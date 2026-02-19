@extends('layouts.app')

@section('content')

<div class="container mt-5" style="max-width: 500px;">
    <div class="card shadow rounded-4 p-4">
        <h2 class="text-center mb-4" style="color:#840028; font-weight:bold;">
            Autenticación en dos pasos
        </h2>

        {{-- Alerta de éxito o error --}}
        @if(session('success'))
            <div id="alert-message" class="alert alert-success text-center" role="alert">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div id="alert-message" class="alert alert-danger text-center" role="alert">
                {{ session('error') }}
            </div>
        @endif

        {{-- Formulario para ingresar OTP --}}
        <form action="{{ route('email2fa.verify.post') }}" method="POST" class="mt-3">
            @csrf
            <div class="mb-3">
                <label for="code" class="form-label">Código recibido en tu correo</label>
                <input type="text" name="code" id="code" maxlength="6" 
                       class="form-control text-center fs-4" 
                       placeholder="••••••" required>
            </div>
            <button type="submit" class="btn w-100" 
                    style="background:#840028; color:white; font-weight:bold;">
                Verificar
            </button>
        </form>

        @error('code')
            <p class="text-danger mt-2 text-center">{{ $message }}</p>
        @enderror

        {{-- Botón oculto para reenviar OTP --}}
        <div id="resend-section" class="text-center mt-4" style="display:none;">
            <p class="text-muted">¿No recibiste el correo?</p>
            <form action="{{ route('email2fa.setup.post') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-outline-dark">
                    Reenviar código
                </button>
            </form>
        </div>
    </div>
</div>

{{-- Script para mostrar "Reenviar código" tras unos segundos --}}
<script src ="{{asset('js/2fa-email.js')}}"></script>
@endsection
