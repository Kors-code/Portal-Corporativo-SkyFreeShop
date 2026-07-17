<?php

namespace App\Http\Controllers;

use App\Services\Davibank\DavibankConverterService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class PublicDavibankConverterController extends Controller
{
    public function convert(Request $request, DavibankConverterService $converter): BinaryFileResponse|Response
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt'],
            'receipt_start' => ['required', 'integer', 'min:1', 'max:999999999'],
        ]);

        try {
            $result = $converter->convert(
                $request->file('file'),
                (int) $validated['receipt_start']
            );

            return response()
                ->download($result['path'], $result['filename'], [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'X-Davibank-Sheets' => (string) $result['sheets'],
                    'X-Davibank-Rows' => (string) $result['rows'],
                    'X-Davibank-Excluded-Zero-Commission' => (string) $result['excluded_zero_commission'],
                ])
                ->deleteFileAfterSend(true);
        } catch (RuntimeException $e) {
            return response([
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response([
                'message' => 'No se pudo convertir el archivo. Revisa el formato del CSV e intenta de nuevo.',
            ], 500);
        }
    }
}
