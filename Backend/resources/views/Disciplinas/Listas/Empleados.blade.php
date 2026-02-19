@extends('Disciplinas.layouts.dashboard')

@section('titulo', '👥 Listado de Empleados')

@section('contenido')
@php
use Carbon\Carbon;
@endphp

{{-- Enlace al nuevo archivo CSS --}}
<link rel="stylesheet" href="{{ asset('Disciplina/css/ListEmpleados.css') }}">

<h1>👥 Listado de Empleados</h1>

<form action="{{ route('empleados.list') }}" method="GET" class="filter-form">
    <div class="form-group">
        <label for="query">Buscar:</label>
        <input type="text" id="query" name="query" value="{{ request('query') }}" placeholder="Cédula o nombre">
    </div>

    <div class="form-group">
        <label for="estado">Estado:</label>
        <select id="estado" name="estado">
            <option value="">Todos</option>
            <option value="Activo" {{ request('estado') == 'Activo' ? 'selected' : '' }}>Activo</option>
            <option value="Retirado" {{ request('estado') == 'Retirado' ? 'selected' : '' }}>Retirado</option>
        </select>
    </div>

    <div class="form-group">
        <label for="fecha_inicio">Fecha Ingreso Desde:</label>
        <input type="date" id="fecha_inicio" name="fecha_inicio" value="{{ request('fecha_inicio') }}">
    </div>

    <div class="form-group">
        <label for="fecha_fin">Hasta:</label>
        <input type="date" id="fecha_fin" name="fecha_fin" value="{{ request('fecha_fin') }}">
    </div>

    <button type="submit" class="btn-primary">Filtrar</button>
</form>

<form action="{{ route('exportar.empleados') }}" method="GET" class="export-form">
    <input type="hidden" name="query" value="{{ request('query') }}">
    <input type="hidden" name="fecha_inicio" value="{{ request('fecha_inicio') }}">
    <input type="hidden" name="fecha_fin" value="{{ request('fecha_fin') }}">
    <input type="hidden" name="estado" value="{{ request('estado') }}">
    <button type="submit" class="btn-secondary">📤 Exportar Excel</button>
</form>

@if ($empleados->isEmpty())
    <div class="no-results">No se encontraron empleados con esos criterios.</div>
@else
    <table class="empleados-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Colaborador</th>
                <th>Cédula</th>
                <th>Estado</th>
                <th>Fecha Ingreso</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($empleados as $empleado)
                <tr>
                    <td>{{ $empleado->id }}</td>
                    <td>{{ $empleado->colaborador }}</td>
                    <td>{{ $empleado->cedula }}</td>
                    <td>{{ $empleado->estado }}</td>
                    <td>{{ Carbon::parse($empleado->fecha_ingreso)->format('Y-m-d') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<div class="card-list">
    @foreach ($empleados as $empleado)
        <div class="card-item">
            <p><strong>ID:</strong> {{ $empleado->id }}</p>
            <p><strong>Colaborador:</strong> {{ $empleado->colaborador }}</p>
            <p><strong>Cédula:</strong> {{ $empleado->cedula }}</p>
            <p><strong>Estado:</strong> {{ $empleado->estado }}</p>
            <p><strong>Fecha Ingreso:</strong> {{ Carbon::parse($empleado->fecha_ingreso)->format('Y-m-d') }}</p>

            @if ($empleado->ruta_pdf)
                <a href="{{ route('descargar.pdf', ['path' => $empleado->ruta_pdf]) }}" class="link-download">📄 Descargar</a>
            @endif
        </div>
    @endforeach
</div>
@endsection
