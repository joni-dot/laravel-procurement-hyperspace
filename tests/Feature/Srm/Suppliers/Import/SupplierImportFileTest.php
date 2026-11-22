<?php

namespace Tests\Feature\Srm\Suppliers\Import;

use App\Models\User;
use App\Modules\Srm\Models\Supplier;
use App\Modules\Srm\Suppliers\Import\ImportColumn;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class SupplierImportFileTest extends TestCase
{
    use ImportsSupplierFiles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->buyer()->create());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function separators(): array
    {
        return ['comma' => [','], 'semicolon' => [';'], 'tab' => ["\t"]];
    }

    #[DataProvider('separators')]
    public function test_csv_files_with_any_common_separator_are_read(string $separator)
    {
        $this->import($this->csvFile([
            ['Name', 'Country', 'City', 'Notes'],
            ['Nordic Steel Oy', 'FI', 'Helsinki', 'Pays in 30 days, sometimes 14; usually on time'],
        ], $separator))->assertSessionHasNoErrors();

        $supplier = Supplier::sole();
        $this->assertSame('Nordic Steel Oy', $supplier->name);
        $this->assertSame('Helsinki', $supplier->city);
        $this->assertSame('Pays in 30 days, sometimes 14; usually on time', $supplier->notes);
    }

    public function test_utf8_with_a_byte_order_mark_is_read()
    {
        $file = $this->uploadedFile('suppliers.csv', "\xEF\xBB\xBFName;Country\r\nÄänekosken Sähkö Oy;FI\r\n");

        $this->import($file)->assertSessionHasNoErrors();

        $this->assertSame('Äänekosken Sähkö Oy', Supplier::sole()->name);
    }

    public function test_windows_1252_from_excel_on_windows_is_read()
    {
        $content = mb_convert_encoding("Name;Country;Notes\r\nÄänekosken Sähkö Oy;FI;Price 5 €\r\n", 'Windows-1252', 'UTF-8');
        $this->assertFalse(mb_check_encoding($content, 'UTF-8'));

        $this->import($this->uploadedFile('suppliers.csv', $content))->assertSessionHasNoErrors();

        $supplier = Supplier::sole();
        $this->assertSame('Äänekosken Sähkö Oy', $supplier->name);
        $this->assertSame('Price 5 €', $supplier->notes);
    }

    public function test_txt_files_are_read_as_csv()
    {
        $this->import($this->csvFile([['Name', 'Country'], ['Nordic Steel Oy', 'FI']], "\t", 'suppliers.txt'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Supplier::count());
    }

    public function test_excel_workbooks_are_read_from_the_first_sheet_with_the_row_numbers_people_see()
    {
        $path = tempnam(sys_get_temp_dir(), 'import-test').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Name', 'Country', 'Postal code', 'Phone', 'Supplier number']));
        $writer->addRow(Row::fromValues(['Nordic Steel Oy', 'FI', '00510', 358401234567, 1001]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Broken', 'Atlantis', '', '', '']));
        $writer->addNewSheetAndMakeItCurrent();
        $writer->addRow(Row::fromValues(['Name', 'Country']));
        $writer->addRow(Row::fromValues(['From the second sheet', 'FI']));
        $writer->close();
        $file = new UploadedFile($path, 'suppliers.xlsx', null, null, true);

        $preview = $this->preview($file);
        $this->assertSame(['add' => 1, 'skipped' => 0, 'errors' => 1], $preview['counts']);
        $this->assertSame(['4: Country: The selected country is invalid.'], $this->problems($preview, 'error'));

        $this->import($file)->assertSessionHasNoErrors();

        $supplier = Supplier::sole();
        $this->assertSame('Nordic Steel Oy', $supplier->name);
        $this->assertSame('00510', $supplier->postal_code);
        $this->assertSame('358401234567', $supplier->phone);
        $this->assertSame('1001', $supplier->supplier_number);
    }

    public function test_headers_match_whatever_their_case_and_spaces_and_unknown_columns_are_listed()
    {
        $preview = $this->preview($this->csvFile([
            ['  NAME ', 'country', 'Shoe size', '', 'Name'],
            ['Nordic Steel Oy', 'FI', '42', 'x', 'Second name column'],
        ]));

        $this->assertSame(1, $preview['counts']['add']);
        $this->assertSame('Nordic Steel Oy', $preview['suppliers'][0]['name']);
        $this->assertSame(['Shoe size', 'Name'], $preview['ignoredColumns']);
    }

    public function test_a_file_without_a_required_column_is_refused_naming_it()
    {
        $this->post(route('suppliers.import.preview'), ['file' => $this->csvFile([['Name', 'City'], ['Nordic Steel Oy', 'Helsinki']])])
            ->assertSessionHasErrors(['file' => 'The file has no "Country" column.']);

        $this->post(route('suppliers.import.preview'), ['file' => $this->csvFile([['City'], ['Helsinki']])])
            ->assertSessionHasErrors(['file' => 'The file has no "Name", "Country" columns.']);
    }

    public function test_a_file_of_more_than_5000_suppliers_is_refused()
    {
        $rows = [['Name', 'Country']];

        for ($i = 0; $i <= 5000; $i++) {
            $rows[] = ["Supplier {$i}", 'XX'];
        }

        $this->post(route('suppliers.import.preview'), ['file' => $this->csvFile($rows)])
            ->assertSessionHasErrors(['file' => 'The file has more than 5,000 suppliers. Split it into smaller files.']);
        $this->import($this->csvFile($rows))->assertSessionHasErrors('file');
        $this->assertSame(0, Supplier::count());
    }

    public function test_a_file_over_5_mb_is_refused()
    {
        $file = UploadedFile::fake()->create('suppliers.csv', 5 * 1024 + 1, 'text/csv');

        $this->post(route('suppliers.import.preview'), ['file' => $file])
            ->assertSessionHasErrors(['file' => 'The file may be at most 5 MB.']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function wrongFiles(): array
    {
        $zip = "PK\x03\x04".str_repeat("\x00", 100);

        return [
            'a PDF' => ['suppliers.pdf', "%PDF-1.4\n", 'The file must be a CSV file or an Excel workbook (.xlsx).'],
            'an old Excel file' => ['suppliers.xls', "\xD0\xCF\x11\xE0", 'The file must be a CSV file or an Excel workbook (.xlsx).'],
            'text named .xlsx' => ['suppliers.xlsx', "Name,Country\nNordic,FI\n", 'The file must be a CSV file or an Excel workbook (.xlsx).'],
            'a zip named .csv' => ['suppliers.csv', $zip, 'The file must be a CSV file or an Excel workbook (.xlsx).'],
            'a broken workbook' => ['suppliers.xlsx', $zip, 'The file could not be read. Save it again as CSV or Excel (.xlsx) and try again.'],
            'an empty file' => ['suppliers.csv', '', 'The file is empty.'],
            'only empty lines' => ['suppliers.csv', "\r\n\r\n;;\r\n", 'The file is empty.'],
        ];
    }

    #[DataProvider('wrongFiles')]
    public function test_files_that_are_not_supplier_lists_are_refused(string $name, string $content, string $message)
    {
        $this->post(route('suppliers.import.preview'), ['file' => $this->uploadedFile($name, $content)])
            ->assertSessionHasErrors(['file' => $message]);
    }

    public function test_the_templates_hold_only_the_header_row_and_import_nothing()
    {
        $csv = $this->get(route('suppliers.import.template'))
            ->assertOk()
            ->assertDownload('suppliers-import-template.csv')
            ->getContent();
        $this->assertSame(implode(',', ImportColumn::headers())."\r\n", $csv);

        $excel = $this->get(route('suppliers.import.template', ['format' => 'xlsx']))
            ->assertOk()
            ->assertDownload('suppliers-import-template.xlsx');
        $excelPath = $excel->baseResponse->getFile()->getPathname();
        $excelCopy = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
        copy($excelPath, $excelCopy);

        foreach ([
            $this->uploadedFile('template.csv', $csv),
            new UploadedFile($excelCopy, 'template.xlsx', null, null, true),
        ] as $template) {
            $preview = $this->preview($template);
            $this->assertSame(['add' => 0, 'skipped' => 0, 'errors' => 0], $preview['counts']);
            $this->assertSame([], $preview['problems']);
            $this->assertSame([], $preview['ignoredColumns']);

            $this->import($template)->assertSessionHasErrors(['file' => 'No row of the file can be added.']);
        }

        $this->assertSame(0, Supplier::count());
    }

    public function test_a_workbook_that_unpacks_to_too_much_is_refused_before_it_is_read()
    {
        $path = tempnam(sys_get_temp_dir(), 'import-test').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', str_repeat(' ', 60 * 1024 * 1024));
        $zip->close();
        $this->assertLessThan(5 * 1024 * 1024, filesize($path));

        $this->post(route('suppliers.import.preview'), ['file' => new UploadedFile($path, 'bomb.xlsx', null, null, true)])
            ->assertSessionHasErrors(['file' => 'The file could not be read. Save it again as CSV or Excel (.xlsx) and try again.']);
    }

    public function test_a_workbook_cannot_make_the_server_read_other_files()
    {
        $file = $this->xlsxFile([['Name', 'Country'], ['SECRET_PLACEHOLDER', 'FI']]);
        $this->rewriteSheet($file->getPathname(), fn (string $xml) => str_replace(
            ['<?xml version="1.0" encoding="UTF-8"?>', 'SECRET_PLACEHOLDER'],
            ['<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE worksheet [<!ENTITY secret SYSTEM "file:///etc/passwd">]>', '&secret;'],
            $xml,
        ));

        $response = $this->post(route('suppliers.import.preview'), ['file' => $file]);

        $this->assertStringNotContainsString('root:', json_encode(session()->all()) ?: '');
        $this->assertStringNotContainsString('root:', (string) $response->getContent());
        $this->assertSame(0, Supplier::count());
    }

    public function test_a_workbook_whose_rows_run_far_past_the_suppliers_is_refused()
    {
        $file = $this->xlsxFile([['Name', 'Country'], ['Nordic Steel Oy', 'FI']]);
        $this->rewriteSheet($file->getPathname(), fn (string $xml) => str_replace(['r="2"', 'A2', 'B2'], ['r="1048577"', 'A1048577', 'B1048577'], $xml));

        $this->post(route('suppliers.import.preview'), ['file' => $file])
            ->assertSessionHasErrors(['file' => 'The file has more than 20,000 rows. Remove the empty rows or split it into smaller files.']);
    }

    public function test_a_csv_file_of_empty_lines_is_refused_without_reading_them_all()
    {
        $started = microtime(true);

        $this->post(route('suppliers.import.preview'), ['file' => $this->uploadedFile('blank.csv', "Name,Country\r\n".str_repeat("\r\n", 2_000_000))])
            ->assertSessionHasErrors(['file' => 'The file has more than 20,000 rows. Remove the empty rows or split it into smaller files.']);
        $this->assertLessThan(2, microtime(true) - $started);
    }

    public function test_a_workbook_claiming_thousands_of_columns_is_refused_at_its_first_row()
    {
        $file = $this->xlsxFile([['Name', 'Country'], ['Nordic Steel Oy', 'FI']]);
        $this->rewriteSheet($file->getPathname(), fn (string $xml) => preg_replace(
            ['/<dimension[^>]*>/', '/<sheetData>/', '/<\/sheetData>/'],
            ['', '<dimension ref="A1:ZZZ1"/><sheetData>', str_repeat('<row></row>', 3_000).'</sheetData>'],
            $xml,
        ) ?? '');
        $started = microtime(true);

        $this->post(route('suppliers.import.preview'), ['file' => $file])
            ->assertSessionHasErrors(['file' => 'The file has more than 200 columns. Keep only the columns of the template.']);
        $this->assertLessThan(2, microtime(true) - $started);
    }

    public function test_a_row_claiming_to_span_millions_of_columns_is_refused_before_it_is_read()
    {
        $file = $this->xlsxFile([['Name', 'Country'], ['Nordic Steel Oy', 'FI']]);
        $this->rewriteSheet($file->getPathname(), fn (string $xml) => preg_replace(
            ['/<dimension[^>]*>/', '/<row r="2"[^>]*>/'],
            ['', '<row r="2" spans="1:5000000">'],
            $xml,
        ) ?? '');
        $memoryBefore = memory_get_peak_usage();

        $this->post(route('suppliers.import.preview'), ['file' => $file])
            ->assertSessionHasErrors(['file' => 'The file has more than 200 columns. Keep only the columns of the template.']);
        $this->assertLessThan(50 * 1024 * 1024, memory_get_peak_usage() - $memoryBefore);
    }

    public function test_a_workbook_that_understates_its_unpacked_size_is_still_refused()
    {
        // A readable sheet, padded with 60 MB of comments that compress to almost nothing.
        $file = $this->xlsxFile([['Name', 'Country'], ['Nordic Steel Oy', 'FI']]);
        $padding = str_repeat('<!--'.str_repeat(' ', 1000).'-->', 60 * 1024);
        $this->rewriteSheet($file->getPathname(), fn (string $xml) => str_replace('</sheetData>', $padding.'</sheetData>', $xml));
        $this->claimUnpackedSize($file->getPathname(), 'xl/worksheets/sheet1.xml', 1000);

        $this->post(route('suppliers.import.preview'), ['file' => $file])
            ->assertSessionHasErrors(['file' => 'The file could not be read. Save it again as CSV or Excel (.xlsx) and try again.']);
    }

    /**
     * Rewrites what the zip's local and central headers say the part unpacks to, as an attacker can.
     */
    private function claimUnpackedSize(string $path, string $part, int $size): void
    {
        $bytes = (string) file_get_contents($path);
        $patched = 0;

        // [signature, offset of the name length, offset of the name, offset of the unpacked size]
        foreach ([["PK\x03\x04", 26, 30, 22], ["PK\x01\x02", 28, 46, 24]] as [$signature, $nameLengthAt, $nameAt, $sizeAt]) {
            for ($at = strpos($bytes, $signature); $at !== false; $at = strpos($bytes, $signature, $at + 4)) {
                $nameLength = unpack('v', substr($bytes, $at + $nameLengthAt, 2))[1];

                if (substr($bytes, $at + $nameAt, $nameLength) === $part) {
                    $bytes = substr_replace($bytes, pack('V', $size), $at + $sizeAt, 4);
                    $patched++;
                }
            }
        }

        $this->assertSame(2, $patched);
        file_put_contents($path, $bytes);
    }

    /**
     * @param  callable(string): string  $change
     */
    private function rewriteSheet(string $path, callable $change): void
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $changed = $change($sheet);
        $this->assertNotSame($sheet, $changed, 'The test must change the sheet.');
        $zip->addFromString('xl/worksheets/sheet1.xml', $changed);
        $zip->close();
    }
}
