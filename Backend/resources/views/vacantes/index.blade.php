
@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/vacantes.css') }}">

<a href="{{ url()->previous() }}" class="volver">
    ← Volver
</a>
<div class="container">
    <h1>Vacantes</h1>

    <a href="{{ route('vacante.create') }}" class="btn-crear">➕ Crear nueva vacante</a>


    <ul class="vacante-lista">
        <table class="vacantes-table">
            <thead>
            <tr>
                <th>Título</th>
                <th>Descripción</th>
                <th style="width: 110px;">Editar</th>
                <th style="width: 120px;">Eliminar</th>
                <th style="">Habilitar </th>
                <th style="">Deshabilitar</th>
                <th style="">Estado</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($vacantes as $vacante)
            <tr class="{{ $vacante->habilitado === false ? 'vacante-deshabilitada' : '' }}">

                <td>
                <a href="{{ route('vacantes.show', $vacante->slug) }}" class="vacante-link">
                    {{ $vacante->titulo }}
                </a>
                </td>
                <td>
                    {{ $vacante->descripcion }}
                </td>

                <td>
                <a href="{{ route('vacantes.edit', $vacante->slug) }}" class="btn-editar">✏️ Editar</a>
                </td>
                <td>
                <form action="{{ route('vacantes.destroy', $vacante->slug) }}" method="POST" onsubmit="return confirm('¿Estás seguro de eliminar esta vacante?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-eliminar">🗑️ Eliminar</button>
                </form>
                </td>
               <td>
                <form action="{{ route('vacantes.habilitar', $vacante->slug) }}" method="POST" 
                      onsubmit="return confirm('¿Estás seguro de habilitar esta vacante?');">
                    @csrf
                    <input type="hidden" name="habilitado" value="habilitado">
                    <button type="submit" class="btn-eliminar">Habilitar</button>
                </form>
            </td>
            <td>
                <form action="{{ route('vacantes.habilitar', $vacante->slug) }}" method="POST" 
                      onsubmit="return confirm('¿Estás seguro de deshabilitar esta vacante?');">
                    @csrf
                    <input type="hidden" name="habilitado" value="deshabilitar">
                    <button type="submit" class="btn-eliminar">Deshabilitar</button>
                </form>
            </td>
            <td>
                @if ($vacante->habilitado == true)
                
                    <p>habilitado</p>
                @else
                    <p>Deshabilitado</p>
                @endif
            </td>

            </tr>
            @endforeach
            </tbody>
        </table>


    </ul>

</div>

@endsection
