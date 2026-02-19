

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Document</title>
</head>
<body>
<a href="{{ route('Disciplina.show') }}" class="btn-primary">⬅️ Retroceder</a>
<link rel="stylesheet" href="{{ asset('Disciplina/css/ListDisciplinas.css') }}">

<h1>📋 Disciplinas Positivas</h1>

<form action="{{ route('Disciplinas.listUsers') }}" method="GET" class="filter-form">

    <div class="form-group">
        <label for="query">Búsqueda:</label>
        <input type="text" id="query" name="query" value="{{ request('query') }}" placeholder="Cédula">
    </div>

    <button type="submit" class="btn-primary">Filtrar</button>
</form>

<!--<h3>📤 Cargar archivo Excel de Llamados de Atención</h3>-->

<!--<form action="{{ route('llamados.importar') }}" method="POST" enctype="multipart/form-data" style="margin-top: 1rem;">-->
<!--    @csrf-->

<!--    <div style="margin-bottom: 1rem;">-->
<!--        <label for="archivo_excel"><strong>Seleccionar archivo Excel (.xlsx o .csv)</strong></label><br>-->
<!--        <input type="file" name="archivo_excel" id="archivo_excel" required accept=".xlsx,.csv">-->
<!--    </div>-->

<!--    <button type="submit" class="btn"-->
<!--        style="background-color: #840028; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px;">-->
<!--        📥 Subir archivo-->
<!--    </button>-->
<!--</form>-->

<!--@if (session('import_success'))-->
<!--    <div class="alert alert-success" style="margin-top: 1rem;">-->
<!--        ✅ {{ session('import_success') }}-->
<!--    </div>-->
<!--@endif-->

<!--@if (session('import_errors'))-->
<!--    <div class="alert alert-danger" style="margin-top: 1rem;">-->
<!--        ⚠️ Se encontraron errores en algunas filas:-->
<!--        <ul>-->
<!--            @foreach (session('import_errors') as $error)-->
<!--                <li>{{ $error }}</li>-->
<!--            @endforeach-->
<!--        </ul>-->
<!--    </div>-->
<!--@endif-->


@if ($LlamadoAtencion->isEmpty())
    <div class="no-results">No se encontraron resultados.</div>
@else
    <table class="disciplinas-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Colaborador</th>
                <th>Cédula</th>
                <th>Aplicada Por</th>
                <th>Cédula</th>
                <th>Fase</th>
                <th>Grupo</th>
                <th>Orientación</th>
                <th>Detalle</th>
                <th>Archivo</th>
                <th>Fecha</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($LlamadoAtencion as $Llamado)
                <tr>
                    <td>{{ $Llamado->id }}</td>
                    <td>{{ $Llamado->nombre }}</td>
                    <td>{{ $Llamado->cedula }}</td>
                    <td>{{ $Llamado->jefe }}</td>
                    <td>{{ $Llamado->jefe_cedula }}</td>
                    <td>{{ $Llamado->fase }}</td>
                    <td>{{ $Llamado->grupo }}</td>
                    <td>{{ $Llamado->orientacion }}</td>
                    <td>{{ $Llamado->detalle }}</td>
                    <td>
                        @if ($Llamado->ruta_pdf)
                            <a href="{{ route('descargar.pdf', ['path' => $Llamado->ruta_pdf]) }}" class="link-download">📄 Descargar</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $Llamado->created_at->format('d-m-Y') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<div class="card-list">
    @foreach ($LlamadoAtencion as $Llamado)
        <div class="card-item">
            <p><strong>ID:</strong> {{ $Llamado->id }}</p>
            <p><strong>Colaborador:</strong> {{ $Llamado->nombre }}</p>
            <p><strong>Cédula:</strong> {{ $Llamado->cedula }}</p>
            <p><strong>Grupo:</strong> {{ $Llamado->grupo }}</p>
            <p><strong>Orientación:</strong> {{ $Llamado->orientacion }}</p>
            <p><strong>Aplicada Por:</strong> {{ $Llamado->jefe }}</p>
            <p><strong>Fase:</strong> {{ $Llamado->fase }}</p>
            <p><strong>Fecha:</strong> {{ $Llamado->fecha_evento}}</p>

            @if ($Llamado->ruta_pdf)
                <a href="{{ route('descargar.pdf', ['path' => $Llamado->ruta_pdf]) }}" class="link-download">📄 Descargar</a>
            @endif
        </div>
    @endforeach
</div>

</body>
</html>
