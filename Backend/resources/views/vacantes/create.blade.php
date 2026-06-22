@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/vacantecreate.css') }}">

<a href="{{ url()->previous() }}" class="volver">
    ← Volver
</a>

<div class="container">
    <h1>Crear vacante</h1>
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul style="margin: 0; padding-left: 20px;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @if(session('success'))
        <p class="success-message">{{ session('success') }}</p>
    @endif

<form id="formulario">

</form>

    <form method="POST" action="{{ route('vacantes.store') }}">
        @csrf
        <label>Título de la vacante:</label>
        <input type="text" name="titulo" required>
        
        <label>Descripción:</label>
         <small class="descripcion-campo">
        *Informacion que se vera en la vacante .
    </small>
        <textarea name="descripcion" rows="2"></textarea>
        
        <label>Requisito IA:</label>
        <small class="descripcion-campo">
    *Este campo <strong>no será visible para los candidatos</strong>. 
    Aquí debes escribir los requisitos que la inteligencia artificial usará para analizar las hojas de vida.
</small>

<!-- Botón para mostrar/ocultar recomendaciones -->
<button type="button" id="mostrarRecomendaciones" class="btn btn-sm btn-outline-secondary mt-2">
    📋 Ver recomendaciones
</button>

<!-- Bloque oculto de recomendaciones -->
<div id="recomendacionesIA" style="display: none; margin-top: 10px; font-size: 0.9rem; color: #444; background: #f9f9f9; padding: 12px; border-radius: 8px;">
    <p><strong>💡 Consejos para redactar el requisito IA:</strong></p>
    <ul style="padding-left: 18px;">
        <li>Usa frases <strong>claras y medibles</strong>: incluye años de experiencia, nivel de idioma o herramientas específicas.</li>
        <li>Ejemplo: <em>“Mínimo 2 años de experiencia en ventas, inglés B2 o superior.”</em></li>
        <li>Incluye palabras clave relevantes al cargo, como: <em>ventas, comercial, atención al cliente, gestión.</em></li>
        <li>Evita descripciones vagas como “persona proactiva” o “con experiencia”.</li>
        <li>Usa texto corrido (no listas con guiones o saltos de línea).</li>
    </ul>
    <p><strong>🎯 Este campo ayuda a que la IA evalúe correctamente la compatibilidad entre el candidato y la vacante.</strong></p>
</div>
        <textarea name="requisito_ia" rows="3" required></textarea>
        </label>
        <label>Salario</label>
        <small class="descripcion-campo">
        *Indica el salario o el rango salarial. Esta información será visible para los candidatos.
        </small>
        <input type="text" name="salario" required>
        </label>
        <div id="contenedor-beneficios">
        <label>Beneficios</label>
        <small class="descripcion-campo">
            Visible para los candidatos
        </small>
        <textarea name="beneficios[]" rows="2" required></textarea>
        </label>
            
        </div>
    <button type="button" id="btn-agregarbeneficio">➕ Añadir beneficio</button>
    <button type="button" id="btn-quitarbeneficio">➖​ Quitar beneficio</button>
        <div id="contenedor-requisitos">
        <div class="campo">
            <label>Requisitos</label>
            <small class="descripcion-campo">
                *Ejemplo: mínimo 1 año de experiencia, nivel intermedio de inglés, etc.
            </small>
            <textarea name="requisitos[]" rows="2"></textarea>
            
        </div>
    </div>

    <button type="button" id="btn-agregar">➕ Añadir requisito</button>
    <button type="button" id="btn-quitar">➖​ Quitar requisito</button>
    <br><br>
    <label for="ciudad">Ubicación de la vacante:</label>
    <select id="ciudad" name="localidad" required>
    <option value=""> Selecciona </option>
    <option value="Aeropuerto Internacional Jose Maria Cordoba"> Rionegro Aeropuerto Internacional José María Córdoba</option>
    <option value="Puerto de manga">Cartagena Puerto de manga</option>
  </select>
        <h4>Criterios de Evaluación</h4>
         <small class="descripcion-campo">
        *Estos valores se usarán internamente para que la IA evalúe los perfiles
        según la importancia de cada criterio, <b>¡Importante!</b> El resultado final debe dar 100.
    </small>
<div class="criterio">
    <label>Inglés</label>
    <input type="number" name="criterios[ingles]" placeholder="Peso %" min="0" max="100" required>

    <label>Habilidades blandas</label>
    <input type="number" name="criterios[habilidades]" placeholder="Peso %" min="0" max="100" required>

<div class="criterio">
    <label>Experiencia</label>
    <input type="number" name="criterios[experiencia]" placeholder="Peso %" min="0" max="100" required>
</div>

<div class="criterio">
    <label>Educación</label>
    <input type="number" name="criterios[educacion]" placeholder="Peso %" min="0" max="100" required>
</div>
    <button type="submit">✅ Enviar</button>
    </form>
</div>
<script src="{{ asset('js/añadirRequisitos.js') }}" defer></script>
@endsection
