@extends('layouts.app')

@section('content')


<link rel="stylesheet" href="{{ asset('css/main.css') }}">
@php
    $user = Auth::user();
    $userRole = Auth::user()->role;
@endphp
<div class="hero">
    <h1>Bienvenido al portal de empleo de Sky Free Shop</h1>
    <p>Gestiona vacantes, candidatos y hojas de vida de forma inteligente y automatizada.</p>
    <a href="{{ route('vacantes.index') }}">Explorar vacantes</a>
    <a href="{{ route('ver_perfil') }}">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-person-check-fill" viewBox="0 0 16 16">
  <path fill-rule="evenodd" d="M15.854 5.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 0 1 .708-.708L12.5 7.793l2.646-2.647a.5.5 0 0 1 .708 0"/>
  <path d="M1 14s-1 0-1-1 1-4 6-4 6 3 6 4-1 1-1 1zm5-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6"/>
</svg> Ver mi usuario
    </a>
    @if(session('success'))
        <p style="color: green;">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p style="color: red;">{{ session('error') }}</p>
    @endif
        @if(($userRole)==='super_admin')
      <a href="{{ route('view-users') }}" >Gestionar usuarios</a>
    @endif
</div>


        <div class="modal fade" id="modalVerificarEmail{{ $user->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">

                    <div class="modal-header">
                        <h5 class="modal-title">Confirmar Aprobación</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body text-center">
                        <form action="{{ route('enviarVerificacion', $user) }}" method="POST">
                            @csrf
                            <input type="hidden" name="action" value="aprobado">
                            <img src="{{ asset('imagenes/mail.gif') }}" class="img-fluid mb-3" style="max-height:150px;">
                            <p>¿Seguro que quieres aprobar a {{ $user->name }}?</p>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">✅ Aprobar</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    </div>

                        </form>
                </div>
            </div>
        </div>
<div class="footer">
    © {{ date('Y') }} Sky Free Shop. Todos los derechos reservados.
</div>

@endsection