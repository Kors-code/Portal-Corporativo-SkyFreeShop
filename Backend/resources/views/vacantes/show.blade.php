<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Sky Free Shop - Vacante: {{ $vacante->titulo }}</title>
  <link rel="icon" type="image/png" href="{{ asset('imagenes/logo4.png') }}">
  <link rel="stylesheet" href="{{ asset('css/bootstrap.min.css') }}">
  <link rel="stylesheet" href="{{ asset('css/vacanteshow.css') }}">
  <script src="{{ asset('js/showvacante.js') }}" defer></script>
</head>
<body>
    
@if (session('success'))
  <div id="popup-data" data-popup='@json(["type" => "success", "text" => session("success")])'></div>
  
@endif

@if (session('error'))
  <div id="popup-data" data-popup='@json(["type" => "error", "text" => session("error")])'></div>
@endif

<!-- Modal pantalla completa -->
<div id="popup-overlay"
     class="popup-overlay hidden"
     data-success-logo="{{ asset('imagenes/loader.gif') }}"
     data-error-logo="{{ asset('imagenes/error.png') }}">
  <div class="popup-content">
    <div id="popup-loading" class="popup-loading hidden">
      <div class="spinner"></div>
      <p>Enviando tu solicitud, por favor espera...</p>
    </div>
    <div id="popup-message" class="popup-message hidden"></div>
    <button id="popup-close" class="popup-close hidden">Cerrar</button>
  </div>
</div>







<div class="main-container">
  <div class="left-section">
    <div class="overlay">
        <h1>{{$vacante->titulo}}</h1>
      <h1>Requisitos</h1>
@if ($vacante->requisitos)
    <ul>
        @foreach ($vacante->requisitos as $requisito)
            <li>{{ $requisito }}</li>
        @endforeach
    </ul>
@else
    <p>No hay requisitos.</p>
@endif

      <h1>Beneficios</h1>
      <ul>
        <li>Póliza de vida</li>
        <li>Descuentos en gimnasio</li>
        <li>Acompañamiento psicológico</li>
        <li>Días libres en fechas especiales: cumpleaños, grados</li>
        <li>Clases de inglés</li>
        @if ($vacante->beneficios)
        @foreach ($vacante->beneficios as $beneficio)
        <li>{{ $beneficio}}</li>
        @endforeach
        @endif
      </ul>
      <h1>Salario</h1>
      <ul>
          {{$vacante->salario}}
      </ul>
    </div>
  </div>

  <div class="right-section-alt">
      <div class="form-container-alt">
          <div class="form-header-alt">
              <h2>Únete al equipo</h2>
              <img src="/imagenes/logo3.png" alt="Sky Free Shop" class="logo-sm">
            </div>

    <form id="cvForm" class="alt-form" action="{{ route('postular.store', $vacante->slug) }}" method="POST" enctype="multipart/form-data">
      @csrf

      <div class="field">
        <input type="text" id="nombre" name="nombre" placeholder=" " value="{{ old('nombre') }}" required>
        <label for="nombre">Nombre completo</label>
      </div>

      <div class="field">
        <input type="email" id="email" name="email" placeholder=" " required>
        <label for="email">Correo electrónico</label>
      </div>
      <div class="field">
        <input  id="celular" name="celular" placeholder=" " required>
        <label >N° Celular</label>
      </div><div class="form-check consent my-3">
  <input
    class="form-check-input"
    type="checkbox"
    id="autorizacion"
    name="autorizacion"
    value="1"
    required
  >
  <label class="form-check-label" for="autorizacion">
    Autorizo a <strong>Sky Free Shop</strong> para el tratamiento de mis datos personales
    de acuerdo con la <a href="{{ asset('docs/politica.pdf') }}" target="_blank"> Política de Tratamiento de Datos </a>
    y la Ley 1581 de 2012.
  </label>
</div>


      <div class="field">
  <input type="file" id="cv" name="cv" accept=".pdf,.doc,.docx" required>
  <label for="cv">Sube tu hoja de vida</label>
</div>


      <button type="submit" class="btn-alt">Enviar Solicitud</button>
    </form>
  </div>
</div>



</body>
</html>

