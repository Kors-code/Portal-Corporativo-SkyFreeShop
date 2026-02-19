<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Sky Free Shop</title>
    <link rel="stylesheet" href="{{ asset('css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/login.css') }}?v={{ filemtime(public_path('css/login.css')) }}">

</head>
<body>
    <nav class="navbar">
        <div class="container-fluid">
            <a class="navbar-brand" href="{{ url('/') }}">Sky Free Shop</a>
        </div>
    </nav>

    <div class="login-container">
        <img src="{{ asset('imagenes/logo1.jpg') }}" alt="Sky Free Shop Logo" class="logo">

        @if (session('status'))
            <div class="alert alert-success">
                {{ session('status') }}
            </div>
        @endif

        <h4 class="mb-3">Iniciar Sesión</h4>

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="form-floating mb-3">
                 <input type="text" name="login" class="form-control" id="loginInput" placeholder="Correo o usuario" value="{{ old('login') }}" required autofocus>
                    <label for="loginInput">Correo o usuario</label>
                    @error('login')
                        <div class="text-danger text-start small mt-1">{{ $message }}</div>
                    @enderror

            </div>

            <!-- HTML: reemplazar SOLO el bloque del password -->
            <div class="form-floating mb-3 position-relative">
                <input
                    type="password"
                    name="password"
                    class="form-control"
                    id="passwordInput"
                    placeholder="Contraseña"
                    required
                    aria-describedby="passwordToggle"
                >
                <label for="passwordInput">Contraseña</label>
            
                <button
                    type="button"
                    id="passwordToggle"
                    class="password-toggle"
                    aria-pressed="false"
                    aria-label="Mostrar u ocultar contraseña"
                    title="Mostrar u ocultar contraseña"
                >
                    <!-- ojo abierto -->
                    <svg class="eye eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none"
                         xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
            
                    <!-- ojo cerrado (línea) -->
                    <svg class="eye eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none"
                         xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                        <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20C5 20 1 12 1 12a21.8 21.8 0 0 1 4.11-5.7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M1 1l22 22" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>



            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="remember" id="remember_me">
                <label class="form-check-label" for="remember_me">Recordarme</label>
            </div>

            <button type="submit" class="btn btn-custom">Ingresar</button>



            @if (Route::has('password.request'))
                <div class="mt-2">
                    <a class="btn btn-outline-secondary" href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
                </div>
            @endif
        </form>
    </div>
        <script src="{{ asset('js/login.js') }}?v={{ filemtime(public_path('js/login.js')) }}"></script>


</body>
</html>
