<?php

namespace App\Services;

use App\Http\Controllers\EntregaController;
use App\Models\Empleado;
use App\Models\Entrega;
use Illuminate\Support\Facades\Log;
use MailerSend\Helpers\Builder\Attachment;
use MailerSend\Helpers\Builder\EmailParams;
use MailerSend\Helpers\Builder\Recipient;
use MailerSend\MailerSend;

class EntregaMailService
{
    protected MailerSend $mailer;
    protected EntregaPdfService $pdfService;

    public function __construct(EntregaPdfService $pdfService)
    {
        $this->pdfService = $pdfService;
        $this->mailer = new MailerSend([
            'api_key' => config('services.mailersend.key', env('MAILERSEND_API_KEY')),
        ]);
    }

    public function notificarLiderReceptor(Entrega $entrega): bool
    {
        $entrega->loadMissing(['liderEntrega', 'liderRecibe', 'novedades']);

        $liderRecibe = $entrega->liderRecibe;
        $emailRecibe = $this->emailEmpleado($liderRecibe);

        if (!$liderRecibe || !$emailRecibe) {
            Log::warning('Lider receptor sin email de portal', ['entrega_id' => $entrega->id]);
            return false;
        }

        $enlace = $this->urlEntrega($entrega);

        $emailParams = (new EmailParams())
            ->setFrom('no-reply@skyfreeshopdutyfree.com')
            ->setFromName('Sky Free Shop - Entregas')
            ->setRecipients([new Recipient($emailRecibe, $liderRecibe->colaborador)])
            ->setSubject("Nueva acta de entrega - {$entrega->codigo_acta}")
            ->setHtml($this->plantillaEntregaPendiente($entrega, $enlace))
            ->setText($this->plantillaTexto($entrega, $enlace))
            ->setReplyTo('no-reply@skyfreeshopdutyfree.com')
            ->setReplyToName('No Reply');

        return $this->enviar($emailParams, $entrega, 'entrega_pendiente');
    }

    public function notificarCierreActa(Entrega $entrega): bool
    {
        $entrega->loadMissing(['liderEntrega', 'liderRecibe', 'novedades']);

        $liderEntrega = $entrega->liderEntrega;
        $emailEntrega = $this->emailEmpleado($liderEntrega);

        if (!$liderEntrega || !$emailEntrega) {
            Log::warning('Lider que entrega sin email de portal', ['entrega_id' => $entrega->id]);
            return false;
        }

        $enlace = $this->urlEntrega($entrega);
        $pdfContent = $this->pdfService->generarRespuesta($entrega)->output();
        $attachment = new Attachment(base64_encode($pdfContent), "acta-{$entrega->codigo_acta}.pdf", 'attachment');

        $emailParams = (new EmailParams())
            ->setFrom('no-reply@skyfreeshopdutyfree.com')
            ->setFromName('Sky Free Shop - Entregas')
            ->setRecipients([new Recipient($emailEntrega, $liderEntrega->colaborador)])
            ->setSubject("Acta recibida y firmada - {$entrega->codigo_acta}")
            ->setHtml($this->plantillaCierre($entrega, $enlace))
            ->setText("Tu acta {$entrega->codigo_acta} fue recibida y firmada por {$entrega->liderRecibe->colaborador}.")
            ->setAttachments([$attachment]);

        return $this->enviar($emailParams, $entrega, 'acta_cerrada');
    }

    public function notificarRechazo(Entrega $entrega): bool
    {
        $entrega->loadMissing(['liderEntrega', 'liderRecibe']);

        $liderEntrega = $entrega->liderEntrega;
        $emailEntrega = $this->emailEmpleado($liderEntrega);

        if (!$liderEntrega || !$emailEntrega) {
            Log::warning('Lider que entrega sin email de portal para rechazo', ['entrega_id' => $entrega->id]);
            return false;
        }

        $enlace = $this->urlEntrega($entrega);

        $html = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; line-height: 1.6;'>
            <h2 style='color: #dc2626;'>Acta rechazada</h2>
            <p>Hola <strong>{$liderEntrega->colaborador}</strong>,</p>
            <p>Tu acta <strong>{$entrega->codigo_acta}</strong> fue rechazada por {$entrega->liderRecibe->colaborador}.</p>
            <div style='background: #fef2f2; padding: 15px; border-left: 4px solid #dc2626;'>
                <strong>Razon:</strong><br>
                {$entrega->razon_rechazo}
            </div>
            <p style='margin-top: 20px;'>
                <a href='{$enlace}' style='background: #dc2626; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Ver acta</a>
            </p>
        </div>";

        $emailParams = (new EmailParams())
            ->setFrom('no-reply@skyfreeshopdutyfree.com')
            ->setFromName('Sky Free Shop - Entregas')
            ->setRecipients([new Recipient($emailEntrega, $liderEntrega->colaborador)])
            ->setSubject("Acta rechazada - {$entrega->codigo_acta}")
            ->setHtml($html);

        return $this->enviar($emailParams, $entrega, 'acta_rechazada');
    }

    private function emailEmpleado(?Empleado $empleado): ?string
    {
        if (!$empleado) {
            return null;
        }

        $usuarioPortal = EntregaController::buscarUsuarioPortalParaEmpleado($empleado);

        return $usuarioPortal->email ?? $empleado->email ?? null;
    }

    private function enviar(EmailParams $params, Entrega $entrega, string $tipo): bool
    {
        try {
            $response = $this->mailer->email->send($params);

            if (isset($response['status_code']) && in_array($response['status_code'], [200, 202], true)) {
                $entrega->update(['correo_enviado' => true]);
                Log::info('Correo de entrega enviado', ['entrega_id' => $entrega->id, 'tipo' => $tipo]);
                return true;
            }

            Log::warning('Correo de entrega no enviado', [
                'entrega_id' => $entrega->id,
                'tipo' => $tipo,
                'response' => $response,
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::error('Error enviando correo de entrega', [
                'entrega_id' => $entrega->id,
                'tipo' => $tipo,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function urlEntrega(Entrega $entrega): string
    {
        $base = rtrim(config('app.frontend_url', env('FRONTEND_URL', config('app.url'))), '/');
        return "{$base}/entregas/{$entrega->id}";
    }

    private function plantillaEntregaPendiente(Entrega $entrega, string $enlace): string
    {
        $liderEntrega = $entrega->liderEntrega->colaborador;
        $liderRecibe = $entrega->liderRecibe->colaborador;
        $totalNovedades = $entrega->novedades()->count();
        $fecha = optional($entrega->fecha_acta)->format('d/m/Y') ?? $entrega->fecha_acta;
        $turno = ucfirst($entrega->turno);

        return "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; line-height: 1.6;'>
            <div style='background: #1e40af; color: white; padding: 28px; text-align: center; border-radius: 10px 10px 0 0;'>
                <h1 style='margin: 0;'>Nueva acta de entrega</h1>
                <p style='margin: 10px 0 0;'>Codigo: <strong>{$entrega->codigo_acta}</strong></p>
            </div>
            <div style='background: #ffffff; padding: 30px; border: 1px solid #e5e7eb;'>
                <p>Hola <strong>{$liderRecibe}</strong>,</p>
                <p><strong>{$liderEntrega}</strong> cerro y firmo un acta de entrega para tu revision.</p>
                <div style='background: #f3f4f6; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                    <p><strong>Fecha:</strong> {$fecha}</p>
                    <p><strong>Turno:</strong> {$turno}</p>
                    <p><strong>Novedades reportadas:</strong> {$totalNovedades}</p>
                </div>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$enlace}' style='background: #1e40af; color: white; padding: 14px 34px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;'>Ver y firmar acta</a>
                </div>
                <p style='color: #6b7280; font-size: 14px;'>Al firmar, el acta quedara cerrada con las novedades pendientes registradas.</p>
            </div>
            <div style='background: #f9fafb; padding: 18px; text-align: center; border-radius: 0 0 10px 10px; color: #6b7280; font-size: 12px;'>
                Sistema de Entregas Sky Free Shop
            </div>
        </div>";
    }

    private function plantillaCierre(Entrega $entrega, string $enlace): string
    {
        $liderEntrega = $entrega->liderEntrega->colaborador;
        $liderRecibe = $entrega->liderRecibe->colaborador;
        $pendientes = $entrega->novedades()->where('resuelto', false)->count();

        return "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; line-height: 1.6;'>
            <div style='background: #047857; color: white; padding: 28px; text-align: center; border-radius: 10px 10px 0 0;'>
                <h1 style='margin: 0;'>Acta recibida y firmada</h1>
                <p style='margin: 10px 0 0;'>{$entrega->codigo_acta}</p>
            </div>
            <div style='background: #ffffff; padding: 30px; border: 1px solid #e5e7eb;'>
                <p>Hola <strong>{$liderEntrega}</strong>,</p>
                <p><strong>{$liderRecibe}</strong> recibio y firmo tu acta de entrega.</p>
                <div style='background: #ecfdf5; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #047857;'>
                    <p style='margin: 0;'><strong>Pendientes al cierre:</strong> {$pendientes}</p>
                    <p style='margin: 8px 0 0;'>El PDF firmado va adjunto a este correo.</p>
                </div>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$enlace}' style='background: #047857; color: white; padding: 14px 34px; text-decoration: none; border-radius: 8px; font-weight: bold;'>Ver acta completa</a>
                </div>
            </div>
            <div style='background: #f9fafb; padding: 18px; text-align: center; border-radius: 0 0 10px 10px; color: #6b7280; font-size: 12px;'>
                Sistema de Entregas Sky Free Shop
            </div>
        </div>";
    }

    private function plantillaTexto(Entrega $entrega, string $enlace): string
    {
        return "Nueva acta de entrega {$entrega->codigo_acta} de {$entrega->liderEntrega->colaborador}. "
             . "Revisar y firmar en: {$enlace}";
    }
}
