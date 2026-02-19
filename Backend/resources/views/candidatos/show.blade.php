@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/showcandidatos.css') }}">
  <meta charset="UTF-8">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="app-config-toggle-store-route" content="{{ route('toggle.store') }}">
@if (session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

<a href="{{ route('panel.candidatos') }}" class="volver">
    ← Volver
</a>
<form method="GET" action="{{ route('candidatos.export', $vacante->slug) }}">
    <input type="hidden" name="q" value="{{ request('q') }}">
    <input type="hidden" name="puntaje_min" value="{{ request('puntaje_min') }}">
    <input type="hidden" name="puntaje_max" value="{{ request('puntaje_max') }}">
    <input type="hidden" name="fecha_inicio" value="{{ request('fecha_inicio') }}">
    <input type="hidden" name="fecha_fin" value="{{ request('fecha_fin') }}">
    <input type="hidden" name="ordenar" value="{{ request('ordenar') }}">
    <input type="hidden" name="contador" value="{{ request('contador') }}">
    <input type="hidden" name="cajero" value="{{ request('cajero') }}">
    <input type="hidden" name="ventas" value="{{ request('ventas') }}">
    <button type="submit" class="btn btn-success">Descargar Excel</button>
</form>

  <div class="d-flex align-items-center sticky-md-top">
    <!-- Botón principal -->
    <button id="toggleBtn" class="btn btn-primary me-2">X</button>
    
    <!-- Contenedor de botones oculto -->
    <div id="btnGroup" class="btn-container">
    <button class="btn btn-filtrar " type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasRight">
        🔍 Filtrar
    </button>
<div class="toolbar">

    <a href="{{ route('candidatos.aprobados.list', $vacante->slug) }}" class="btn btn-success">
        ✅ Aprobados
    </a>

    <a href="{{ route('candidatos.rechazados.list', $vacante->slug) }}" class="btn btn-danger">
        ❌ Rechazados
    </a>
</div>

    </div>
  </div>


<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasRight" aria-labelledby="offcanvasRightLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="offcanvasRightLabel" >Filtrar Candidatos</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body" >


<br>
        <div>
            <h6>Fecha</h6>
    <form class="form-inline" id="form" method="GET" action="{{ route('candidatos.show', $vacante->slug) }}">
        <input type="date" name="fecha_inicio" value="{{ request('fecha_inicio') }}">
        <input type="date" name="fecha_fin" value="{{ request('fecha_fin') }}">
        <input name="contador" id="contador" value="{{ request('contador') }}" type="hidden"/>
        <input name="cajero" id="cajero" value="{{ request('cajero') }}" type="hidden" />
        <input name="ventas" id="ventas" value="{{ request('ventas') }}" type="hidden" />
        </div>
        <div >
            <h6>puntaje</h6>
        <input type="number" name="puntaje_min" placeholder="Puntaje min del 1 al 100" value="{{ request('puntaje_min') }}">
        <input type="number" name="puntaje_max" placeholder="Puntaje max del 1 al 100" value="{{ request('puntaje_max') }}">
        </div>
        <div >
            <h6>Palabras</h6>
        <input name="q" value="{{ request('q') }}" />
        </div>
        <button type="submit" class="btn">Buscar</button>
    </form>
    </div>
</div>
    <h1>Listado de Candidatos</h1>

    <table class="custom-table">
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Email</th>
                <th>CV</th>
                <th>Acciones</th>
                <th>Razon IA</th>
                <th>Descargar</th>
                <th>Enviar Correo</th>
                <th>¿Correo enviado?</th>
<th>
    Puntaje 
    <div class="dropdown d-inline">
        <button class="btn btn-sm btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            ⤋
        </button>
        <ul class="dropdown-menu">
            <li>
                <a class="dropdown-item" href="{{ request()->fullUrlWithQuery(['ordenar' => 'puntaje_asc']) }}">
                    Ordenar Ascendente
                </a>
            </li>
            <li>
                <a class="dropdown-item" href="{{ request()->fullUrlWithQuery(['ordenar' => 'puntaje_desc']) }}">
                    Ordenar Descendente
                </a>
            </li>
            <li>
                <a class="dropdown-item" href="{{ request()->fullUrlWithQuery(['ordenar' => 'fecha']) }}">
                    Más recientes
                </a>
            </li>
        </ul>
    </div>
</th>

                
            </tr>
        </thead>
        <tbody>
            @foreach ($candidatos as $candidato)
                <tr class="{{ $candidato->estado === 'aprobado' ? 'table-success' : ($candidato->estado === 'rechazado' ? 'table-danger' : '') }}">
                    <td>{{ $candidato->nombre }}</td>
                    <td>{{ $candidato->email }}</td>

                    <td>
                        <a href="{{ route('candidatos.edit', $candidato) }}">✏️ Editar</a> |
                        <form action="{{ route('candidatos.destroy', $candidato) }}" method="POST">
                            @csrf
                            @method('DELETE')
                            <button onclick="return confirm('¿Eliminar este candidato?')" type="submit">🗑️ Eliminar</button>
                        </form>
                    </td>
                    <td>
<form action="{{ route('candidatos.aprobar', $candidato->id) }}" method="POST" >
    @csrf
    <button type="submit"
            class="action-icon action-icon--approve"
            title="Aprobar Candidato">
        ✅
    </button>
</form>
<form action="{{ route('candidatos.rechazar', $candidato->id) }}" method="POST" >
    @csrf
    <button type="submit"
            class="action-icon action-icon--approve"
            title="Rechazar Candidato">
        ❌
    </button>
</form>

                    </td>
                    <td>
                        {{ $candidato->razon_ia }}
                    </td>
                                        <td>
                        @if($candidato->cv)
                            <a href="{{ route('candidatos.cv', $candidato->id) }}" class="">
                                Descargar CV
                            </a>
                        @else
                            <span class="text-muted">Sin CV</span>
                        @endif
                    </td>
                                        <td>

    <input type="hidden" name="action" value="aprobado">
        <button type="button" data-bs-toggle="modal" data-bs-target="#modalAprobar{{ $candidato->id }}">
        ✅
    </button>

        <button type="button" data-bs-toggle="modal" data-bs-target="#modalRechazar{{ $candidato->id }}">
         ❌
    </button>
    
                    </td>
                    <td>
                        {{$candidato->estado_correo}}
                    </td>
                    <td>
                        {{$candidato->puntaje}}
                    </td>
                </tr>
                    <!-- Modal único para este candidato -->
    <div class="modal fade" id="modalAprobar{{ $candidato->id }}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title">Confirmar Aprobación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body text-center">
                    <form action="{{ route('candidatos.correo', $candidato->id) }}" method="POST">
                        @csrf
                        <input type="hidden" name="action" value="aprobado">
                        <img src="{{ asset('imagenes/mail.gif') }}" class="img-fluid mb-3" style="max-height:150px;">
                        <p>¿Seguro que quieres aprobar a {{ $candidato->nombre }}?</p>
                </div>

                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">✅ Aprobar</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                </div>

                    </form>
            </div>
        </div>
    </div>
    <div class="modal fade" id="modalRechazar{{ $candidato->id }}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title">Confirmar Aprobación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body text-center">
                    <form action="{{ route('candidatos.correo', $candidato->id) }}" method="POST">
                        @csrf
                        <input type="hidden" name="action" value="rechazado">
                        <img src="{{ asset('imagenes/mailsky.gif') }}" class="img-fluid mb-3" style="max-height:150px;">
                        <p>¿Seguro que quieres rechazar a {{ $candidato->nombre }}?</p>
                </div>

                <div class="modal-footer">
                    <button type="submit" class="btn btn-success"> ❌Rechazar</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                </div>

                    </form>
            </div>
        </div>
    </div>
    <div class="modal fade" id="modalPuntaje" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title">Ordenar por </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body text-center">
                    <form action="{{ route('candidatos.correo', $candidato->id) }}" method="POST">
                        @csrf
                        <input type="hidden" name="action" value="rechazado">
                        <p>¿Seguro que quieres rechazar a {{ $candidato->nombre }}?</p>
                </div>

                <div class="modal-footer">
                    <button type="submit" class="btn btn-success"> ❌Rechazar</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                </div>

                    </form>
            </div>
        </div>
    </div>
            @endforeach
        </tbody>
    </table>


<script src="{{ asset('js/candidatosshow.js') }}"></script>

@endsection
