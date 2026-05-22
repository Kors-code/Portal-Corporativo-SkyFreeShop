<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Acta {{ $entrega->codigo_acta }}</title>
    <style>
        @page { margin: 30px 40px; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #1f2937;
            line-height: 1.5;
        }
        .header {
            border-bottom: 3px solid #3b82f6;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            color: #1e40af;
            font-size: 22px;
        }
        .header-info {
            display: table;
            width: 100%;
            margin-top: 10px;
        }
        .header-info > div {
            display: table-cell;
            vertical-align: top;
        }
        .codigo {
            font-size: 14px;
            font-weight: bold;
            color: #6b7280;
        }
        .estado-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            color: white;
        }
        .estado-completada { background: #10b981; }
        .estado-abierta { background: #f59e0b; }
        .estado-entregada { background: #3b82f6; }
        .estado-rechazada { background: #ef4444; }

        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background: #f9fafb;
            border-radius: 6px;
        }
        .info-table td {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
        }
        .info-table .label {
            font-weight: bold;
            background: #f3f4f6;
            width: 30%;
        }

        .section-title {
            background: linear-gradient(to right, #3b82f6, #1e40af);
            color: white;
            padding: 8px 15px;
            margin: 20px 0 10px 0;
            border-radius: 4px;
            font-size: 13px;
            font-weight: bold;
        }

        .novedad {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-left: 4px solid #3b82f6;
            padding: 12px;
            margin-bottom: 10px;
            border-radius: 4px;
            page-break-inside: avoid;
        }
        .novedad-header {
            display: table;
            width: 100%;
            margin-bottom: 8px;
        }
        .novedad-categoria {
            display: inline-block;
            padding: 3px 8px;
            background: #6b7280;
            color: white;
            font-size: 9px;
            border-radius: 3px;
            font-weight: bold;
        }
        .novedad-prioridad {
            display: inline-block;
            padding: 3px 8px;
            font-size: 9px;
            border-radius: 3px;
            font-weight: bold;
            margin-left: 5px;
        }
        .prio-urgente { background: #dc2626; color: white; }
        .prio-alta { background: #ef4444; color: white; }
        .prio-media { background: #f59e0b; color: white; }
        .prio-baja { background: #6b7280; color: white; }
        .novedad-titulo {
            font-weight: bold;
            margin: 5px 0;
            color: #1f2937;
        }
        .novedad-descripcion {
            color: #4b5563;
        }
        .novedad-observacion {
            background: #fef3c7;
            border-left: 3px solid #f59e0b;
            padding: 6px 10px;
            margin-top: 8px;
            font-style: italic;
            font-size: 10px;
        }

        .firmas-section {
            margin-top: 30px;
            page-break-inside: avoid;
        }
        .firmas-table {
            width: 100%;
            border-collapse: collapse;
        }
        .firma-cell {
            width: 50%;
            text-align: center;
            padding: 15px;
            border: 1px solid #e5e7eb;
            vertical-align: top;
        }
        .firma-titulo {
            font-weight: bold;
            margin-bottom: 10px;
            color: #1f2937;
        }
        .firma-imagen {
            height: 80px;
            border-bottom: 2px solid #1f2937;
            margin: 10px 0;
            text-align: center;
        }
        .firma-imagen img {
            max-height: 75px;
            max-width: 200px;
        }
        .firma-info {
            font-size: 10px;
            color: #6b7280;
            margin-top: 5px;
        }
        .firma-nombre {
            font-weight: bold;
            color: #1f2937;
        }

        .footer {
            position: fixed;
            bottom: 10px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 9px;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
        }

        .observaciones-generales {
            background: #fffbeb;
            border: 1px solid #f59e0b;
            padding: 12px;
            margin: 15px 0;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <!-- HEADER -->
    <div class="header">
        <table style="width: 100%;">
            <tr>
                <td>
                    <h1>📋 Acta de Entrega de Novedades</h1>
                    <div class="codigo">{{ $entrega->codigo_acta }}</div>
                    <div style="margin-top: 4px; color: #4b5563;">{{ $entrega->nombre_acta }}</div>
                </td>
                <td style="text-align: right;">
                    <span class="estado-badge estado-{{ $entrega->estado }}">
                        {{ $entrega->estado_label }}
                    </span>
                </td>
            </tr>
        </table>
    </div>

    <!-- INFORMACIÓN -->
    <table class="info-table">
        <tr>
            <td class="label">Fecha del turno</td>
            <td>{{ $entrega->fecha_acta->format('d/m/Y') }}</td>
            <td class="label">Turno</td>
            <td>{{ ucfirst($entrega->turno) }}</td>
        </tr>
        <tr>
            <td class="label">Sede</td>
            <td>{{ $entrega->sede ?? '-' }}</td>
            <td class="label">Generado</td>
            <td>{{ now()->format('d/m/Y H:i') }}</td>
        </tr>
        <tr>
            <td class="label">👤 Líder que entrega</td>
            <td>
                <strong>{{ $entrega->liderEntrega->colaborador }}</strong><br>
                <span style="font-size:10px; color:#6b7280;">{{ $entrega->liderEntrega->email }}</span>
            </td>
            <td class="label">👤 Líder que recibe</td>
            <td>
                <strong>{{ $entrega->liderRecibe->colaborador }}</strong><br>
                <span style="font-size:10px; color:#6b7280;">{{ $entrega->liderRecibe->email }}</span>
            </td>
        </tr>
    </table>

    <!-- OBSERVACIONES GENERALES -->
    @if($entrega->observaciones)
        <div class="observaciones-generales">
            <strong>📝 Observaciones generales:</strong><br>
            {{ $entrega->observaciones }}
        </div>
    @endif

    <!-- NOVEDADES POR CATEGORÍA -->
    @php
        $novedadesPorCategoria = $entrega->novedades->groupBy('categoria');
    @endphp

    @foreach($novedadesPorCategoria as $categoriaKey => $items)
        @php $catInfo = $categorias[$categoriaKey] ?? null; @endphp

        <div class="section-title">
            {{ $catInfo['icon'] ?? '📋' }} {{ $catInfo['label'] ?? $categoriaKey }}
            <span style="float: right; font-weight: normal;">({{ count($items) }} {{ count($items) === 1 ? 'novedad' : 'novedades' }})</span>
        </div>

        @foreach($items as $novedad)
            <div class="novedad" style="border-left-color: {{ $catInfo['color'] ?? '#3b82f6' }};">
                <div class="novedad-header">
                    <span class="novedad-prioridad prio-{{ $novedad->prioridad }}">{{ strtoupper($novedad->prioridad) }}</span>
                    @if($novedad->requiere_seguimiento)
                        <span style="color: #dc2626; font-size: 10px; margin-left: 8px;">⚠️ Requiere seguimiento</span>
                    @endif
                </div>
                @if($novedad->titulo)
                    <div class="novedad-titulo">{{ $novedad->titulo }}</div>
                @endif
                <div class="novedad-descripcion">{{ $novedad->descripcion }}</div>

                @if($novedad->observaciones_receptor)
                    <div class="novedad-observacion">
                        <strong>Observación de {{ $entrega->liderRecibe->colaborador }}:</strong><br>
                        {{ $novedad->observaciones_receptor }}
                    </div>
                @endif
            </div>
        @endforeach
    @endforeach

    @if($entrega->novedades->isEmpty())
        <div style="text-align: center; padding: 30px; color: #9ca3af;">
            No se reportaron novedades en este acta.
        </div>
    @endif

    <!-- FIRMAS -->
    <div class="firmas-section">
        <div class="section-title">✍️ Firmas</div>

        <table class="firmas-table">
            <tr>
                <td class="firma-cell">
                    <div class="firma-titulo">Líder que entrega</div>
                    <div class="firma-imagen">
                        @if($entrega->firmaEntrega)
                            @if(str_starts_with($entrega->firmaEntrega->firma_data, 'data:image'))
                                <img src="{{ $entrega->firmaEntrega->firma_data }}" alt="Firma">
                            @elseif(str_starts_with(trim($entrega->firmaEntrega->firma_data), '<svg') || str_starts_with(trim($entrega->firmaEntrega->firma_data), '<'.'?xml'))
                                {!! $entrega->firmaEntrega->firma_data !!}
                            @else
                                <img src="data:image/png;base64,{{ $entrega->firmaEntrega->firma_data }}" alt="Firma">
                            @endif
                        @else
                            <span style="color:#9ca3af;">Pendiente de firma</span>
                        @endif
                    </div>
                    <div class="firma-nombre">{{ $entrega->liderEntrega->colaborador }}</div>
                    <div class="firma-info">
                        C.C. {{ $entrega->liderEntrega->cedula }}<br>
                        @if($entrega->firmaEntrega)
                            Firmado: {{ $entrega->firmaEntrega->fecha_firma->format("d/m/Y H:i") }}
                        @endif
                    </div>
                </td>

                <td class="firma-cell">
                    <div class="firma-titulo">Líder que recibe</div>
                    <div class="firma-imagen">
                        @if($entrega->firmaRecepcion)
                            @if(str_starts_with($entrega->firmaRecepcion->firma_data, 'data:image'))
                                <img src="{{ $entrega->firmaRecepcion->firma_data }}" alt="Firma">
                            @elseif(str_starts_with(trim($entrega->firmaRecepcion->firma_data), '<svg') || str_starts_with(trim($entrega->firmaRecepcion->firma_data), '<'.'?xml'))
                                {!! $entrega->firmaRecepcion->firma_data !!}
                            @else
                                <img src="data:image/png;base64,{{ $entrega->firmaRecepcion->firma_data }}" alt="Firma">
                            @endif
                        @else
                            <span style="color:#9ca3af;">Pendiente de firma</span>
                        @endif
                    </div>
                    <div class="firma-nombre">{{ $entrega->liderRecibe->colaborador }}</div>
                    <div class="firma-info">
                        C.C. {{ $entrega->liderRecibe->cedula }}<br>
                        @if($entrega->firmaRecepcion)
                            Firmado: {{ $entrega->firmaRecepcion->fecha_firma->format('d/m/Y H:i') }}
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="footer">
        Sistema de Entregas Sky Free Shop · Documento generado el {{ now()->format('d/m/Y H:i:s') }}
    </div>
</body>
</html>
