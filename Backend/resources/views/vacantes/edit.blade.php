@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/vacantecreate.css') }}">
<div class="container">
    <h1>Editar Vacante</h1>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('vacantes.update', $vacante->slug) }}" method="POST" enctype="multipart/form-data">
        @csrf
        @method('PUT') {{-- ✅ Importante para update --}}

        <label>Título:</label>
        <input type="text" name="titulo" value="{{ old('titulo', $vacante->titulo) }}" required>
        
        <label>Descripción:</label>
        <textarea name="descripcion" rows="2" required>{{ old('descripcion', $vacante->descripcion) }}</textarea>
        
        <label>Requisito IA:</label>
        <input type="text" name="requisito_ia" value="{{ old('requisito_ia', $vacante->requisito_ia) }}" required>
        
        <label>Salario</label>
        <input type="text" name="salario" value="{{ old('salario', $vacante->salario) }}" required>

        {{-- Beneficios --}}
        <div id="contenedor-beneficios">
            <label>Beneficios</label>
            @php $beneficios = old('beneficios', $vacante->beneficios ?? []); @endphp
            @foreach($beneficios as $beneficio)
                <input type="text" name="beneficios[]" value="{{ $beneficio }}" required>
            @endforeach
            @if(empty($beneficios))
                <input type="text" name="beneficios[]" value="">
            @endif
        </div>
        <button type="button" id="btn-agregarbeneficio">➕ Añadir beneficio</button>
        <button type="button" id="btn-quitarbeneficio">➖​ Quitar beneficio</button>

        {{-- Requisitos --}}
        <div id="contenedor-requisitos">
            <label>Requisitos</label>
            @php $requisitos = old('requisitos', $vacante->requisitos ?? []); @endphp
            @foreach($requisitos as $req)
                <input type="text" name="requisitos[]" value="{{ $req }}">
            @endforeach
            @if(empty($requisitos))
                <input type="text" name="requisitos[]" value="">
            @endif
        </div>
        <button type="button" id="btn-agregar">➕ Añadir Requisito</button>
        <button type="button" id="btn-quitar">➖​ Quitar Requisito</button>

        <br><br>

        {{-- Localidad --}}
        <label for="ciudad">Selecciona una ciudad:</label>
        <select id="ciudad" name="localidad" required>
            <option value=""> Selecciona </option>
            <option value="Aeropuerto Internacional Jose Maria Cordoba" 
                {{ old('localidad', $vacante->localidad) == 'Aeropuerto Internacional Jose Maria Cordoba' ? 'selected' : '' }}>
                Aeropuerto Internacional Jose Maria Cordoba
            </option>
            <option value="Puerto de manga"
                {{ old('localidad', $vacante->localidad) == 'Puerto de manga' ? 'selected' : '' }}>
                Puerto de manga
            </option>
        </select>

        {{-- Criterios --}}
        <h4>Criterios de Evaluación</h4>
        @php $criterios = old('criterios', $vacante->criterios ?? []); @endphp

        <div class="criterio">
            <label>Inglés</label>
            <input type="number" name="criterios[ingles]" value="{{ $criterios['ingles'] ?? '' }}" placeholder="Peso %" min="0" max="100" required>
        </div>

        <div class="criterio">
            <label>Habilidades Blandas</label>
            <input type="number" name="criterios[habilidades]" value="{{ $criterios['habilidades'] ?? '' }}" placeholder="Peso %" min="0" max="100" required>
        </div>

        <div class="criterio">
            <label>Experiencia</label>
            <input type="number" name="criterios[experiencia]" value="{{ $criterios['experiencia'] ?? '' }}" placeholder="Peso %" min="0" max="100" required>
        </div>

        <div class="criterio">
            <label>Educación</label>
            <input type="number" name="criterios[educacion]" value="{{ $criterios['educacion'] ?? '' }}" placeholder="Peso %" min="0" max="100" required>
        </div>

        <button type="submit">✅ Guardar cambios</button>
    </form>
</div>
@endsection
