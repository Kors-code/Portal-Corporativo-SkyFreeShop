<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vacante;
use App\Services\CandidateCvImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CandidateCvAutomationController extends Controller
{
    public function store(Request $request, CandidateCvImportService $importer): JsonResponse
    {
        $data = $request->validate([
            'vacante_slug' => ['required', 'string', 'exists:vacantes,slug'],
            'cv' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:8192'],
            'sender_email' => ['nullable', 'email', 'max:150'],
            'sender_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email_subject' => ['nullable', 'string', 'max:500'],
            'gmail_message_id' => ['nullable', 'string', 'max:255'],
        ]);

        $vacante = Vacante::where('slug', $data['vacante_slug'])->firstOrFail();
        $candidato = $importer->importUploadedFile($request->file('cv'), $vacante, [
            'name' => $data['sender_name'] ?? null,
            'email' => $data['sender_email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'autorizacion' => true,
            'subject' => $data['email_subject'] ?? null,
            'message_id' => $data['gmail_message_id'] ?? null,
            'source_channel' => 'gmail',
            'routing_method' => 'rule',
        ]);

        return response()->json([
            'ok' => true,
            'candidato_id' => $candidato->id,
            'vacante_id' => $vacante->id,
            'estado' => $candidato->estado,
            'puntaje' => $candidato->puntaje,
        ], 201);
    }

    public function autoStore(Request $request, CandidateCvImportService $importer): JsonResponse
    {
        $data = $request->validate([
            'cv' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:8192'],
            'sender_email' => ['nullable', 'email', 'max:150'],
            'sender_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email_subject' => ['nullable', 'string', 'max:500'],
            'gmail_message_id' => ['nullable', 'string', 'max:255'],
        ]);

        $candidato = $importer->importUploadedFileWithAiRouting($request->file('cv'), [
            'name' => $data['sender_name'] ?? null,
            'email' => $data['sender_email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'subject' => $data['email_subject'] ?? null,
            'message_id' => $data['gmail_message_id'] ?? null,
            'source_channel' => 'gmail',
            'autorizacion' => true,
        ]);

        return response()->json([
            'ok' => true,
            'candidato_id' => $candidato->id,
            'vacante_id' => $candidato->vacante_id,
            'estado' => $candidato->estado,
            'puntaje' => $candidato->puntaje,
            'routing_method' => $candidato->routing_method,
        ], 201);
    }
}
