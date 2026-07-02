<?php

namespace App\Http\Controllers;

use App\Imports\ProductCatalogImport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class ProductCatalogImportController extends Controller
{
    private const CHUNK_SIZE = 500;
    private const DIR = 'catalog-imports';

    public function importAutomation(Request $request)
    {
        if (!$this->authorized($request)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $request->validate([
            'file' => $this->spreadsheetFileRules(),
        ]);

        $import = new ProductCatalogImport();
        Excel::import($import, $request->file('file'));

        return response()->json([
            'message' => 'Catalogo importado correctamente por automatizacion.',
            'filename' => $request->file('file')->getClientOriginalName(),
            'summary' => $import->summary(),
        ]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        $import = new ProductCatalogImport();
        Excel::import($import, $request->file('file'));

        return response()->json([
            'message' => 'Catalogo importado correctamente.',
            'summary' => $import->summary(),
        ]);
    }

    public function start(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        $file = $request->file('file');
        $token = uniqid('catalog_', true);
        $extension = $file->getClientOriginalExtension() ?: 'xlsx';
        $path = $file->storeAs(self::DIR, "{$token}.{$extension}");
        $fullPath = Storage::path($path);

        $reader = IOFactory::createReaderForFile($fullPath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($fullPath);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = (int) $sheet->getHighestDataRow();
        $headers = $this->headersFromRow($sheet->rangeToArray('1:1', null, true, false)[0] ?? []);
        $spreadsheet->disconnectWorksheets();

        return response()->json([
            'token' => $token,
            'path' => $path,
            'total_rows' => max(0, $highestRow - 1),
            'next_row' => 2,
            'chunk_size' => self::CHUNK_SIZE,
            'headers' => $headers,
        ]);
    }

    public function chunk(Request $request)
    {
        $data = $request->validate([
            'path' => ['required', 'string'],
            'next_row' => ['required', 'integer', 'min:2'],
            'chunk_size' => ['nullable', 'integer', 'min:50', 'max:1000'],
        ]);

        abort_unless(str_starts_with($data['path'], self::DIR . '/'), 422, 'Ruta de catalogo invalida.');
        abort_unless(Storage::exists($data['path']), 404, 'Archivo de catalogo no encontrado.');

        $fullPath = Storage::path($data['path']);
        $startRow = (int) $data['next_row'];
        $chunkSize = (int) ($data['chunk_size'] ?? self::CHUNK_SIZE);
        $endRow = $startRow + $chunkSize - 1;

        $reader = IOFactory::createReaderForFile($fullPath);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class($startRow, $endRow) implements IReadFilter {
            public function __construct(private int $startRow, private int $endRow) {}

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row === 1 || ($row >= $this->startRow && $row <= $this->endRow);
            }
        });

        $spreadsheet = $reader->load($fullPath);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = (int) $sheet->getHighestDataRow();
        $headers = $this->headersFromRow($sheet->rangeToArray('1:1', null, true, false)[0] ?? []);
        $lastRow = min($endRow, $highestRow);

        $rows = new Collection();
        if ($lastRow >= $startRow) {
            $dataRows = $sheet->rangeToArray("A{$startRow}:{$sheet->getHighestDataColumn()}{$lastRow}", null, true, false);
            foreach ($dataRows as $row) {
                $assoc = [];
                foreach ($headers as $index => $header) {
                    if ($header !== '') {
                        $assoc[$header] = $row[$index] ?? null;
                    }
                }
                $rows->push(collect($assoc));
            }
        }
        $spreadsheet->disconnectWorksheets();

        $import = new ProductCatalogImport();
        $import->collection($rows);

        $nextRow = $lastRow + 1;
        $done = $nextRow > $highestRow;
        if ($done) {
            Storage::delete($data['path']);
        }

        return response()->json([
            'done' => $done,
            'next_row' => $nextRow,
            'processed_rows' => max(0, $lastRow - $startRow + 1),
            'total_rows' => max(0, $highestRow - 1),
            'summary' => $import->summary(),
        ]);
    }

    private function headersFromRow(array $row): array
    {
        return array_map(fn ($header) => $this->normalizeHeader((string) $header), $row);
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\x{FEFF}/u', '', $header);
        $header = mb_strtolower(trim($header));
        $header = preg_replace('/\s+/', ' ', $header);
        $header = preg_replace('/[^\p{L}\p{N}]+/u', '_', $header);
        $header = preg_replace('/_+/', '_', $header);

        return trim($header, '_');
    }

    private function authorized(Request $request): bool
    {
        $token = (string) env('IMPORT_AUTOMATION_TOKEN');

        return $token !== '' && hash_equals($token, (string) $request->header('X-Automation-Token'));
    }

    private function spreadsheetFileRules(): array
    {
        return [
            'required',
            'file',
            function (string $attribute, $value, \Closure $fail): void {
                $extension = strtolower($value->getClientOriginalExtension() ?: '');

                if (!in_array($extension, ['xlsx', 'xls', 'xlsm', 'csv'], true)) {
                    $fail('El archivo debe ser de tipo: xlsx, xls, xlsm o csv.');
                }
            },
        ];
    }
}
