<!-- resources/views/vacantes/index.blade.php -->
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Sky Free Shop - Vacantes Disponibles</title>
  <link rel="stylesheet" href="{{ asset('css/allvacantes.css') }}">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
</head>
<body>
    
  <header class="site-header">
      <a href="{{route('home')}}">
    <img src="/imagenes/logo3.png" alt="Sky Free Shop" class="logo">
      </a>
    <h1>Nuestras Vacantes</h1>
  </header>
  
  <a href="{{ url()->previous() }}" class="volver">
    ← Volver
</a>

  <main class="vacantes-container">
    @forelse($vacantes as $vacante)
        @if($vacante->habilitado != true)
            @continue
        @endif
        @if($vacante->localidad != $localidad)
            @continue
        @endif
      <article class="vacante-card">
        <h2>{{ $vacante->titulo }}</h2>
        <div class="descripcion">
            
        <p >{{ Str::limit($vacante->descripcion, 100) }}</p>
        @if ($vacante->requisitos)
    
        @foreach ($vacante->requisitos as $requisito)
            <p>{{ $requisito }}</p>
        @endforeach
    
    @endif
        </div>
        <a href="{{ route('vacantes.show', $vacante->slug) }}" class="btn-vermas">Ver detalles</a>
      </article>
    @empty
      <p class="sin-vacantes">No hay vacantes disponibles en este momento.</p>
    @endforelse
  </main>

  <footer class="site-footer">
    <p>&copy; {{ date('Y') }} Sky Free Shop. Todos los derechos reservados.</p>
  </footer>
</body>
</html>
