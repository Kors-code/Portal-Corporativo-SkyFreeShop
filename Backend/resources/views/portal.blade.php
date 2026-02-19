<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Sky Free Shop | Portal Corporativo</title>
  <link rel="stylesheet" href="{{ asset('css/portal.css') }}?v={{ filemtime(public_path('css/portal.css')) }}">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://kit.fontawesome.com/a2b5f6babc.js" crossorigin="anonymous" defer></script>

</head>

<body>
    
<!-- LOGOUT MODAL -->
<div id="logoutModal" class="logout-modal">
  <div class="logout-modal-content">

    <div class="logout-icon">
      <i class="fa-solid fa-circle-exclamation"></i>
    </div>

    <h2>Cerrar sesión</h2>
    <p>Tu sesión actual se cerrará y deberás iniciar nuevamente para acceder.</p>

    <div class="logout-actions">
      <button id="cancelLogout" class="btn-cancel">Cancelar</button>
      <button id="confirmLogout" class="btn-confirm">Sí, cerrar sesión</button>
    </div>

  </div>
</div>

<form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none;">
  @csrf
</form>


<form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none;">
  @csrf
</form>



  <!-- NAVBAR -->
  <header class="navbar">
    <div class="container navbar-inner">
      <a href="{{ route('welcome') }}">
        <img src="{{ asset('imagenes/logo5.png') }}" alt="Logo Sky Free Shop" class="logo">
      </a>
      <nav class="menu">
        <a href="{{ route('welcome') }}">Inicio</a>
        <a href="#">Nosotros</a>
        <a href="#"></a>
        <button id="openLogoutModal" class="btn btn-outline">
  <i class="fa-solid fa-right-from-bracket"></i> Cerrar sesión
</button>


      </nav>
    </div>
  </header>
@php
    $isPresupuesto = request()->routeIs('presupuesto');
@endphp

@if($isPresupuesto)
  <a href="{{ route('welcome') }}" class="back-button">
    <i class="fa-solid fa-arrow-left"></i> Volver al Portal
  </a>
@endif

  <!-- HERO -->
  <section class="hero ">
    <div class="container hero-inner">
      <div class="hero-text">
        <h1>{{ $title ?? 'Bienvenido' }}</h1>
        <p>{{ $subtitle ?? '' }}</p>

        <div class="hero-ctas">
          @foreach($buttons as $btn)
            @php
              $isLaravelRoute = !str_starts_with($btn['route'], '/')
                                && !str_starts_with($btn['route'], 'http')
                                && $btn['route'] !== '#';
            @endphp

            <a href="{{ $btn['route'] === '#' ? '#' : ($isLaravelRoute ? route($btn['route']) : $btn['route']) }}"
               class="{{ $btn['class'] }}">
                <i class="{{ $btn['icon'] }}"></i> {{ $btn['text'] }}
            </a>
          @endforeach
        </div>

      </div>
    </div>
  </section>

  <!-- MÓDULOS -->
  <section class="modules">
    <h2>Accesos Destacados</h2>
    <div class="cards container">
      @foreach($cards as $card)
        @php
          $isLaravelRoute = !str_starts_with($card['route'], '/')
                            && !str_starts_with($card['route'], 'http')
                            && $card['route'] !== '#';
        @endphp

        <article class="card">
          <i class="{{ $card['icon'] }}"></i>
          <h3>{{ $card['title'] }}</h3>
          <p>{{ $card['text'] }}</p>
          <a href="{{ $card['route'] === '#' ? '#' : ($isLaravelRoute ? route($card['route']) : $card['route']) }}"
             class="card-link">
             Ingresar
          </a>
        </article>
      @endforeach
    </div>
  </section>

  <!-- FOOTER -->
  <footer class="footer">
    <p>© {{ date('Y') }} Sky Free Shop — Todos los derechos reservados.</p>
  </footer>
<script src="{{ asset('js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/logoutportal.js') }}?v={{ filemtime(public_path('js/logoutportal.js')) }}"></script>

</body>
</html>
