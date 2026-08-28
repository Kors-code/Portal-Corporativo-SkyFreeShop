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
  $isPresupuesto = request()->routeIs('presupuesto');

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
  $showcaseGroups = collect($showcase_groups ?? []);
  $quickNavCards = collect($featuredCards ?? [])->take(3);
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
      <span>
        <strong>Portal Sky</strong>
        <small>{{ $moduleCount ?? 0 }} accesos disponibles</small>
      </span>
    </a>

    <nav class="quick-links" aria-label="Accesos frecuentes">
      <a href="{{ route('welcome') }}" class="quick-link active">
        <i class="fa-solid fa-house"></i>
        Inicio
      </a>
      @foreach($quickNavCards as $card)
        <a href="{{ $resolveUrl($card['route']) }}" class="quick-link">
          <i class="{{ $card['icon'] }}"></i>
          {{ $card['title'] }}
        </a>
      @endforeach
    </nav>

    <div class="nav-actions">
      <button class="module-toggle" type="button" data-module-toggle aria-expanded="false" aria-controls="moduleDrawer">
        <i class="fa-solid fa-grip"></i>
        <span>Módulos</span>
        <i class="fa-solid fa-chevron-down"></i>
      </button>

      <button id="openLogoutModal" class="logout-link" type="button">
        <i class="fa-solid fa-right-from-bracket"></i>
        <span>Cerrar sesión</span>
      </button>
    </div>
  </div>

  <div id="moduleDrawer" class="module-drawer" data-module-drawer>
    <div class="container module-drawer-inner">
      <div class="module-finder">
        <label for="moduleSearch">
          <i class="fa-solid fa-magnifying-glass"></i>
          <span>Buscar acceso</span>
        </label>
        <input id="moduleSearch" type="search" placeholder="Escribe: inventario, cajeros, permisos..." data-module-search autocomplete="off">
      </div>

      <div class="module-directory">
        @foreach(($areas ?? []) as $area)
          <section class="module-group" data-module-group>
            <h2>{{ $area }}</h2>
            <div class="module-group-links">
              @foreach($cardsByArea->get($area, collect()) as $card)
                <a
                  href="{{ $resolveUrl($card['route']) }}"
                  class="drawer-module-link"
                  data-module-link
                  data-search-text="{{ \Illuminate\Support\Str::lower($card['title'].' '.$card['text'].' '.$card['area']) }}"
                >
                  <span class="drawer-module-icon"><i class="{{ $card['icon'] }}"></i></span>
                  <span>
                    <strong>{{ $card['title'] }}</strong>
                    <small>{{ $card['text'] }}</small>
                  </span>
                  <i class="fa-solid fa-arrow-right"></i>
                </a>
              @endforeach
            </div>
          </section>
        @endforeach
      </div>

      <p class="module-empty" data-module-empty>No encontramos accesos con ese texto.</p>
    </div>
  </div>
</header>

@if($isPresupuesto)
  <a href="{{ route('welcome') }}" class="back-button">
    <i class="fa-solid fa-arrow-left"></i>
    <span>Volver al Portal</span>
  </a>
@endif

<main>
  <section class="hero">
    <div class="hero-glow hero-glow-one"></div>
    <div class="hero-glow hero-glow-two"></div>

    <div class="container hero-inner">
      <div class="hero-copy">
        <span class="hero-label">{{ $eyebrow ?? 'Portal interno' }}</span>
        <h1>{{ $title ?? 'Bienvenido' }}</h1>
        <p>{{ $subtitle ?? '' }}</p>

        <div class="hero-ctas" aria-label="Accesos principales">
          @if($showcaseGroups->isNotEmpty())
            @foreach($showcaseGroups as $index => $group)
              <button
                class="btn {{ $index < 2 ? 'btn-primary' : 'btn-outline' }} showcase-picker"
                type="button"
                data-showcase-trigger="{{ $group['title'] }}"
              >
                <i class="{{ $group['icon'] }}"></i>
                {{ $group['title'] }}
              </button>
            @endforeach
          @else
            @foreach($buttons as $btn)
              <a href="{{ $resolveUrl($btn['route']) }}" class="{{ $btn['class'] }}">
                <i class="{{ $btn['icon'] }}"></i>
                {{ $btn['text'] }}
              </a>
            @endforeach
          @endif

          @if(auth()->user()?->role === 'super_admin')
            <a href="/panel/AdminPermissionsPanel" class="btn btn-outline permissions-shortcut">
              <i class="fa-solid fa-shield-halved"></i>
              Permisos
            </a>
          @endif
        </div>
      </div>

      <div class="hero-showcase" aria-label="Módulos dinámicos del portal">
        <div class="showcase-core">
          <img src="{{ asset('imagenes/logo5.png') }}" alt="Sky Free Shop">
        </div>

        <div class="showcase-route-stage">
          @foreach($showcaseGroups as $group)
            <div class="showcase-routes" data-showcase-panel="{{ $group['title'] }}">
              @foreach(collect($group['items'])->take(5) as $itemIndex => $item)
                <a
                  href="{{ $resolveUrl($item['route']) }}"
                  class="route-bubble route-{{ ($itemIndex % 5) + 1 }} route-delay-{{ $itemIndex }}"
                >
                  <i class="{{ $item['icon'] }}"></i>
                  <span>{{ $item['title'] }}</span>
                </a>
              @endforeach
            </div>
          @endforeach
        </div>
      </div>
    </div>
  </section>

  <section id="destacados" class="featured-section">
    <div class="container">
      <div class="section-heading centered">
        <span>Accesos principales</span>
        <h2>Lo más usado, siempre a la mano</h2>
      </div>

      <div class="featured-grid">
        @foreach(($featuredCards ?? collect($cards)->take(4)) as $card)
          <a href="{{ $resolveUrl($card['route']) }}" class="featured-card">
            <div class="featured-icon">
              <i class="{{ $card['icon'] }}"></i>
            </div>
            <span>{{ $card['area'] }}</span>
            <h3>{{ $card['title'] }}</h3>
            <p>{{ $card['text'] }}</p>
            <strong>Ingresar <i class="fa-solid fa-arrow-right"></i></strong>
          </a>
        @endforeach
      </div>
    </div>
  </section>

  <section id="areas" class="areas-section">
    <div class="container">
      <div class="section-heading">
        <span>Directorio corporativo</span>
        <h2>Explora los módulos por área</h2>
        <p>Elige una categoría para ver sus accesos sin perderte entre todos los módulos del portal.</p>
      </div>

      <div class="area-explorer">
        <aside class="area-menu">
          @foreach(($areas ?? []) as $area)
            <button
              class="area-option {{ $area === $firstArea ? 'active' : '' }}"
              type="button"
              data-area-target="{{ $area }}"
            >
              <span>{{ $area }}</span>
              <small>{{ $cardsByArea->get($area, collect())->count() }} módulos</small>
            </button>
          @endforeach
        </aside>

        <div class="area-panels">
          @foreach(($areas ?? []) as $area)
            <section class="area-panel {{ $area === $firstArea ? 'active' : '' }}" data-area-panel="{{ $area }}">
              <div class="area-panel-head">
                <span>{{ $area }}</span>
                <h3>{{ $cardsByArea->get($area, collect())->count() }} accesos disponibles</h3>
              </div>

              <div class="module-list">
                @foreach($cardsByArea->get($area, collect()) as $card)
                  <a href="{{ $resolveUrl($card['route']) }}" class="module-link">
                    <div class="module-link-icon">
                      <i class="{{ $card['icon'] }}"></i>
                    </div>
                    <div>
                      <strong>{{ $card['title'] }}</strong>
                      <p>{{ $card['text'] }}</p>
                    </div>
                    <i class="fa-solid fa-arrow-right"></i>
                  </a>
                @endforeach
              </div>
            </section>
          @endforeach
        </div>
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
