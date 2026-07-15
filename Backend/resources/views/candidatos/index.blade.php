@extends('layouts.app')

@section('content')

<link rel="stylesheet" href="{{ asset('css/candidatosindex.css') }}">
<a href="{{ url()->previous() }}" class="volver">
    ← Volver
</a>
<div class="container">

    <h1>Lista de Candidatos</h1>

    <a class="btn-nuevo" href="{{ route('vacantes.index') }}">➕ Nuevo Candidato</a>

    <table>
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Email</th>
                <th>CV</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($candidatos as $candidato)
                <tr>
                    <td>{{ $candidato->nombre }}</td>
                    <td>{{ $candidato->email }}</td>
                    <td>
                        @if ($candidato->cv)
                            <a href="{{ asset('storage/' . $candidato->cv) }}" target="_blank">Ver CV</a>
                        @else
                            No enviado
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('candidatos.edit', $candidato) }}">✏️ Editar</a>
                        <form action="{{ route('candidatos.destroy', $candidato) }}" method="POST" style="display:inline">
                            @csrf
                            @method('DELETE')
                            <button class="js-confirm-action" data-confirm-message="Eliminar este candidato?" type="submit">Eliminar</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <form class="form-inline" method="GET" action="{{ route('candidatos.index') }}">
        <input name="q" value="{{ request('q') }}" placeholder="Buscar por experiencia o tecnología" />
        <button type="submit">Buscar</button>
    </form>

</div>

@endsection
