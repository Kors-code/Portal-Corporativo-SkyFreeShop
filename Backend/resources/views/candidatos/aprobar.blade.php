    @extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/candidatosaprobar.css') }}">



    <table>
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Email</th>
                <th>CV</th>
                <th>Acciones</th>
                <th>Aprobado</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($candidatos as $candidato)
            @if ($candidato->estado === 'aprobado')
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
                        <a href="{{ route('candidatos.edit', $candidato) }}">✏️ Editar</a> |
                        <form action="{{ route('candidatos.destroy', $candidato) }}" method="POST" style="display:inline">
                            @csrf
                            @method('DELETE')
                            <button onclick="return confirm('¿Eliminar este candidato?')" type="submit">🗑️ Eliminar</button>
                        </form>
                    </td>
                    <td>
<form action="{{ route('candidatos.aprobar', $candidato->id) }}" method="POST" style="display:inline">
    @csrf
    <button type="submit"
            class="action-icon action-icon--approve"
            title="Aprobar Candidato">
        ✅
    </button>
</form>

        <a href="{{ route('candidatos.edit', $candidato) }}" class="action-icon action-icon--reject" title="Rechazar Candidato">
            <span class="icon">❌</span>
        </a>
                    </td>
                </tr>
            @endif
            @endforeach
        </tbody>
    </table>
@endsection
