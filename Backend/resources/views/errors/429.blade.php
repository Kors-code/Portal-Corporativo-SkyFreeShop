<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Demasiadas Solicitudes</title>
    <link rel="stylesheet" href="{{ asset('css/bootstrap.min.css') }}">
</head>
<body class="d-flex justify-content-center align-items-center vh-100 bg-light">
    <div class="text-center">
        <h1 class="display-4 text-danger">¡Ups! 😓</h1>
        <p class="lead">Has realizado demasiadas solicitudes en poco tiempo.</p>
        <p>Por favor, espera un momento antes de intentarlo de nuevo.</p>
        <a href="{{ url()->previous() }}" class="btn btn-primary mt-3">Volver</a>
    </div>
</body>
</html>
