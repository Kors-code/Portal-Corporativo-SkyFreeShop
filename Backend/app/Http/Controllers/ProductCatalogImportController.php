<?php

namespace App\Http\Controllers;

use App\Imports\ProductCatalogImport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ProductCatalogImportController extends Controller
{
    private const CHUNK_SIZE = 100;
    private const DIR = 'catalog-imports';

    public function importAutomation(Request $request)
    {
        if (!$this->authorized($request)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        return response()->json([
            'message' => 'La importacion automatica de catalogo debe ejecutarse por bloques para evitar timeouts.',
            'start_endpoint' => '/' . trim($request->path(), '/') . '/start',
            'chunk_endpoint' => '/' . trim($request->path(), '/') . '/chunk',
        ], 422);
    }

    public function startAutomation(Request $request)
    {
        if (!$this->authorized($request)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        try {
            return $this->start($request);
        } catch (ValidationException $error) {
            throw $error;
        } catch (HttpExceptionInterface $error) {
            return $this->automationHttpErrorResponse($error);
        } catch (Throwable $error) {
            return $this->automationErrorResponse($error, 'start');
        }
    }

    public function chunkAutomation(Request $request)
    {
        if (!$this->authorized($request)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        try {
            return $this->chunk($request);
        } catch (ValidationException $error) {
            throw $error;
        } catch (HttpExceptionInterface $error) {
            return $this->automationHttpErrorResponse($error);
        } catch (Throwable $error) {
            return $this->automationErrorResponse($error, 'chunk', [
                'path' => $request->input('path'),
                'next_row' => $request->input('next_row'),
                'chunk_size' => $request->input('chunk_size'),
            ]);
        }
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => $this->spreadsheetFileRules(),
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
            'file' => $this->spreadsheetFileRules(),
        ]);

        $file = $request->file('file');
        $token = uniqid('catalog_', true);
        $extension = $file->getClientOriginalExtension() ?: 'xlsx';
        $path = $file->storeAs(self::DIR, "{$token}.{$extension}");
        $fullPath = Storage::path($path);

        $reader = IOFactory::createReaderForFile($fullPath);
        $reader->setReadDataOnly(true);
        $highestRow = $this->detectHighestRow($reader, $fullPath);
        $reader->setReadFilter(new class implements IReadFilter {
            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row === 1;
            }
        });
        $spreadsheet = $reader->load($fullPath);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow ??= (int) $sheet->getHighestDataRow();
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
            'total_rows' => ['nullable', 'integer', 'min:0'],
            'chunk_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        abort_unless(preg_match('/^' . preg_quote(self::DIR, '/') . '\/catalog_[A-Za-z0-9_.-]+\.(xlsx|xls|csv)$/i', $data['path']), 422, 'Ruta de catalogo invalida.');
        abort_unless(Storage::exists($data['path']), 404, 'Archivo de catalogo no encontrado.');

        $fullPath = $this->safeStoragePath($data['path']);
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
        $highestRow = isset($data['total_rows']) && (int) $data['total_rows'] > 0
            ? (int) $data['total_rows'] + 1
            : (int) $sheet->getHighestDataRow();
        $headers = $this->headersFromRow($sheet->rangeToArray('1:1', null, true, false)[0] ?? []);
        $highestColumn = Coordinate::stringFromColumnIndex(max(1, count($headers)));
        $lastRow = min($endRow, $highestRow);

        $rows = new Collection();
        if ($lastRow >= $startRow) {
            $dataRows = $sheet->rangeToArray("A{$startRow}:{$highestColumn}{$lastRow}", null, true, false);
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
        $headers = array_map(fn ($header) => $this->normalizeHeader((string) $header), $row);

        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }

        return $headers;
    }

    private function detectHighestRow($reader, string $fullPath): ?int
    {
        if (!method_exists($reader, 'listWorksheetInfo')) {
            return null;
        }

        $worksheets = $reader->listWorksheetInfo($fullPath);
        $firstSheet = $worksheets[0] ?? null;

        return isset($firstSheet['totalRows']) ? (int) $firstSheet['totalRows'] : null;
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

    private function safeStoragePath(string $path): string
    {
        $basePath = realpath(Storage::path(self::DIR));
        $filePath = realpath(Storage::path($path));

        abort_unless($basePath && $filePath && str_starts_with($filePath, $basePath . DIRECTORY_SEPARATOR), 422, 'Ruta de catalogo invalida.');

        return $filePath;
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

    private function automationErrorResponse(Throwable $error, string $stage, array $context = [])
    {
        Log::error('CATALOG AUTOMATION IMPORT FAILED', [
            'stage' => $stage,
            'context' => $context,
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString(),
        ]);

        return response()->json([
            'message' => 'Error importando catalogo por automatizacion.',
            'stage' => $stage,
            'error' => $error->getMessage(),
            'context' => $context,
        ], 500);
    }

    private function automationHttpErrorResponse(HttpExceptionInterface $error)
    {
        return response()->json([
            'message' => $error->getMessage() ?: 'Error importando catalogo por automatizacion.',
        ], $error->getStatusCode(), $error->getHeaders());
    }
}
