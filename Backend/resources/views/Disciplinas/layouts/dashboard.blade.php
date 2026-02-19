<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>@yield('titulo', 'Dashboard')</title>
    <link rel="stylesheet" href="{{ asset('Disciplina/css/layout.css') }}">

@php
    $user = Auth::user();
    $userRole = Auth::user()->role;
@endphp
</head>
<body>

  <!-- Sidebar -->
  <aside id="sidebar" class="sidebar" aria-hidden="true">
    <div>
      <div class="close-mobile" aria-hidden="true">
        <button class="icon-close" id="btnCloseSidebar" title="Cerrar menú">✕</button>
      </div>

      <div class="brand">
        <h2>DASHBOARD</h2>
        <p class="small">Panel administrativo</p>
      </div>

      <nav role="navigation" aria-label="Navegación principal">
        <a href="{{ route('welcome') }}"><i>🏠</i><span class="label">Inicio</span></a>
        <a href="{{ route('Disciplina.show') }}"><i>📝</i><span class="label">Aplicar Disciplina</span></a>
          @if($userRole == 'user')
        <a href="{{ route('Disciplinas.listUsers') }}"><i>📋</i><span class="label">Consultar </span></a>
            @else
        <a href="{{ route('empleados.list') }}"><i>👥</i><span class="label">Empleados</span></a>
        <a href="{{ route('Disciplinas.list') }}"><i>📋</i><span class="label">Disciplinas</span></a>
            @endif
      </nav>
      
    </div>

    <div class="logout" role="button" id="openModalBtn" tabindex="0">🚪 <span class="label">Cerrar sesión</span></div>
  </aside>

    <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
    @csrf
</form>
  <!-- Backdrop (to close sidebar on mobile) -->
  <div id="backdrop" class="backdrop" tabindex="-1" aria-hidden="true"></div>

  <!-- Toggle button (mobile) -->
  <button id="toggleBtn" class="toggle-btn" aria-controls="sidebar" aria-expanded="false" aria-label="Abrir menú">☰</button>

  <!-- Main content -->
  <main class="main" id="mainContent">
    <div class="container">
      @yield('contenido')
    </div>
  </main>


<!-- Modal (sin Bootstrap) -->
<div id="logoutModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h5>¿Cerrar sesión?</h5>
      <button class="close-btn" id="closeModalBtn">✕</button>
    </div>
    <div class="modal-body">
      <p>Tu sesión se cerrará.</p>
    </div>
    <div class="modal-footer">
      <button id="cancelBtn" class="btn-secondary">Cancelar</button>
      <button id="confirmLogoutBtn" class="btn-danger">Sí, cerrar sesión</button>
    </div>
  </div>
</div>

<form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none;">
  @csrf
</form>

<script src="{{ asset('Disciplina/js/layout.js') }}"></script>


</body>
</html>
