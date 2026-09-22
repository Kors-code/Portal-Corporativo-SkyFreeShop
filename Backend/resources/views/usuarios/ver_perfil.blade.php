<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Mi perfil | Sky Free Shop</title>
  <link rel="stylesheet" href="{{ asset('css/portal.css') }}?v={{ filemtime(public_path('css/portal.css')) }}">
</head>

<body>
@php
  $fullName = trim(($usuario->name ?? '') . ' ' . ($usuario->apellido ?? ''));
  $displayName = $fullName !== '' ? $fullName : ($usuario->username ?? 'Usuario');
  $photo = $usuario->foto ?: '/imagenes/perfil/sinfoto.jpg';
  $roleLabel = $usuario->roleModel->name ?? $usuario->role ?? 'Sin rol asignado';
  $twoFactorLabel = match ($usuario->fav_2fa ?? null) {
      'email' => 'Correo verificado',
      'google_authenticator' => 'Google Authenticator activo',
      default => 'Pendiente de configurar',
  };
  $emailVerified = (bool) ($usuario->email_verified_at ?? false);
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
      <a href="{{ route('welcome') }}" class="main-menu-link">
        <i class="fa-solid fa-house"></i>
        Inicio
      </a>
      <a href="{{ route('ver_perfil') }}" class="main-menu-link active">
        <i class="fa-solid fa-user"></i>
        Mi perfil
      </a>
      <button id="openLogoutModal" class="logout-link" type="button">
        <i class="fa-solid fa-right-from-bracket"></i>
        <span>Cerrar sesión</span>
      </button>
    </nav>
  </div>
</header>

<main>
  <section class="profile-stage">
    <div class="profile-background" aria-hidden="true"></div>

    <div class="container profile-hero">
      <a href="{{ route('welcome') }}" class="profile-back-link">
        <i class="fa-solid fa-arrow-left"></i>
        Volver al portal
      </a>

      @if(session('success'))
        <div class="profile-alert success" role="status">
          {{ session('success') }}
        </div>
      @endif

      @if(session('error'))
        <div class="profile-alert error" role="alert">
          {{ session('error') }}
        </div>
      @endif

      <div class="profile-shell">
        <section class="profile-summary" aria-labelledby="profile-title">
          <span class="hero-label">Cuenta corporativa</span>
          <img src="{{ asset($photo) }}" alt="{{ $displayName }}" class="profile-photo">
          <h1 id="profile-title">{{ $displayName }}</h1>
          <p>{{ $usuario->email }}</p>

          <div class="profile-status-row" aria-label="Estado de la cuenta">
            <span class="{{ $emailVerified ? 'is-ready' : 'is-pending' }}">
              <i class="fa-solid {{ $emailVerified ? 'fa-check' : 'fa-circle-exclamation' }}"></i>
              {{ $emailVerified ? 'Correo confirmado' : 'Correo por confirmar' }}
            </span>
            <span class="{{ ($usuario->fav_2fa ?? null) ? 'is-ready' : 'is-pending' }}">
              <i class="fa-solid fa-shield-halved"></i>
              {{ $twoFactorLabel }}
            </span>
          </div>
        </section>

        <section class="profile-details" aria-label="Datos del perfil">
          <div class="profile-section-head">
            <span>Información principal</span>
            <h2>Tu usuario y correo</h2>
            <p>Estos son los datos asociados a tu acceso en el portal corporativo.</p>
          </div>

          <div class="profile-info-grid">
            <article class="profile-info-card">
              <span class="profile-info-icon"><i class="fa-solid fa-user"></i></span>
              <div>
                <small>Usuario</small>
                <strong>{{ $usuario->username ?: 'Sin usuario registrado' }}</strong>
              </div>
            </article>

            <article class="profile-info-card">
              <span class="profile-info-icon"><i class="fa-solid fa-envelope"></i></span>
              <div>
                <small>Correo</small>
                <strong>{{ $usuario->email ?: 'Sin correo registrado' }}</strong>
              </div>
            </article>

            <article class="profile-info-card">
              <span class="profile-info-icon"><i class="fa-solid fa-id-card"></i></span>
              <div>
                <small>Rol</small>
                <strong>{{ $roleLabel }}</strong>
              </div>
            </article>

            <article class="profile-info-card">
              <span class="profile-info-icon"><i class="fa-solid fa-shield-halved"></i></span>
              <div>
                <small>Seguridad</small>
                <strong>{{ $twoFactorLabel }}</strong>
              </div>
            </article>
          </div>

          <div class="profile-security-panel">
            <div>
              <span>Verificación de seguridad</span>
              <h3>Protege tu cuenta</h3>
              @if(!$usuario->fav_2fa)
                <p>Elige una opción de verificación para reforzar el acceso a tu perfil.</p>
              @elseif($usuario->fav_2fa === 'email')
                <p>Tu verificación por correo está activa para esta cuenta.</p>
              @elseif($usuario->fav_2fa === 'google_authenticator')
                <p>Tu verificación con Google Authenticator está activa.</p>
              @endif
            </div>

            <div class="profile-security-actions">
              @if(!$usuario->fav_2fa)
                <a href="{{ route('perfil.enviarVerificacion') }}" class="profile-primary-action">
                  <i class="fa-solid fa-envelope"></i>
                  Verificar por correo
                </a>
                <a href="{{ route('2fa.setup') }}" class="profile-secondary-action">
                  <i class="fa-solid fa-shield-halved"></i>
                  Usar Authenticator
                </a>
              @else
                <a href="{{ route('welcome') }}" class="profile-primary-action">
                  <i class="fa-solid fa-house"></i>
                  Ir al portal
                </a>
              @endif
            </div>
          </div>
        </section>
      </div>
    </div>
  </section>
</main>

<footer class="footer">
  <p>© {{ date('Y') }} Sky Free Shop — Todos los derechos reservados.</p>
</footer>

<script src="{{ asset('js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/logoutportal.js') }}?v={{ filemtime(public_path('js/logoutportal.js')) }}"></script>
</body>
</html>
