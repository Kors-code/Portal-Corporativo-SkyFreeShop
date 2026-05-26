<?php

namespace App\Services;

use App\Models\Entrega;
use App\Models\Novedad;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class EntregaPdfService
{
    /**
     * Generar PDF y guardarlo, retorna la ruta
     */
    public function generar(Entrega $entrega): string
    {
        $pdf = $this->generarRespuesta($entrega);

        $filename = "actas/acta-{$entrega->codigo_acta}.pdf";
        Storage::disk('public')->put($filename, $pdf->output());

        return $filename;
    }

    /**
     * Generar instancia de PDF para responder al cliente
     */
    public function generarRespuesta(Entrega $entrega)
    {
        $data = [
            'entrega' => $entrega,
            'categorias' => Novedad::$categorias,
            'prioridades' => Novedad::$prioridades,
        ];

        return Pdf::loadView('pdf_entrega', $data)
                  ->setPaper('a4', 'portrait')
                  ->setOptions([
                      'isHtml5ParserEnabled' => true,
                      'isRemoteEnabled' => true,
                      'defaultFont' => 'sans-serif',
                  ]);
    }
}
