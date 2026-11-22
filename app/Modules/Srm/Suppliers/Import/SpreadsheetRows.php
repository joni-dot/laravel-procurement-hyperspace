<?php

namespace App\Modules\Srm\Suppliers\Import;

use DateTimeInterface;
use ErrorException;
use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\TextRunCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use ValueError;
use ZipArchive;

/**
 * Reads the rows of an uploaded CSV or Excel file, streaming, so a big file never sits in memory whole.
 * Row numbers are those people see in the file; empty rows are left out. Whatever the file claims about
 * itself (its size, its rows and columns) is bounded before it can cost much time or memory.
 */
final class SpreadsheetRows
{
    /**
     * The last row number read, empty rows included. Empty rows cost time too (a 5 MB CSV holds millions
     * of empty lines), and 5,000 suppliers with blank lines between them fit well within this.
     */
    public const int LAST_ROW = 20_000;

    /** Far more than the import's columns. Every row costs time for every column the file claims. */
    public const int MAX_COLUMNS = 200;

    /**
     * An .xlsx is a zip file; this bounds what it may unpack to, so a small upload can't unpack to
     * gigabytes (a "zip bomb"). 5,000 suppliers fit in a few megabytes.
     */
    private const int MAX_UNPACKED_BYTES = 50 * 1024 * 1024;

    /** A real workbook has a few dozen parts. */
    private const int MAX_ZIP_ENTRIES = 1_000;

    /** Longer than any field allows, so a longer cell still fails its length check, but memory stays bounded. */
    private const int MAX_CELL_LENGTH = 5_001;

    private const array CSV_TYPES = ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'];

    private const array XLSX_TYPES = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'];

    /**
     * @return Generator<int, array<int, string>> the row number in the file => the row's cells by position, trimmed
     *
     * @throws ImportFileRefused
     */
    public function read(UploadedFile $file): Generator
    {
        $reader = $this->openReader($file);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                    if ($rowNumber > self::LAST_ROW) {
                        throw new ImportFileRefused(__('The file has more than :max rows. Remove the empty rows or split it into smaller files.', ['max' => number_format(self::LAST_ROW)]));
                    }

                    if (count($row->cells) > self::MAX_COLUMNS) {
                        throw new ImportFileRefused(__('The file has more than :max columns. Keep only the columns of the template.', ['max' => self::MAX_COLUMNS]));
                    }

                    $cells = $this->cells($row);

                    if (implode('', $cells) !== '') {
                        yield $rowNumber => $cells;
                    }
                }

                break; // The first sheet only.
            }
        } catch (OpenSpoutException|ErrorException|ValueError) {
            throw self::unreadable();
        } finally {
            $reader->close();
        }
    }

    private static function unreadable(): ImportFileRefused
    {
        return new ImportFileRefused(__('The file could not be read. Save it again as CSV or Excel (.xlsx) and try again.'));
    }

    private function openReader(UploadedFile $file): CsvReader|XlsxReader
    {
        if ($file->getSize() === 0) {
            throw new ImportFileRefused(__('The file is empty.'));
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $type = $file->getMimeType();
        $path = $file->getRealPath() ?: throw self::unreadable();

        $reader = match (true) {
            in_array($extension, ['csv', 'txt'], true) && in_array($type, self::CSV_TYPES, true) => $this->csvReader($path),
            $extension === 'xlsx' && in_array($type, self::XLSX_TYPES, true) => $this->xlsxReader($path),
            default => throw new ImportFileRefused(__('The file must be a CSV file or an Excel workbook (.xlsx).')),
        };

        try {
            $reader->open($path);
        } catch (OpenSpoutException) {
            throw self::unreadable();
        }

        return $reader;
    }

    private function csvReader(string $path): CsvReader
    {
        // At most 5 MB, checked before reading.
        $bytes = (string) file_get_contents($path);

        return new CsvReader(new CsvOptions(
            SHOULD_PRESERVE_EMPTY_ROWS: true,
            FIELD_DELIMITER: $this->separator($bytes),
            // Excel on Windows saves "CSV" in the Windows code page rather than UTF-8.
            ENCODING: mb_check_encoding($bytes, 'UTF-8') ? 'UTF-8' : 'Windows-1252',
        ));
    }

    /**
     * The separator is whichever of comma, semicolon and tab the header line has most of: Excel uses a
     * semicolon where the decimal mark is a comma, and tab when saving "Text".
     */
    private function separator(string $bytes): string
    {
        $headerLine = strtok(ltrim(substr($bytes, 0, 64 * 1024), "\xEF\xBB\xBF\r\n"), "\r\n") ?: '';
        $counts = [',' => substr_count($headerLine, ','), ';' => substr_count($headerLine, ';'), "\t" => substr_count($headerLine, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function xlsxReader(string $path): XlsxReader
    {
        $this->refuseZipBomb($path);

        return new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true));
    }

    /**
     * Unpacks every part of the workbook once, counting the bytes that really come out: the sizes a zip
     * file declares are written by whoever made it, and aren't enforced when the parts are read.
     * Also refuses rows that claim to span more columns than allowed, because the reader sets aside
     * room for every claimed column before any row reaches this class.
     */
    private function refuseZipBomb(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw self::unreadable();
        }

        try {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
                throw self::unreadable();
            }

            $unpackedBytes = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $unpackedBytes = $this->unpackCounting($zip, $index, $unpackedBytes);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @return int the bytes unpacked so far, this part's included
     */
    private function unpackCounting(ZipArchive $zip, int $index, int $unpackedBytes): int
    {
        $name = (string) $zip->getNameIndex($index);
        $stream = $zip->getStreamIndex($index) ?: throw self::unreadable();
        $isSheet = str_starts_with($name, 'xl/worksheets/');
        // The end of the previous piece, so an attribute split between two pieces is still seen whole.
        $carry = '';

        try {
            while (! feof($stream)) {
                $piece = fread($stream, 64 * 1024);

                if ($piece === false) {
                    throw self::unreadable();
                }

                $unpackedBytes += strlen($piece);

                if ($unpackedBytes > self::MAX_UNPACKED_BYTES) {
                    throw self::unreadable();
                }

                if ($isSheet) {
                    $this->refuseWideSpans($carry.$piece);
                    $carry = substr($piece, -100);
                }
            }
        } finally {
            fclose($stream);
        }

        return $unpackedBytes;
    }

    /**
     * A row's spans attribute ("1:22") tells the reader how many columns to set aside for it. Anything
     * but plain small numbers is refused, entities included, as the reader decodes those.
     */
    private function refuseWideSpans(string $xml): void
    {
        preg_match_all('/\bspans\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $xml, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $spans = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');

            if (preg_match('/^\s*(\d{1,6})\s*:\s*(\d{1,6})\s*$/D', $spans, $columns) !== 1 || (int) $columns[2] > self::MAX_COLUMNS) {
                throw new ImportFileRefused(__('The file has more than :max columns. Keep only the columns of the template.', ['max' => self::MAX_COLUMNS]));
            }
        }
    }

    /**
     * @return array<int, string> position in the row => text; an Excel row may skip empty cells
     */
    private function cells(Row $row): array
    {
        return array_map(fn (Cell $cell) => $this->text($cell), $row->cells);
    }

    private function text(Cell $cell): string
    {
        $value = match (true) {
            $cell instanceof FormulaCell => $cell->getComputedValue(),
            $cell instanceof TextRunCell => $cell->getStringValue(),
            default => $cell->getValue(),
        };

        $text = match (true) {
            is_string($value) => $value,
            // Whole numbers without ".0", so phone numbers and IDs typed as numbers read as typed.
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => sprintf('%.0f', $value),
            is_int($value), is_float($value) => (string) $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            default => '',
        };

        // Trimmed like the form's input (Laravel's TrimStrings).
        return Str::trim(mb_substr($text, 0, self::MAX_CELL_LENGTH));
    }
}
