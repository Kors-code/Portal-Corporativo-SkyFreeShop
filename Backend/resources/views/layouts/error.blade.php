
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title')</title>
    <!-- Fuente Inter cargada -->
    <link rel="stylesheet" href="{{ asset('css/error.css') }}">
    
    <!-- Enlace al archivo de estilos externo -->
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <main class="main-container">
        
        <!-- Contenedor del Logo (Título de la empresa) -->
        <div class="logo">
            <p class="error-title">@yield('title')</p>
        </div>

        <!-- Tarjeta de Error: El punto focal del error -->
        <section class="error-box">
            
            <!-- Código de Error Grande y Animado -->
            <div class="error-code">
                <p>@yield('error')</p>
            </div>

            <!-- Título del Error -->
            
            <!-- Mensaje Descriptivo -->
            <p class="error-message">
               @yield('message')
            </p>
            
            <!-- Botón de Acción con efecto hover -->
            <div class="action-button">
                <a href="{{ url('/welcome') }}">
                    Volver al incio
                </a>
            </div>
        </section>
        

    </main>
</body>
</html>
