@extends('layouts.app')
@section('content')



  <link rel="stylesheet" href="{{ asset('css/perfil.css') }}"> 
  
</head>
<body>
  <!-- Botón para dispositivos pequeños (menú offcanvas) -->
  @if(session('success'))
    <div class="alert alert-success">
      {{ session('success') }}
    </div>
  @endif

  @if(session('error'))
    <div class="alert alert-danger">
      {{ session('error') }}
    </div>
  @endif


  <main class="container">
    <div class="profile-header">
      {{-- Usamos el usuario autenticado --}}
      <img
        src="{{ asset($usuario->foto) }}"
        alt="{{ $usuario->name }} "
      >
      <h2>{{ $usuario->name }} {{$usuario->apellido }}</h2>
      <p>{{ $usuario->email }}</p>
    </div>

    <div class="modulo-general">
      <div class="verificacion">
    <h3>Verificación de seguridad</h3>

    @if(!$usuario->fav_2fa)
        <p>No has configurado la verificación. Elige una opción:</p>
<a href="{{ route('enviarVerificacion') }}" class="btn">📧 Verificar por correo</a>
<a href="{{ route('2fa.setup') }}" class="btn">🔒 Verificar con Google Authenticator</a>
    @elseif($usuario->fav_2fa === 'email')
        <p>✅ Verificación por correo completada</p>
    @elseif($usuario->fav_2fa === 'google_authenticator')
        <p>✅ Verificación con Google Authenticator activa</p>
    @endif
</div>

      

      

    </div>
  </main>
</body>
@endsection
</html>
