@extends('layouts.error')

@if ($status === 'success')
    @section('title', 'Correo verificado ✅')
    @section('message', 'Tu correo ha sido verificado exitosamente.')
@else
    @section('title', 'Correo no válido ❌')
    @section('message', 'El enlace de verificación no es válido o ya fue utilizado.')
@endif
