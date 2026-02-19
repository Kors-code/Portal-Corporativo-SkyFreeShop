@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/candidatosedit.css') }}">

<div class="container">
    <h1>Editar Candidato</h1>

    <form method="POST" action="{{ route('candidatos.update', $candidato) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="form-group">
            <input id="nombre" name="nombre" type="text" value="{{ $candidato->nombre }}" placeholder=" " required>
            <label for="nombre">Nombre</label>
        </div>

        <div class="form-group">
            <input id="email" name="email" type="email" value="{{ $candidato->email }}" placeholder=" " required>
            <label for="email">Correo electrónico</label>
        </div>

        <div class="form-group">
            <input id="cv" name="cv" type="file" placeholder=" ">
            <label for="cv">Nuevo CV (opcional)</label>
        </div>

        <button type="submit">Actualizar</button>
    </form>
</div>
@endsection
