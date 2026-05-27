<?php

namespace App\Services;

use App\Http\Controllers\EntregaController;
use App\Models\Empleado;
use App\Models\Entrega;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EntregaMailService
{
    private const BRAND_RED = '#840028';
    private const BRAND_RED_DARK = '#5f001d';
    private const BRAND_RED_LIGHT = '#f8eef2';

    protected EntregaPdfService $pdfService;

    public function __construct(EntregaPdfService $pdfService)
    {
        $this->pdfService = $pdfService;
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

        return $this->enviar([
            'to' => $emailRecibe,
            'to_name' => $liderRecibe->colaborador,
            'subject' => "Nueva acta de entrega - {$entrega->codigo_acta}",
            'html' => $this->plantillaEntregaPendiente($entrega, $enlace),
        ], $entrega, 'entrega_pendiente');
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

        return $this->enviar([
            'to' => $emailEntrega,
            'to_name' => $liderEntrega->colaborador,
            'subject' => "Acta recibida y firmada - {$entrega->codigo_acta}",
            'html' => $this->plantillaCierre($entrega, $enlace),
            'attachment' => [
                'content' => $this->pdfService->generarRespuesta($entrega)->output(),
                'name' => "acta-{$entrega->codigo_acta}.pdf",
                'mime' => 'application/pdf',
            ],
        ], $entrega, 'acta_cerrada');
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

        $codigo = e($entrega->codigo_acta);
        $liderEntregaNombre = e($liderEntrega->colaborador);
        $liderRecibeNombre = e($entrega->liderRecibe->colaborador);
        $razon = e($entrega->razon_rechazo);

        $contenido = "
            <p style='margin:0 0 16px;color:#2f2f37;font-size:16px;line-height:1.55;'>Hola <strong>{$liderEntregaNombre}</strong>,</p>
            <p style='margin:0 0 24px;color:#2f2f37;font-size:16px;line-height:1.55;'>Tu acta <strong>{$codigo}</strong> fue rechazada por <strong>{$liderRecibeNombre}</strong>.</p>
            {$this->resumenActa([
                'Codigo de acta' => $codigo,
                'Estado' => 'Rechazada',
            ])}
            <div style='background:#fff8fa;border:1px solid #eadce1;border-left:5px solid " . self::BRAND_RED . ";border-radius:10px;padding:18px 20px;margin:24px 0;'>
                <p style='margin:0 0 8px;color:" . self::BRAND_RED_DARK . ";font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;'>Razon del rechazo</p>
                <p style='margin:0;color:#2f2f37;font-size:15px;line-height:1.55;'>{$razon}</p>
            </div>";

        $html = $this->plantillaCorporativa(
            'Acta rechazada',
            "Se requiere revision de {$codigo}",
            $contenido,
            'Ver acta',
            $this->urlEntrega($entrega)
        );

        return $this->enviar([
            'to' => $emailEntrega,
            'to_name' => $liderEntrega->colaborador,
            'subject' => "Acta rechazada - {$entrega->codigo_acta}",
            'html' => $html,
        ], $entrega, 'acta_rechazada');
    }

    private function emailEmpleado(?Empleado $empleado): ?string
    {
        if (!$empleado) {
            return null;
        }

        $usuarioPortal = EntregaController::buscarUsuarioPortalParaEmpleado($empleado);
        $email = $usuarioPortal->email ?? $empleado->email ?? null;

        if (!EntregaController::emailEsNotificable($email)) {
            Log::warning('Empleado con email invalido para notificacion de acta', [
                'empleado_id' => $empleado->id,
                'email' => $email,
            ]);
            return null;
        }

        return $email;
    }

    private function enviar(array $params, Entrega $entrega, string $tipo): bool
    {
        try {
            Mail::mailer('smtp')->send([], [], function (Message $message) use ($params) {
                $html = str_replace('{{logo_src}}', $this->logoSrc($message), $params['html']);

                $message
                    ->from(config('mail.from.address'), 'Sky Free Shop - Entregas')
                    ->replyTo(config('mail.from.address'), 'No Reply')
                    ->to($params['to'], $params['to_name'])
                    ->subject($params['subject'])
                    ->html($html);

                if (!empty($params['attachment'])) {
                    $message->attachData(
                        $params['attachment']['content'],
                        $params['attachment']['name'],
                        ['mime' => $params['attachment']['mime']]
                    );
                }
            });

            $entrega->update(['correo_enviado' => true]);
            Log::info('Correo de entrega enviado', ['entrega_id' => $entrega->id, 'tipo' => $tipo]);
            return true;
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
        $base = rtrim(( env('APP_URL_PORT')), '/');
        return "{$base}/panel/entregas/{$entrega->id}";
    }

    private function plantillaEntregaPendiente(Entrega $entrega, string $enlace): string
    {
        $liderEntrega = e($entrega->liderEntrega->colaborador);
        $liderRecibe = e($entrega->liderRecibe->colaborador);
        $totalNovedades = $entrega->novedades()->count();
        $fecha = optional($entrega->fecha_acta)->format('d/m/Y') ?? $entrega->fecha_acta;
        $turno = ucfirst($entrega->turno);
        $codigo = e($entrega->codigo_acta);

        $contenido = "
            <p style='margin:0 0 16px;color:#2f2f37;font-size:16px;line-height:1.55;'>Hola <strong>{$liderRecibe}</strong>,</p>
            <p style='margin:0 0 24px;color:#2f2f37;font-size:16px;line-height:1.55;'><strong>{$liderEntrega}</strong> cerro y firmo un acta de entrega para tu revision.</p>
            {$this->resumenActa([
                'Codigo de acta' => $codigo,
                'Fecha' => e((string) $fecha),
                'Turno' => e($turno),
                'Novedades reportadas' => (string) $totalNovedades,
            ])}
            <p style='margin:22px 0 0;color:#6b6f7a;font-size:13px;line-height:1.55;'>Al firmar, el acta quedara registrada como recibida para su cierre operativo.</p>";

        return $this->plantillaCorporativa(
            'Nueva acta de entrega',
            "Acta {$codigo} lista para revisar",
            $contenido,
            'Ver y firmar acta',
            $enlace
        );
    }

    private function plantillaCierre(Entrega $entrega, string $enlace): string
    {
        $liderEntrega = e($entrega->liderEntrega->colaborador);
        $liderRecibe = e($entrega->liderRecibe->colaborador);
        $pendientes = $entrega->novedades()->where('resuelto', false)->count();
        $codigo = e($entrega->codigo_acta);

        $contenido = "
            <p style='margin:0 0 16px;color:#2f2f37;font-size:16px;line-height:1.55;'>Hola <strong>{$liderEntrega}</strong>,</p>
            <p style='margin:0 0 24px;color:#2f2f37;font-size:16px;line-height:1.55;'><strong>{$liderRecibe}</strong> recibio y firmo tu acta de entrega.</p>
            {$this->resumenActa([
                'Codigo de acta' => $codigo,
                'Pendientes al cierre' => (string) $pendientes,
                'Documento' => 'PDF firmado adjunto',
            ])}
            <p style='margin:22px 0 0;color:#6b6f7a;font-size:13px;line-height:1.55;'>El PDF final de la entrega va adjunto a este correo para archivo y consulta.</p>";

        return $this->plantillaCorporativa(
            'Acta recibida y firmada',
            "Cierre confirmado para {$codigo}",
            $contenido,
            'Ver acta completa',
            $enlace
        );
    }

    private function plantillaCorporativa(string $titulo, string $subtitulo, string $contenido, string $cta, string $enlace): string
    {
        $brandRed = self::BRAND_RED;
        $brandRedDark = self::BRAND_RED_DARK;
        $brandRedLight = self::BRAND_RED_LIGHT;
        $titulo = e($titulo);
        $subtitulo = e($subtitulo);
        $cta = e($cta);
        $enlace = e($enlace);

        return "
        <div style='margin:0;padding:0;background:#f4f1f2;font-family:Arial,Helvetica,sans-serif;'>
            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='border-collapse:collapse;background:#f4f1f2;padding:32px 0;'>
                <tr>
                    <td align='center' style='padding:28px 14px;'>
                        <table role='presentation' width='640' cellspacing='0' cellpadding='0' style='width:100%;max-width:640px;border-collapse:collapse;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 14px 40px rgba(37,37,43,.10);'>
                            <tr>
                                <td style='background:{$brandRed};padding:28px 34px 24px;border-bottom:5px solid {$brandRedDark};'>
                                    <img src='{{logo_src}}' alt='Sky Free Shop' width='220' style='display:block;max-width:220px;width:100%;height:auto;margin:0 0 28px;'>
                                    <p style='margin:0 0 8px;color:#f8dfe7;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;'>Entrega de lideres</p>
                                    <h1 style='margin:0;color:#ffffff;font-size:29px;line-height:1.18;font-weight:800;'>{$titulo}</h1>
                                    <p style='margin:10px 0 0;color:#ffeaf1;font-size:15px;line-height:1.45;'>{$subtitulo}</p>
                                </td>
                            </tr>
                            <tr>
                                <td style='padding:34px;'>
                                    {$contenido}
                                    <table role='presentation' cellspacing='0' cellpadding='0' style='margin:30px 0 8px;border-collapse:collapse;'>
                                        <tr>
                                            <td style='border-radius:8px;background:{$brandRed};'>
                                                <a href='{$enlace}' style='display:inline-block;padding:14px 30px;color:#ffffff;text-decoration:none;font-weight:800;font-size:14px;letter-spacing:.01em;'>{$cta}</a>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            <tr>
                                <td style='background:{$brandRedLight};padding:18px 34px;border-top:1px solid #ead6dd;'>
                                    <p style='margin:0;color:{$brandRedDark};font-size:12px;line-height:1.5;font-weight:700;'>Sky Free Shop Duty Free</p>
                                    <p style='margin:4px 0 0;color:#7b5a65;font-size:12px;line-height:1.5;'>Mensaje automatico del sistema de entrega y recibo de lideres.</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>";
    }

    private function resumenActa(array $items): string
    {
        $brandRed = self::BRAND_RED;
        $rows = '';

        foreach ($items as $label => $value) {
            $rows .= "
                <tr>
                    <td style='padding:10px 0;color:#7a4254;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #eadce1;'>{$label}</td>
                    <td align='right' style='padding:10px 0;color:#26262b;font-size:14px;font-weight:700;border-bottom:1px solid #eadce1;'>{$value}</td>
                </tr>";
        }

        return "
            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='border-collapse:collapse;background:#fff8fa;border:1px solid #eadce1;border-left:5px solid {$brandRed};border-radius:10px;margin:24px 0;'>
                <tr>
                    <td style='padding:18px 20px;'>
                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='border-collapse:collapse;'>
                            {$rows}
                        </table>
                    </td>
                </tr>
            </table>";
    }

    private function logoSrc(Message $message): string
    {
        $logoPath = public_path('logo3.png');

        if (!file_exists($logoPath)) {
            $logoPath = public_path('imagenes/logo3.png');
        }

        return file_exists($logoPath)
            ? $message->embed($logoPath)
            : asset('logo3.png');
    }
}
