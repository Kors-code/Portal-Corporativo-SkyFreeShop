@extends('layouts.app')

@section('content')
    <h2>Verificación 2FA</h2>
    <p>Introduce el código de 6 dígitos de Google Authenticator:</p>

    <form method="POST" action="{{ route('2fa.verify.post') }}">
        @csrf
        <input type="text" name="code" placeholder="123456" required>
        <button type="submit">Verificar</button>
    </form>
@endsection
