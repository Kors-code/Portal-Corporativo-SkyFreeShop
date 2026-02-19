<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Sky Free Shop | Portal Corporativo</title>
  <link rel="stylesheet" href="{{ asset('css/portal.css') }}">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://kit.fontawesome.com/a2b5f6babc.js" crossorigin="anonymous" defer></script>
</head>

<body>
  <!-- NAVBAR -->
  <header class="navbar">
    <div class="container navbar-inner">
      <a href="#">
        <img src="{{ asset('imagenes/logo5.png') }}" alt="Logo Sky Free Shop" class="logo">
      </a>
      <nav class="menu">
        <a href="#">Inicio</a>
        <a href="#">Nosotros</a>
        <a href="#">Contacto</a>
      </nav>
    </div>
  </header>

  <!-- HERO -->
  <section class="hero">
    <div class="container hero-inner">
      <div class="hero-text">
        <h1>Bienvenido al Portal Corporativo</h1>
        <p>Accede a los recursos internos y sistemas de Sky Free Shop desde un solo lugar.</p>
        <div class="hero-ctas">
          <a href="{{ route('Disciplina.show') }}" class="btn btn-primary">
            <i class="fa-solid fa-user-check"></i> Disciplinas Positivas
          </a>
          <a href="{{ route('vacantes.inicio') }}" class="btn btn-outline">
            <i class="fa-solid fa-briefcase"></i> Portal de Empleo
          </a>
          <a href="{{route('presupuesto')}}" class="btn btn-outline">
            <i class="fa-solid fa-briefcase"></i> Presupuesto
          </a>
        </div>
      </div>
    </div>
  </section>

  <!-- MÓDULOS -->
  <section class="modules">
    <h2>Accesos Destacados</h2>
    <div class="cards container">
      <article class="card">
        <i class="fa-solid fa-users"></i>
        <h3>Gestión de Talento</h3>
        <p>Programas y herramientas para el bienestar laboral.</p>
        <a href="{{ route('Disciplina.show') }}" class="card-link">Ingresar</a>
      </article>

      <article class="card">
        <i class="fa-solid fa-briefcase"></i>
        <h3>Oportunidades Laborales</h3>
        <p>Explora y postúlate a vacantes dentro de la organización.</p>
        <a href="{{ route('home') }}" class="card-link">Explorar</a>
      </article>

      <article class="card">
        <i class="fa-solid fa-circle-info"></i>
        <h3>Centro de Información</h3>
        <p>Consulta comunicados y documentos institucionales.</p>
        <a href="#" class="card-link">Ver más</a>
      </article>
    </div>
  </section>

  <!-- FOOTER -->
  <footer class="footer">
    <p>© {{ date('Y') }} Sky Free Shop — Todos los derechos reservados.</p>
  </footer>
</body>
</html>
