@extends('layouts.app')

@section('content')
    <h2>Configurar Google Authenticator</h2>
    <p>Escanea este QR en Google Authenticator y luego ingresa el código de 6 dígitos cuando hagas login.</p>

    <img src="data:image/png;base64,{{ $qrCode }}" alt="QR Code">
    
        <form action="{{ route('2fa.setup.post') }}" method="POST" class="mt-3">
            @csrf
            <div class="mb-3">
                <label for="code" class="form-label">Código recibido en tu correo</label>
                <input type="text" name="code" id="code" maxlength="6" 
                       class="form-control text-center fs-4" 
                       placeholder="••••••" required>
            </div>
            <button type="submit" class="btn w-100">
                Verificar
            </button>
        </form>
@endsection
