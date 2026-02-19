@extends('layouts.usuarios')

@section('content')

<link rel="stylesheet" href="{{ asset('css/user-create.css') }}">
  <body >
  @section('content')

  {{-- Mensaje de éxito --}}
  @if(session('success'))
    <div class="alert alert-success">
      {{ session('success') }}
    </div>
  @endif

  {{-- Errores de validación --}}
  @if($errors->any())
    <div class="alert alert-danger">
      <ul class="mb-0">
        @foreach($errors->all() as $err)
          <li>{{ $err }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <main class = "container">
    <form method="POST" action="{{ route('users.store') }}">
    @csrf
      <div class="form-grid">
        <div class="form-group">
          <label for="name">Nombre Completo</label>
          <input type="text" id="name" name="name"  class="form-control @error('name') is-invalid @enderror"
          value="{{ old('name') }}" required>
        </div>

        <div class="form-group">
          <label for="username">Nombre de usuario</label>
          <input type="text" id="username" name="username" class="form-control @error('username') is-invalid @enderror"
          value="{{ old('username') }}" required>
        </div>
        <div class="form-group">
          <label for="email">Correo electrónico</label>
          <input type="email" id="email" name="email"  class="form-control @error('email') is-invalid @enderror"
          value="{{ old('email') }}" required>
        </div>
      <div class="col-md-6">
        <label for="password" class="form-label">Contraseña</label>
        <input type="password" id="password" name="password"
               class="form-control @error('password') is-invalid @enderror"
               required>
      </div>
      <div class="col-md-6">
        <label for="password_confirmation" class="form-label">Confirmar contraseña</label>
        <input type="password" id="password_confirmation" name="password_confirmation"
               class="form-control @error('password_confirmation') is-invalid @enderror"
               required>
      </div>

        <div class="form-group">
          <label for="role">Tipo de usuario</label>
          <select id="role" name="role" class="form-control @error('role') is-invalid @enderror"
          value="{{ old('role') }}" required>
            <option value="" disabled selected>Selecciona...</option>
            <option value="super_admin">super_admin</option>
            <option value="admin">admin</option>
            <option value="user">user</option>
            <option value="user_portal">user_portal</option>
            <option value="user_disciplina">user_disciplina</option>
            <option value="seller">seller</option>
          </select>
        </div>
        <div class="form-group">
          <label for="auth_correo">Correo verificado</label>
          <select id="auth_correo" name="auth_correo" class="form-control @error('auth_correo') is-invalid @enderror"
          value="{{ old('auth_correo') }}" required>
            <option value="" disabled selected>Selecciona...</option>
            <option value="1">Verificado</option>
            <option value="0">No verificado</option>
          </select>
        </div>
      </div>
      
      <div class="form-group" style="text-align: center;">
        <button type="button" onclick="toggleImagenes()">Seleccionar Foto Perfil</button>
      </div>
      
      <!-- Contenedor de selección de imagen y subida drag & drop -->
      <div id="imagenes-container">
        <!-- Sección para subir una nueva imagen -->
        <div class="upload-form" style="margin-bottom:20px; text-align:center;">
          
        </div>
        
        <div class="gallery" style="text-align:center;">
          <!-- Galería de imágenes de perfil predefinidas -->
          @foreach($photos as $photo)
          <label class="opcion">
            <input type="radio" name="imagenes" value="{{ $photo->ruta }}"
                   class="d-none">
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
      
      <button type="submit" name="btnRegistrarUsuario">Registrar usuario</button>
    </form>
    <form id="upload-form" action="{{ route('photos.store') }}" method="POST" enctype="multipart/form-data">
    @csrf
      <div class="drop-zone" id="drop-zone">
        <span id="drop-zone-text">Arrastra y suelta tu imagen aquí, o haz clic para seleccionar</span>
        <input type="file" name="imagen" id="imagen" accept="image/*" required>
      </div>
      <button type="submit" name="submit">Subir Imagen</button>
    </form>
  </main>
@endsection
    <script src="{{ asset('js/usuarios/create.js') }}"></script>
    </body>