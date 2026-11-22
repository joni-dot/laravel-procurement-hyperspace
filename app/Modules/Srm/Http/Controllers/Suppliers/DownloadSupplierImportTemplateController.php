<?php

namespace App\Modules\Srm\Http\Controllers\Suppliers;

use App\Http\Controllers\Controller;
use App\Modules\Srm\Suppliers\Import\ImportColumn;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * An empty import file with just the header row, as CSV or Excel. It has no example row, so importing
 * the template unchanged adds nothing.
 */
class DownloadSupplierImportTemplateController extends Controller
{
    private const string FILE_NAME = 'suppliers-import-template';

    public function __invoke(Request $request): Response
    {
        return $request->query('format') === 'xlsx' ? $this->excel() : $this->csv();
    }

    private function csv(): Response
    {
        $header = implode(',', ImportColumn::headers())."\r\n";

        return response($header, headers: [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.self::FILE_NAME.'.csv"',
        ]);
    }

    private function excel(): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'supplier-template');

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(ImportColumn::headers()));
        $writer->close();

        return response()->download($path, self::FILE_NAME.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
