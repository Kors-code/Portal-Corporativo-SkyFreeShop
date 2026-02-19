
@extends('Disciplinas.layouts.dashboard')

@section('titulo', '📋 Disciplinas Positivas')

@section('contenido')
@php
    $user = Auth::user();
    $userRole = Auth::user()->role;
@endphp
<link rel="stylesheet" href="{{ asset('Disciplina/css/ListDisciplinas.css') }}">

<h1>📋 Disciplinas Positivas</h1>



<form action="{{ route('Disciplinas.list') }}" method="GET" class="filter-form">
    <div class="form-group">
        <label for="fecha_inicio">Fecha inicio:</label>
        <input type="date" id="fecha_inicio" name="fecha_inicio" value="{{ request('fecha_inicio') }}">
    </div>
    
        <div class="form-group">
        <label for="estado">Estado:</label>
        <select id="estado" name="estado">
            <option value="">Todos</option>
            <option value="activo" {{ request('estado') == 'activo' ? 'selected' : '' }}>Activas</option>
            <option value="inactivo" {{ request('estado') == 'inactivo' ? 'selected' : '' }}>Inactivas</option>
        </select>
    </div>

    <div class="form-group">
        <label for="fecha_fin">Fecha fin:</label>
        <input type="date" id="fecha_fin" name="fecha_fin" value="{{ request('fecha_fin') }}">
    </div>

    <div class="form-group">
        <label for="query">Búsqueda:</label>
        <input type="text" id="query" name="query" value="{{ request('query') }}" placeholder="Cédula, nombre o ID">
    </div>

    <button type="submit" class="btn-primary">Filtrar</button>
</form>

<form action="{{ route('disciplinas.export') }}" method="GET" class="export-form">
    @if(($userRole)==='super_admin')
<a href="{{ route('disciplinas.eliminadas') }}" class="btn-primary">Eliminadas</a>
    @endif
    <input type="hidden" name="query" value="{{ request('query') }}">
    <input type="hidden" name="fecha_inicio" value="{{ request('fecha_inicio') }}">
    <input type="hidden" name="fecha_fin" value="{{ request('fecha_fin') }}">
    <button type="submit" class="btn-secondary">📤 Exportar Excel</button>
</form>
<hr>
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

<!-- Modal de Éxito -->
@if (session('success'))
<div id="modalSuccess" class="modal-overlay" style="display:flex;">
    <div class="modal-box">
        <h3>✅ Eliminado correctamente</h3>
        <p>{{ session('success') }}</p>

        <div class="modal-actions">
            <button id="successBtn" class="btn-confirm">OK</button>
        </div>
    </div>
</div>
@endif




@if ($LlamadoAtencion->isEmpty())
    <div class="no-results">No se encontraron resultados.</div>
@else
    <table class="disciplinas-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Colaborador</th>
                <th>Cédula</th>
                <th>Aplicada por</th>
                <th>Cédula</th>
                <th>Fase</th>
                <th>Grupo</th>
                <th>Orientación</th>
                <th>Detalle</th>
                <th>Archivo</th>
                <th>Fecha</th>
                <th>estado</th>
                @if(($userRole)==='super_admin')
                <th>Eliminar</th>
                @endif
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
                    <td>{{ $Llamado->fecha_evento}}</td>
                    <td>{{$Llamado->estado }}  </td>
                            @if(($userRole)==='super_admin')
                    <td> <form action="{{ route('disciplinas.delete') }}" method="POST">
                        @csrf
                        <input type="hidden" name="id" value="{{ $Llamado->id }}">
                        <button class="btn-primary btn-delete" type="button">Eliminar</button>
                    </form>
                    </td>
                            @endif
                  
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
            <p><strong>Orientacion:</strong> {{ $Llamado->orientacion }}</p>
            <p><strong>Aplicada Por:</strong> {{ $Llamado->jefe }}</p>
            <p><strong>Fase:</strong> {{ $Llamado->fase }}</p>
            <p><strong>Fecha:</strong> {{ $Llamado->created_at->format('d-m-Y') }}</p>
            <td>{{ $Llamado->created_at->format('d-m-Y') }}</td>
                            @if(($userRole)==='super_admin')
            <form action="{{ route('disciplinas.delete') }}" method="POST">
                        @csrf
                    <input type="hidden" name="id" value="{{ $Llamado->id }}">
                    <button class="btn-primary" type="submit" >Eliminar</button>
            </form>
                            @endif

            @if ($Llamado->ruta_pdf)
                <a href="{{ route('descargar.pdf', ['path' => $Llamado->ruta_pdf]) }}" class="link-download">📄 Descargar</a>
            @endif
        </div>
    @endforeach
</div>
<!-- Modal de Confirmación -->
<div id="modalConfirm" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3>⚠️ Confirmar eliminación</h3>
        <p>¿Seguro que deseas eliminar este registro?</p>
        
        <div class="modal-actions">
            <button id="cancelBtn" class="btn-cancel">Cancelar</button>
            <button id="confirmBtn" class="btn-confirm">Eliminar</button>
        </div>
    </div>
</div>
<script src="{{asset('Disciplina/js/ListDisciplina.js') }}"></script>
@endsection
