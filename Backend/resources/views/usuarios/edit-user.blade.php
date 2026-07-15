@extends('layouts.usuarios')

@section('content')
<link rel="stylesheet" href="{{ asset('css/edit-user.css') }}">

<main class="container" style="padding-top:100px;">
    <h2 class="mb-4">Actualizar usuario ({{ $usuario->name }})</h2>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('users.update', $usuario->id) }}">
        @csrf
        @method('PUT')

        <div class="form-group">
            <label for="name">Nombre Completo</label>
            <input type="text" id="name" name="name" class="form-control" value="{{ old('name', $usuario->name) }}" required>
        </div>

        <div class="form-group">
            <label for="username">Nombre de usuario</label>
            <input type="text" id="username" name="username" class="form-control" value="{{ old('username', $usuario->username) }}" required>
        </div>

        <div class="form-group">
            <label for="email">Correo electronico</label>
            <input type="email" id="email" name="email" class="form-control" value="{{ old('email', $usuario->email) }}" required>
        </div>

        <div class="form-group">
            <label for="password">Contrasena (opcional)</label>
            <input type="password" id="password" name="password" class="form-control">
        </div>

        <div class="form-group">
            <label for="role">Tipo usuario</label>
            <select id="role" name="role" class="form-select" required>
                <option value="user" {{ $usuario->role == 'user' ? 'selected' : '' }}>Usuario</option>
                <option value="admin" {{ $usuario->role == 'admin' ? 'selected' : '' }}>Administrador</option>
                <option value="super_admin" {{ $usuario->role == 'super_admin' ? 'selected' : '' }}>Super Admin</option>
                <option value="user_portal" {{ $usuario->role == 'user_portal' ? 'selected' : '' }}>user Portal</option>
                <option value="user_disciplina" {{ $usuario->role == 'user_disciplina' ? 'selected' : '' }}>User Disciplina</option>
            </select>
        </div>

        <div class="form-group">
            <label for="auth_correo">Correo verificado</label>
            <select id="auth_correo" name="auth_correo" class="form-control @error('auth_correo') is-invalid @enderror" required>
                <option value="" disabled>Selecciona...</option>
                <option value="1" {{ $usuario->auth_correo == 1 ? 'selected' : '' }}>Verificado</option>
                <option value="0" {{ $usuario->auth_correo == 0 ? 'selected' : '' }}>No verificado</option>
            </select>
        </div>

        <div class="form-group" style="text-align: center;">
            <button type="button" id="toggle-imagenes" class="button">Seleccionar Foto Perfil</button>
        </div>

        <div id="imagenes-container">
            <div class="upload-form" style="margin-bottom:20px; text-align:center;"></div>
            <div class="gallery" style="text-align:center;">
                @foreach($photos as $photo)
                    <label class="opcion">
                        <input type="radio" name="imagenes" value="{{ $photo->ruta }}" class="d-none">
                        <img src="{{ $photo->ruta }}" alt="Avatar" class="img-thumbnail" style="width:100px">
                    </label>
                @endforeach

                <label class="opcion">
                    <input type="radio" name="imagenes" value="/imagenes/perfil/avatar2.png">
                    <img src="/imagenes/perfil/avatar2.png" alt="Imagen 2">
                </label>
                <label class="opcion">
                    <input type="radio" name="imagenes" value="/imagenes/perfil/avatar3.png">
                    <img src="/imagenes/perfil/avatar3.png" alt="Imagen 3">
                </label>
                <label class="opcion">
                    <input type="radio" name="imagenes" value="/imagenes/perfil/avatar4.png">
                    <img src="/imagenes/perfil/avatar4.png" alt="Imagen 4">
                </label>
                <label class="opcion">
                    <input type="radio" name="imagenes" value="/imagenes/perfil/avatar5.png">
                    <img src="/imagenes/perfil/avatar5.png" alt="Imagen 5">
                </label>
                <label class="opcion">
                    <input type="radio" name="imagenes" value="/imagenes/perfil/sinfoto.jpg">
                    <img src="/imagenes/perfil/sinfoto.jpg" alt="Imagen 6">
                </label>
            </div>
        </div>

        <button type="submit" class="btn btn-primary mt-3">Guardar cambios</button>
    </form>

    <form id="upload-form" action="{{ route('photos.store') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="drop-zone" id="drop-zone">
            <span id="drop-zone-text">Arrastra y suelta tu imagen aqui, o haz clic para seleccionar</span>
            <input type="file" name="imagen" id="imagen" accept="image/*" required>
        </div>
        <button type="submit" name="submit">Subir Imagen</button>
    </form>
</main>

<script src="{{ asset('js/usuarios/edit.js') }}"></script>
@endsection
