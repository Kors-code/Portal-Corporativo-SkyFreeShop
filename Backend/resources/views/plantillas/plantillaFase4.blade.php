<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <style>
    @page { margin: 0; }
    body { margin:0; padding:0; font-family: DejaVu Sans, sans-serif; font-size: 12px; }
      .page {
        position: relative;
        width: 210mm;       /* ancho A4 */
        height: 297mm;      /* alto A4 */
        background-image: url("{{ public_path('baseFase4.png') }}");
        background-size: 210mm 297mm; /* ajusta al tamaño del PDF */
        background-repeat: no-repeat;
      }

    .campo {
      position: absolute;
      color: #000;
      font-size: 12px;
    }

    /* Ajusta estas coordenadas para que queden exactas sobre tu imagen */
    .fecha        { top: 47mm; left: 40mm; }
.nombre       { top: 57mm; left: 38mm; width: 120mm; }
.cedula       { top: 57mm; left: 152mm; width: 50mm; }
.cargo        { top: 66mm; left: 36mm; width: 160mm; }

.jefe         { top: 87mm; left: 50mm; width: 120mm; }
.jefe_cedula  { top: 87mm; left: 154mm; width: 50mm; }
.cargo_jefe   { top: 97mm; left: 36mm; width: 160mm; }

.fecha_evento { top: 127mm; left: 55mm; }
.hora         { top: 138mm; left: 34mm; }
.fase         { top: 138mm; left: 77mm; }
.grupo        { top: 138mm; left: 115mm; }
.orientacion  { top: 147mm; left: 80mm; width: 160mm; }

.detalle {
  position: absolute;
  top: 171mm;
  left: 25mm;
  width: 160mm; /* puedes ajustar el ancho */
  max-height: 100mm; /* alto máximo */
  overflow: hidden; /* o auto si quieres scroll, pero hidden es mejor para PDF */
  white-space: normal; /* permite salto de línea automático */
  word-wrap: break-word; /* corta palabras largas si es necesario */
  line-height: 2.5; /* mejora la legibilidad */
}

.firma_empleado        { top: 261mm; left: 20mm; width: 75mm; }
.firma_jefe        { top: 261mm; left: 127mm; width: 75mm; }

    .small { font-size:10px; color:#444; }
  </style>
</head>
<body>
  <div class="page">
    <div class="campo fecha">{{ $data['fecha'] ?? '' }}</div>
    <div class="campo nombre">{{ $data['nombre'] ?? '' }}</div>
    <div class="campo cedula">{{ $data['cedula'] ?? '' }}</div>
    <div class="campo cargo">{{ $data['cargo'] ?? '' }}</div>

    <div class="campo jefe">{{ $data['jefe'] ?? '' }}</div>
    <div class="campo jefe_cedula">{{ $data['jefe_cedula'] ?? '' }}</div>
    <div class="campo cargo_jefe">{{ $data['cargo_jefe'] ?? '' }}</div>

    <div class="campo fecha_evento">{{ $data['fecha_evento'] ?? '' }}</div>
    <div class="campo hora">{{ $data['hora'] ?? '' }}</div>
    <div class="campo fase">{{ $data['fase'] ?? '' }}</div>
    <div class="campo grupo">{{ $data['grupo'] ?? '' }}</div>

    <div class="campo orientacion">{{ $data['orientacion'] ?? '' }}</div>

    <div class="campo detalle">{!! nl2br(e($data['detalle'] ?? '')) !!}</div>

    @if(!empty($data['firma_empleado']))
      <div class="campo firma_empleado">
        <img src="{{ $data['firma_empleado'] }}" alt="firma_empleado" style="max-width:220px; border:none;">
      </div>
    @endif
    @if(!empty($data['firma_jefe']))
      <div class="campo firma_jefe">
        <img src="{{ $data['firma_jefe'] }}" alt="firma_jefe" style="max-width:220px; border:none;">
      </div>
    @endif

  </div>
</body>
</html>
