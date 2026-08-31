<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Sky Free Shop | Portal Corporativo</title>
  <link rel="stylesheet" href="{{ asset('css/portal.css') }}?v={{ filemtime(public_path('css/portal.css')) }}">
</head>

<body id="top">
@php
  $isSubPortal = request()->routeIs('presupuesto');

  $resolveUrl = function ($target) {
      if ($target === '#') {
          return '#';
      }

      $isLaravelRoute = !str_starts_with($target, '/')
          && !str_starts_with($target, 'http')
          && $target !== '#';

      return $isLaravelRoute ? route($target) : $target;
  };

  $cardsByArea = collect($cards)->groupBy('area');
  $firstArea = ($areas ?? [])[0] ?? null;
  $quickNavCards = collect($featuredCards ?? [])->merge($cards ?? [])->unique('route')->take(6)->values();
  $areaDescriptions = [
      'Comercial' => 'Accesos de uso diario para asesores, especialistas, cajeros, Wishlist y SHA.',
      'Presupuesto' => 'Presupuesto, reportes, cajeros e importes reunidos en una sola ruta de gestión.',
      'Informacional' => 'Material interno y consulta rápida para asesores.',
      'Personal' => 'Talento humano, vacantes, candidatos y procesos de seguimiento interno.',
      'Analitica' => 'Visualizaciones ejecutivas, pasajeros, ventas por tienda, cierres e inventario.',
      'Contabilidad' => 'Importaciones bancarias, movimientos normalizados y archivos listos para gestión contable.',
      'Administracion' => 'Usuarios, permisos, configuraciones y gobierno del portal.',
  ];
@endphp

<div id="logoutModal" class="logout-modal">
  <div class="logout-modal-content">
    <div class="logout-icon">
      <i class="fa-solid fa-circle-exclamation"></i>
    </div>

    <h2>Cerrar sesión</h2>
    <p>Tu sesión actual se cerrará y deberás iniciar nuevamente para acceder.</p>

    <div class="logout-actions">
      <button id="cancelLogout" class="btn-cancel" type="button">Cancelar</button>
      <button id="confirmLogout" class="btn-confirm" type="button">Sí, cerrar sesión</button>
    </div>
  </div>
</div>

<form id="logout-form" action="{{ route('logout') }}" method="POST" hidden>
  @csrf
</form>

<header class="navbar" data-portal-nav>
  <div class="container navbar-inner">
    <a href="{{ route('welcome') }}" class="brand" aria-label="Sky Free Shop">
      <img src="{{ asset('imagenes/logo5.png') }}" alt="Logo Sky Free Shop" class="logo">
    </a>

    <nav class="utility-menu" aria-label="Accesos de usuario">
      <a href="{{ route('welcome') }}" class="main-menu-link active">
        <i class="fa-solid fa-house"></i>
        Inicio
      </a>
      <a href="{{ route('ver_perfil') }}" class="main-menu-link">
        <i class="fa-solid fa-user"></i>
        Mi perfil
      </a>
      <button id="openLogoutModal" class="logout-link" type="button">
        <i class="fa-solid fa-right-from-bracket"></i>
        <span>Cerrar sesión</span>
      </button>
    </nav>
  </div>

  <div class="category-bar">
    <nav class="container category-menu" aria-label="Módulos por área">
      @foreach(($areas ?? []) as $area)
        @php
          $areaIcon = $cardsByArea->get($area, collect())->first()['icon'] ?? 'fa-solid fa-layer-group';
        @endphp
        <button class="category-button" type="button" data-nav-area-toggle="{{ $area }}" aria-expanded="false">
          <i class="{{ $areaIcon }}"></i>
          {{ $area }}
          <i class="fa-solid fa-chevron-down"></i>
        </button>
      @endforeach
    </nav>
  </div>

  <div class="nav-module-panel" data-nav-module-panel>
    <div class="container nav-module-inner">
      @foreach(($areas ?? []) as $area)
        <section class="nav-module-list" data-nav-area-panel="{{ $area }}">
          <div class="nav-module-head">
            <span>{{ $area }}</span>
            <strong>Selecciona un módulo</strong>
          </div>

          <div class="nav-module-links">
            @foreach($cardsByArea->get($area, collect()) as $card)
              <a href="{{ $resolveUrl($card['route']) }}" class="nav-module-link">
                <span><i class="{{ $card['icon'] }}"></i></span>
                <strong>{{ $card['title'] }}</strong>
              </a>
            @endforeach
          </div>
        </section>
      @endforeach
    </div>
  </div>
</header>

@if($isSubPortal)
  <a href="{{ route('welcome') }}" class="back-button">
    <i class="fa-solid fa-arrow-left"></i>
    <span>Volver al Portal</span>
  </a>
@endif

<main>
  <section class="portal-stage" data-portal-carousel aria-label="Carrusel de áreas del portal">
    <div class="stage-background" aria-hidden="true"></div>

    <div class="container stage-shell">
      <div class="guide-assistant" data-guide-assistant>
        <span class="guide-avatar" aria-hidden="true">
          <i class="fa-solid fa-route"></i>
        </span>

        <span class="guide-message">
          <small>Guía Sky</small>
          <strong>¿Primera vez en el portal?</strong>
          <span>Aquí puedes iniciar un recorrido rápido para entender el carrusel y entrar a tus módulos.</span>
        </span>

        <span class="guide-actions">
          <button class="guide-start" type="button" data-guide-start>
          <i class="fa-solid fa-play"></i>
          Iniciar recorrido
        </button>
          <button class="guide-dismiss" type="button" data-guide-dismiss aria-label="Ocultar guía">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </span>
      </div>

      <div class="tour-card" data-tour-card hidden aria-live="polite">
        <span class="tour-icon" data-tour-icon><i class="fa-solid fa-route"></i></span>
        <div>
          <small data-tour-kicker>Paso 1 de 4</small>
          <h2 data-tour-title>Cada tarjeta es un área</h2>
          <p data-tour-text>Este carrusel organiza el portal por áreas. Al elegir una sección, el portal cambia los accesos visibles.</p>
        </div>
        <div class="tour-progress" data-tour-progress>
          <span class="active"></span>
          <span></span>
          <span></span>
          <span></span>
        </div>
        <div class="tour-actions">
          <button type="button" data-tour-prev aria-label="Paso anterior">
            <i class="fa-solid fa-arrow-left"></i>
          </button>
          <button type="button" data-tour-next>
            Siguiente
            <i class="fa-solid fa-arrow-right"></i>
          </button>
          <button type="button" data-tour-close aria-label="Cerrar recorrido">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
      </div>

      <div class="stage-main">
        <div class="stage-copy">
          @foreach(($areas ?? []) as $area)
            @php
              $areaCards = $cardsByArea->get($area, collect());
            @endphp

            <article class="stage-slide {{ $area === $firstArea ? 'active' : '' }}" data-carousel-slide="{{ $area }}">
              <span class="hero-label">Suite corporativa</span>
              <h1>{{ $area }}</h1>
              <p>{{ $areaDescriptions[$area] ?? 'Accesos organizados para que cada equipo encuentre su ruta de trabajo sin fricción.' }}</p>

              <div class="slide-actions" aria-label="Todos los accesos de {{ $area }}">
                @foreach($areaCards as $card)
                  <a href="{{ $resolveUrl($card['route']) }}">
                    <span class="slide-action-icon"><i class="{{ $card['icon'] }}"></i></span>
                    <span>{{ $card['title'] }}</span>
                  </a>
                @endforeach
              </div>
            </article>
          @endforeach
        </div>

        <div class="stage-visual" aria-hidden="true">
          <span>Portal Sky</span>
          <strong data-carousel-count>{{ $cardsByArea->get($firstArea, collect())->count() }}</strong>
          <small>módulos disponibles</small>
        </div>

        <div class="carousel-controls" aria-label="Controles del carrusel">
          <button type="button" data-carousel-prev aria-label="Área anterior">
            <i class="fa-solid fa-arrow-left"></i>
          </button>
          <button type="button" data-carousel-next aria-label="Área siguiente">
            <i class="fa-solid fa-arrow-right"></i>
          </button>
        </div>
      </div>

      <div class="area-carousel-cards" aria-label="Tarjetas de áreas">
        @foreach(($areas ?? []) as $areaIndex => $area)
          @php
            $areaCards = $cardsByArea->get($area, collect());
            $icon = $areaCards->first()['icon'] ?? 'fa-solid fa-layer-group';
          @endphp

          <button
            class="area-carousel-card {{ $area === $firstArea ? 'active' : '' }}"
            type="button"
            data-carousel-area="{{ $area }}"
            style="--card-delay: {{ $areaIndex * 0.05 }}s"
            aria-pressed="{{ $area === $firstArea ? 'true' : 'false' }}"
          >
            <span class="area-card-icon"><i class="{{ $icon }}"></i></span>
            <span>
              <strong>{{ $area }}</strong>
              <small>{{ $areaCards->count() }} accesos</small>
            </span>
          </button>
        @endforeach
      </div>
    </div>
  </section>

  <section id="areas" class="workspace-section">
    <div class="container workspace-layout">
      <div class="workspace-heading">
        <span>Centro de trabajo</span>
        <h2>Módulos disponibles</h2>
        <p>El panel cambia con el carrusel para mantener visible solo el área que necesitas.</p>
      </div>

      <div class="featured-access" aria-label="Accesos principales">
        @foreach($quickNavCards as $cardIndex => $card)
          <a href="{{ $resolveUrl($card['route']) }}" class="featured-card" style="--card-delay: {{ $cardIndex * 0.045 }}s">
            <span class="featured-icon"><i class="{{ $card['icon'] }}"></i></span>
            <span>
              <small>{{ $card['area'] }}</small>
              <strong>{{ $card['title'] }}</strong>
            </span>
          </a>
        @endforeach
      </div>

      <div class="module-panels">
        @foreach(($areas ?? []) as $areaIndex => $area)
          <article id="area-{{ \Illuminate\Support\Str::slug($area) }}" class="module-panel {{ $area === $firstArea ? 'active' : '' }}" data-carousel-panel="{{ $area }}">
            <div class="module-panel-head">
              <div>
                <span>Área activa</span>
                <h3>{{ $area }}</h3>
              </div>
              <strong>{{ $cardsByArea->get($area, collect())->count() }} accesos</strong>
            </div>

            <div class="module-grid">
              @foreach($cardsByArea->get($area, collect()) as $cardIndex => $card)
                <a href="{{ $resolveUrl($card['route']) }}" class="module-card" style="--card-delay: {{ ($areaIndex * 0.035) + ($cardIndex * 0.035) }}s">
                  <span class="module-icon"><i class="{{ $card['icon'] }}"></i></span>
                  <span class="module-copy">
                    <strong>{{ $card['title'] }}</strong>
                    <small>{{ $card['text'] }}</small>
                  </span>
                  <i class="fa-solid fa-arrow-right"></i>
                </a>
              @endforeach
            </div>
          </article>
        @endforeach
      </div>
    </div>
  </section>
</main>

<footer class="footer">
  <p>© {{ date('Y') }} Sky Free Shop — Todos los derechos reservados.</p>
</footer>

<script src="{{ asset('js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/logoutportal.js') }}?v={{ filemtime(public_path('js/logoutportal.js')) }}"></script>
<script src="{{ asset('js/portal.js') }}?v={{ filemtime(public_path('js/portal.js')) }}"></script>
</body>
</html>
