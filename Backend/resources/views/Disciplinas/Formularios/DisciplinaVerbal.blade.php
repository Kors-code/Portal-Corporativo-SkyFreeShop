
@extends('Disciplinas.layouts.dashboard')

@section('titulo', '📋 Aplicar Disciplina')

@section('contenido')

<link rel="stylesheet" href="{{ asset('Disciplina/css/DisciplinaVerbal.css') }}">
</head>
<body>
@if (session('success'))
  <div id="flash-success" class="alert success" role="status" aria-live="polite">
    <div class="alert-content">
      {{ session('success') }}
      @if(session('pdf_path'))
        <div class="small-note">
          <small>Descargando archivo...</small>
        </div>
        {{-- link oculto que el JS usará para descargar --}}
        <a id="downloadLink" href="{{ route('descargar.pdf', ['path' => session('pdf_path')]) }}" class="hidden">Descargar</a>
      @endif
    </div>
    <button class="close" onclick="document.getElementById('flash-success')?.remove()">×</button>
  </div>
@endif

@if (session('aplicar'))
  <div id="flash-success" class="alert success" role="status" aria-live="polite">
    <div class="alert-content">
      {{ session('aplicar') }}
      @if(session('pdf_pathLink'))
        <div class="small-note">
          <small>Presiona aqui para descargar</small>
        </div>
        {{-- link visible que el JS usará para descargar --}}
        <a href="{{ route('descargar.pdf', ['path' => session('pdf_pathLink')]) }}">Descargar PDF</a>
      @endif
    </div>
    <button class="close" onclick="document.getElementById('flash-success')?.remove()">×</button>
  </div>
@endif


@if ($errors->any())
  <div id="flash-errors" class="error-list" role="alert">
    <strong>Por favor corrige los siguientes errores:</strong>
    <ul class="errors-ul">
      @foreach ($errors->all() as $error)
        <li>{{ $error }}</li>
      @endforeach
    </ul>
    <div class="errors-footer">
      <button class="btn secondary btn-small" onclick="document.getElementById('flash-errors')?.remove()">Cerrar</button>
    </div>
  </div>
@endif



  <div class="wrap">
    <header class="header-row">
      <div class="logo">DP</div>
      <div class="brand">
        <h1>Disciplina Positiva</h1>
        <p>Asesoramiento Verbal — Generador de actas</p>
      </div>
      <div class="year">{{ date('Y') }}</div>
    </header>

    <div class="card" role="region" aria-label="Formulario Asesoramiento Verbal">
      <form class="grid" action="{{ route('formulario.pdf') }}" method="POST" novalidate>
        @csrf

        <!-- Panel principal (columna 1) -->
        <div>
          <div class="field">
            <label for="fecha">Fecha de aplicación</label>
            <input id="fecha" type="text" name="fecha"
                  value="{{ now()->format('Y-m-d H:i') }}" readonly
                  class="input-fecha">
          </div>

                  <!-- Searchable motivo select -->
          <div class="field">
            <label for="motivo">Motivo del llamado de atención</label>
            <div class="select-search">
              <input type="text" id="searchMotivo" placeholder="Buscar motivo..." name="Proceso" value="{{session('Proceso')}}" />
              <div class="dropdown" id="motivosDropdown" aria-hidden="true" role="listbox">
                <!-- Los motivos se llenan dinámicamente -->
              </div>
            </div>
            <input type="hidden" id="motivo" name="motivo" required />
          </div>


          <div class="row items-end">
            <div class="col">
              <div class="field">
                <label for="nombre">Aplicada a</label>
                <input id="nombre" type="text" name="nombre" required value="{{ session('nombre') }}" autocomplete="name" readonly>
              </div>
            </div>

            <div class="w180">
              <div class="field">
                <label for="cedula">Cédula</label>
                <div class="input-row">
                  <input id="cedula" type="text" name="cedula" value="{{ session('cedula') }}" placeholder="1036..." class="flex-1" autocomplete="off" inputmode="numeric" required>
                  <div id="loaderEmpleado" aria-hidden="true" class="loader"></div>
                </div>
              </div>
            </div>
          </div>

          <div class="field">
            <label for="cargo">Cargo</label>
            <input id="cargo" type="text" name="cargo" value="{{ session('cargo') }}" autocomplete="organization" readonly>
          </div>

          <hr class="sep">

          <h3 class="h3-primary">Descripción del Asesoramiento</h3>

          <div class="row">
            <div class="w180">
              <div class="field">
                <label for="fecha_evento">Fecha del evento</label>
                <input id="fecha_evento" type="date" name="fecha_evento" value="{{ session('fecha_evento') }}" required>
              </div>
            </div>
            
            <div class="w180">
              <div class="field">
                <label for="hora">Hora</label>
                <input id="hora" type="time" name="hora" value="{{ session('hora') }}">
              </div>
            </div>

            <div class="field">
              <label for="fase">Fase</label>
              <select id="fase" name="fase" required>
                <option value="">Seleccione una fase</option>
                <option value="1" {{ session('fase') == '1' ? 'selected' : '' }}>Fase 1</option>
                <option value="2" {{ session('fase') == '2' ? 'selected' : '' }}>Fase 2</option>
                <option value="3" {{ session('fase') == '3' ? 'selected' : '' }}>Fase 3</option>
                <option value="4" {{ session('fase') == '4' ? 'selected' : '' }}>Fase 4</option>
              </select>
            </div>


          </div>

          

          <div class="field">
            <label for="detalle">Descripción del evento </label>
            <textarea id="detalle" name="detalle" maxlength="480" required>{{ session('detalle') }}</textarea>
            <div id="contador" class="small contador">0 / 480</div>
          </div>

          
        </div>

        <!-- Panel lateral (columna 2) -->
        <aside>
          <div class="field">
            <label for="jefe">Realizada por</label>
            <input id="jefe" type="text" name="jefe" value="{{ session('jefe') }}" autocomplete="name" readonly>
          </div>

          <div class="field">
            <label for="jefe_cedula">Cédula del responsable</label>
            <div class="input-row">
              <input id="jefe_cedula" type="text" name="jefe_cedula" value="{{ session('jefe_cedula') }}" placeholder="1036..." class="flex-1" autocomplete="off" inputmode="numeric" required>
              <div id="loaderJefe" aria-hidden="true" class="loader"></div>
            </div>
          </div>

          <div class="field">
            <label for="cargo_jefe">Cargo (responsable)</label>
            <input id="cargo_jefe" type="text" name="cargo_jefe" value="{{ session('cargo_jefe') }}" readonly>
          </div>

          <hr class="sep">

          <h4 class="h4-primary">Firmas</h4>
          <div class="sign-row">
            <div class="sign-box" aria-label="Firma empleado">
              <div class="sign-top-row">
                <strong class="small">Firma Empleado</strong>
                <div class="sign-actions">
                  <button type="button" id="abrirFirmaEmpleado" class="btn secondary">Firmar</button>
                  <button type="button" id="borrarFirmaEmpleado" class="btn secondary">Borrar</button>
                </div>
              </div>
              <img id="imagenFirmaEmpleado" src="" alt="Firma empleado" class="hidden-img">
              <input type="hidden" id="firmaEmpleadoBase64" name="firma_empleado">
            </div>

            <div class="sign-box" aria-label="Firma responsable">
              <div class="sign-top-row">
                <strong class="small">Firma Responsable</strong>
                <div class="sign-actions">
                  <button type="button" id="abrirFirmaJefe" class="btn secondary">Firmar</button>
                  <button type="button" id="borrarFirmaJefe" class="btn secondary">Borrar</button>
                </div>
              </div>
              <img id="imagenFirmaJefe" src="" alt="Firma responsable" class="hidden-img">
              <input type="hidden" id="firmaJefeBase64" name="firma_jefe">
            </div>
          </div>

          <div class="form-footer mt-2">
            <div class="note small">Revisa los datos antes de Aplicar</div>
            <div class="form-actions">
              <button type="select" class="btn" name="accion" value="aplicar">Guardar Disciplina </button>
              <button type="submit" class="btn" name="download" value="1">Aplicar Disciplina Y Descargar pdf</button>
              <button type="submit" class="btn" name="accion" value="nueva">Nueva Disciplina</button>
            </div>
          </div>
        </aside>
      </form>
    </div>
  </div>
  <hr>

    <!-- Modal firma -->
  <div id="modalFirma" class="modal-firma hidden" aria-hidden="true">
    <div class="modal-inner">
      <h3 id="tituloModalFirma" class="modal-title">Firme con el mouse o dedo</h3>
      <canvas id="canvasFirma" width="680" height="180" class="canvas-style"></canvas>
      <div class="modal-actions">
        <button id="guardarFirma" class="btn">Guardar</button>
        <button id="limpiarFirma" class="btn secondary">Limpiar</button>
        <button id="cerrarFirma" class="btn secondary">Cerrar</button>
      </div>
    </div>
  </div>


<script src="{{ asset('Disciplina/js/DisciplinaVerbal.js') }}"></script>
</body>
</html>
@endsection
