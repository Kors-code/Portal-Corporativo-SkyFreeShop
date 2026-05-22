<?php

namespace App\Services;

use App\Models\Entrega;
use Illuminate\Support\Facades\Log;
use MailerSend\Helpers\Builder\EmailParams;
use MailerSend\Helpers\Builder\Recipient;
use MailerSend\Helpers\Builder\Attachment;
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

    /**
     * Notificar al líder receptor que tiene una entrega pendiente
     */
    public function notificarLiderReceptor(Entrega $entrega): bool
    {
        $liderRecibe = $entrega->liderRecibe;
        $liderEntrega = $entrega->liderEntrega;

        if (!$liderRecibe || !$liderRecibe->email) {
            Log::warning('Líder receptor sin email', ['entrega_id' => $entrega->id]);
            return false;
        }

        $enlace = $this->urlEntrega($entrega);

        $recipients = [new Recipient($liderRecibe->email, $liderRecibe->colaborador)];

        $html = $this->plantillaEntregaPendiente($entrega, $enlace);

        $emailParams = (new EmailParams())
            ->setFrom('no-reply@skyfreeshopdutyfree.com')
            ->setFromName('Sky Free Shop - Entregas')
            ->setRecipients($recipients)
            ->setSubject("📋 Nueva acta de entrega - {$entrega->codigo_acta}")
            ->setHtml($html)
            ->setText($this->plantillaTexto($entrega, $enlace))
            ->setReplyTo('no-reply@skyfreeshopdutyfree.com')
            ->setReplyToName('No Reply');

        return $this->enviar($emailParams, $entrega);
    }

    /**
     * Notificar al líder que entregó que ya fue recibida y firmada (con PDF)
     */
    public function notificarCierreActa(Entrega $entrega): bool
    {
        $liderEntrega = $entrega->liderEntrega;

        if (!$liderEntrega || !$liderEntrega->email) {
            return false;
        }

        $enlace = $this->urlEntrega($entrega);

        $recipients = [new Recipient($liderEntrega->email, $liderEntrega->colaborador)];

        // Generar PDF para adjuntar
        $pdfContent = $this->pdfService->generarRespuesta($entrega)->output();
        $pdfBase64 = base64_encode($pdfContent);

        $attachment = new Attachment($pdfBase64, "acta-{$entrega->codigo_acta}.pdf", 'attachment');

        $html = $this->plantillaCierre($entrega, $enlace);

        $emailParams = (new EmailParams())
            ->setFrom('no-reply@skyfreeshopdutyfree.com')
            ->setFromName('Sky Free Shop - Entregas')
            ->setRecipients($recipients)
            ->setSubject("✅ Acta completada - {$entrega->codigo_acta}")
            ->setHtml($html)
            ->setText("Tu acta {$entrega->codigo_acta} fue recibida y firmada por {$entrega->liderRecibe->colaborador}.")
            ->setAttachments([$attachment]);

        return $this->enviar($emailParams, $entrega);
    }

    /**
     * Notificar rechazo
     */
    public function notificarRechazo(Entrega $entrega): bool
    {
        $liderEntrega = $entrega->liderEntrega;

        if (!$liderEntrega || !$liderEntrega->email) {
            return false;
        }

        $recipients = [new Recipient($liderEntrega->email, $liderEntrega->colaborador)];

        $enlace = $this->urlEntrega($entrega);

        $html = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto;'>
            <h2 style='color: #dc2626;'>❌ Acta rechazada</h2>
            <p>Hola <strong>{$liderEntrega->colaborador}</strong>,</p>
            <p>Tu acta <strong>{$entrega->codigo_acta}</strong> fue rechazada por {$entrega->liderRecibe->colaborador}.</p>
            <div style='background: #fef2f2; padding: 15px; border-left: 4px solid #dc2626;'>
                <strong>Razón:</strong><br>
                {$entrega->razon_rechazo}
            </div>
            <p style='margin-top: 20px;'>
                <a href='{$enlace}' style='background: #dc2626; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Ver acta</a>
            </p>
        </div>";

        $emailParams = (new EmailParams())
            ->setFrom('no-reply@skyfreeshopdutyfree.com')
            ->setFromName('Sky Free Shop - Entregas')
            ->setRecipients($recipients)
            ->setSubject("❌ Acta rechazada - {$entrega->codigo_acta}")
            ->setHtml($html);

        return $this->enviar($emailParams, $entrega);
    }

    private function enviar(EmailParams $params, Entrega $entrega): bool
    {
        try {
            $response = $this->mailer->email->send($params);

            if (isset($response['status_code']) && in_array($response['status_code'], [200, 202])) {
                $entrega->update(['correo_enviado' => true]);
                Log::info('Correo enviado', ['entrega_id' => $entrega->id]);
                return true;
            }

            Log::warning('Correo no enviado', [
                'entrega_id' => $entrega->id,
                'response' => $response
            ]);
            return false;

        } catch (\Throwable $e) {
            Log::error('Error enviando correo', [
                'entrega_id' => $entrega->id,
                'error' => $e->getMessage()
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
        $fecha = $entrega->fecha_acta->format('d/m/Y');
        $turno = ucfirst($entrega->turno);

        return "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; line-height: 1.6;'>
            <div style='background: linear-gradient(135deg, #3b82f6, #1e40af); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                <h1 style='margin: 0;'>📋 Nueva Acta de Entrega</h1>
                <p style='margin: 10px 0 0;'>Código: <strong>{$entrega->codigo_acta}</strong></p>
            </div>

            <div style='background: #ffffff; padding: 30px; border: 1px solid #e5e7eb;'>
                <p>Hola <strong>{$liderRecibe}</strong>,</p>

                <p><strong>{$liderEntrega}</strong> ha completado el acta de entrega del turno y la ha firmado digitalmente.
                Necesita que la revises y firmes para confirmar la recepción.</p>

                <div style='background: #f3f4f6; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                    <h3 style='margin-top: 0;'>Detalles del acta:</h3>
                    <ul style='list-style: none; padding: 0;'>
                        <li>📅 <strong>Fecha:</strong> {$fecha}</li>
                        <li>🕒 <strong>Turno:</strong> {$turno}</li>
                        <li>👤 <strong>Entregado por:</strong> {$liderEntrega}</li>
                        <li>📋 <strong>Novedades reportadas:</strong> {$totalNovedades}</li>
                    </ul>
                </div>

                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$enlace}' style='background: #3b82f6; color: white; padding: 15px 40px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;'>
                        🔍 Ver y firmar acta
                    </a>
                </div>

                <p style='color: #6b7280; font-size: 14px;'>
                    Al firmar, recibirás el PDF completo del acta por correo para tus registros.
                </p>
            </div>

            <div style='background: #f9fafb; padding: 20px; text-align: center; border-radius: 0 0 10px 10px; color: #6b7280; font-size: 12px;'>
                Sistema de Entregas Sky Free Shop<br>
                Este es un correo automático, por favor no responder.
            </div>
        </div>";
    }

    private function plantillaCierre(Entrega $entrega, string $enlace): string
    {
        $liderEntrega = $entrega->liderEntrega->colaborador;
        $liderRecibe = $entrega->liderRecibe->colaborador;

        return "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; line-height: 1.6;'>
            <div style='background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                <h1 style='margin: 0;'>✅ Acta Completada</h1>
                <p style='margin: 10px 0 0;'>{$entrega->codigo_acta}</p>
            </div>

            <div style='background: #ffffff; padding: 30px; border: 1px solid #e5e7eb;'>
                <p>Hola <strong>{$liderEntrega}</strong>,</p>

                <p>¡Buenas noticias! <strong>{$liderRecibe}</strong> ha firmado tu acta de entrega.</p>

                <div style='background: #ecfdf5; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #10b981;'>
                    <p style='margin: 0;'>📎 Encontrarás el <strong>PDF del acta firmada</strong> adjunto a este correo.</p>
                </div>

                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$enlace}' style='background: #10b981; color: white; padding: 15px 40px; text-decoration: none; border-radius: 8px; font-weight: bold;'>
                        📄 Ver acta completa
                    </a>
                </div>
            </div>

            <div style='background: #f9fafb; padding: 20px; text-align: center; border-radius: 0 0 10px 10px; color: #6b7280; font-size: 12px;'>
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
