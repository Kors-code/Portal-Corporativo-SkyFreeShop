@extends('layouts.usuarios')

@section('content')
<main class="container py-5">
  <h2 class="text-center mb-4">Usuarios Registrados</h2>

  @if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
  @endif
  @if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
  @endif

  <div class="d-flex justify-content-between mb-3">
    <form class="d-flex" method="GET" action="{{ route('users.index') }}">
      <input type="text" name="q" value="{{ $q }}" class="form-control me-2" placeholder="Buscar ID, nombre, email">
      <button class="btn btn-outline-primary">Buscar</button>
    </form>
    <a href="{{ route('users.export') }}" class="btn btn-danger">
      Crear archivo Excel
    </a>
  </div>

  @if($users->count())
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-danger text-white">
          <tr>
            <th>#</th>
            <th>Nombre</th>
            <th>Usuario</th>
            <th>Email</th>
            <th>Rol</th>
            <th>Creado</th>
            <th>Verificado</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          @foreach($users as $u)
            <tr class="js-profile-row" data-profile-url="{{ route('ver_user', $u) }}" style="cursor:pointer;">
              <td>{{ $u->id }}</td>
              <td>{{ $u->name }}</td>
              <td>{{ $u->username }}</td>
              <td>{{ $u->email }}</td>
              <td>{{ $u->role }}</td>
              <td>{{ $u->created_at->format('Y-m-d') }}</td>
              <td>{{ $u->auth_correo ? 'Verificado' : 'Sin verificar' }}</td>
              <td>
                <a href="{{ route('users.edit', $u) }}" class="btn btn-sm btn-primary">Editar</a>
                <form method="POST" action="{{ route('users.destroy', $u) }}" class="d-inline js-confirm-delete">
                  @csrf
                  @method('DELETE')
                  <button class="btn btn-sm btn-danger">Eliminar</button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    {{ $users->links() }}
  @else
    <p class="text-center">No se encontraron usuarios.</p>
  @endif
</main>

<script src="{{ asset('js/usuarios/index.js') }}"></script>
@endsection
