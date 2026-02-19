<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'ATS')</title>
<link rel="stylesheet" href="{{ asset('css/bootstrap.min.css') }}">
<link rel="stylesheet" href="{{ asset('css/layout.css') }}">
<link rel="icon" type="image/png" href="{{ asset('imagenes/logo4.png') }}">
</head>
<body>
    <div class="navbar">
        <div style="display: flex; align-items: center;">
            <a href="{{ route('welcome') }}">
                <img class="logo" src="{{ asset('imagenes/logo3.png') }}" alt="Sky Free Shop Logo">
            </a>
        </div>
    <nav>
                <button class="logout-btn" data-bs-toggle="modal" data-bs-target="#logoutModal">Cerrar sesión</button>
                
    </nav>
</div>
<form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
    @csrf
</form>

<nav class="fixed-navbar">
        <a href="{{ route('vacante.create') }}" class="nav-link1" id="nav-crear-vacante">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            <span>Crear Vacante</span>
        </a>
        <a href="{{ route('vacantes.index') }}" class="nav-link1" id="nav-ver-vacantes">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/></svg>
            <span>Ver Vacantes</span>
        </a>
        <a href="{{ route('panel.candidatos') }}" class="nav-link1" id="nav-ver-candidatos">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zm0 13c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
            <span>Ver Candidatos</span>
        </a>
    </nav>
<div class="modal fade" id="logoutModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">¿Cerrar sesión?</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        Tu sesión se cerrará.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" id="confirm-logout-btn" class="btn btn-danger" >Sí, cerrar sesión</button>
      </div>
    </div>
  </div>
</div>

<script src="{{ asset('js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/logout.js') }}"></script>





    @yield('content')
</body>
</html>
