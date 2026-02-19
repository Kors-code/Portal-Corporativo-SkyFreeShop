<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Importar Excel</title>
<link rel="stylesheet" href="{{ asset('Disciplina/css/import-empleados.css') }}">
</head>
<body>
    <div class="card">
        <h2>Importar Usuarios desde Excel</h2>

        @if (session('success'))
            <p class="success">{{ session('success') }}</p>
        @endif
        @if (session('error'))
            <p class="success">{{ session('error') }}</p>
        @endif

        <form action="{{ route('excel.import') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required>
            <br>
            <button type="submit">Subir e importar</button>
        </form>
    </div>
</body>
</html>
