<?php

namespace Tests\Feature\Srm\Suppliers\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Builds import files in the test and sends them to the preview and the import.
 */
trait ImportsSupplierFiles
{
    /**
     * @param  list<list<string|int|float|null>>  $rows  the header row first
     */
    protected function csvFile(array $rows, string $separator = ',', string $name = 'suppliers.csv'): UploadedFile
    {
        $lines = array_map(fn (array $row) => implode($separator, array_map($this->csvCell(...), $row)), $rows);

        return $this->uploadedFile($name, implode("\r\n", $lines)."\r\n");
    }

    /**
     * An upload whose content type is read from its content, like a real one (fake uploads take it from the name).
     */
    protected function uploadedFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import-test');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * @param  list<list<string|int|float|null>>  $rows  the header row first; null rows are left empty
     */
    protected function xlsxFile(array $rows, string $name = 'suppliers.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import-test').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function preview(UploadedFile $file): array
    {
        $this->post(route('suppliers.import.preview'), ['file' => $file])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('suppliers.import.create'));

        return session(SessionKey::FLASH_DATA)['supplierImportPreview'];
    }

    protected function import(UploadedFile $file): TestResponse
    {
        return $this->post(route('suppliers.import.store'), ['file' => $file]);
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return list<string> "row: column: message" for each problem of the kind
     */
    protected function problems(array $preview, string $kind): array
    {
        return array_values(array_map(
            fn (array $problem) => "{$problem['row']}: {$problem['column']}: {$problem['message']}",
            array_filter($preview['problems'], fn (array $problem) => $problem['kind'] === $kind),
        ));
    }

    private function csvCell(string|int|float|null $value): string
    {
        $value = (string) $value;

        return preg_match('/[",;\t\r\n]/', $value) ? '"'.str_replace('"', '""', $value).'"' : $value;
    }
}
