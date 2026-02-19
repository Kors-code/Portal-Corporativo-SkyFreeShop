
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Sky Free Shop - Inicio</title>
    <link rel="icon" type="image/png" href="{{ asset('imagenes/logo4.png') }}">

        <link rel="stylesheet" href="{{ asset('css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/inicio.css') }}">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="intro-screen">
        <img src="/imagenes/logo3.png" alt="Sky Free Shop" class="intro-logo">
    </div>

    <div class="main-screen hidden">
        <header class="site-header">
            <img src="/imagenes/logo3.png" alt="Sky Free Shop" class="logo">
            <h3>Portal de empleo </h3>
        </header>
    </div>
    
    
         <a class="ig-btn" href="https://www.instagram.com/skyfreeshopcolombia/?igsh=MWN5OWh0emltdjRmZw%3D%3D" target="_blank" rel="noopener noreferrer">
    <span class="ig-icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="3" y="3" width="18" height="18" rx="5" stroke="white" stroke-width="1.6" fill="none"/>
            <circle cx="12" cy="12" r="3.1" stroke="white" stroke-width="1.6" fill="none"/>
            <circle cx="17.5" cy="6.5" r="0.9" fill="white"/>
        </svg>
    </span>
    <span class="ig-label">
        <span class="ig-title">Conocenos</span>
        <span class="ig-sub">@skyfreeshopcolombia</span>
    </span>
</a>
    
        <main class="city-options">
 <div class="grid-container">
        <!-- Tarjeta 1: Viajes -->
        <a href="{{ route('vacantes.vacantes', ['localidad' => 'Aeropuerto Internacional Jose Maria Cordoba']) }}" class="card-button">
            <span class="card-label">Medellín</span>
            <img src="{{asset('imagenes/sky-medellin.jpg')}}" alt="Destinos de Viaje">
            <div class="overlay">
                <h3>Aeropuerto </h3>
                <h3>José María Córdova </h3>
                <p>Ver vacantes</p>
            </div>
        </a>
        
        <!-- Tarjeta 2: Fotografía -->
        <a href="{{ route('vacantes.vacantes', ['localidad' => 'Puerto de manga']) }}" class="card-button">
        <img src="/imagenes/sky-puerto-de-manga.jpg" alt="Sky Free Shop" class="intro-logo">
            <div class="overlay">
                <h3>Puerto de manga </h3>
                <p>Ver vacantes</p>
            </div>
        </a>


    </div>
        </main>
            <h2 class="message-title">
                       Selecciona tu ciudad
            </h2>    
            
       
    <script src="{{ asset('js/inicio.js') }}"></script>
</body>
</html>
