@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/SubirAllCv.css') }}">

<div class="container">
    <h1>Carga Masiva de Hojas de Vida</h1>

    {{-- Mensajes --}}
    @if(session('success'))
        <div class="alert success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert error">
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" enctype="multipart/form-data"
          action="{{ route('storeMasivo.subir' ) }}"
          id="form-carga-masiva">
        @csrf

        <div class="form-group">
            <label for="vacante_id">Vacante</label>
            <select name="vacante_id" id="vacante_id" required>
                <option value="">Seleccione una vacante</option>
                @foreach($vacantes as $vacante)
                    <option value="{{ $vacante->slug }}">{{ $vacante->titulo }}</option>
                @endforeach
            </select>
        </div>

        <div id="drop-area" class="drop-area" tabindex="0">
            <p>Arrastra aquí los archivos o haz clic</p>
            <small>Se aceptan: PDF, DOC, DOCX. Tamaño máximo por archivo: 2MB</small>
            <input type="file" id="cvs" name="cvs[]" multiple accept=".pdf,.doc,.docx">
        </div>

        <div id="preview"></div>

        <button type="submit" class="btn">Subir</button>
    </form>
</div>

<script src="{{ asset('js/SubirAllCv.js') }}"></script>
@endsection
