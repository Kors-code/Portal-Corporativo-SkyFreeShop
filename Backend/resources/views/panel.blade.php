<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $authUser = auth()->user();
        $authEmpleado = null;
        if ($authUser) {
            $authEmpleado = \App\Http\Controllers\EntregaController::resolverEmpleadoParaUsuario($authUser);
        }
    @endphp
    <meta name="laravel-user" content='@json($authUser)'>
    <meta name="laravel-empleado" content='@json($authEmpleado)'>

    @php
        $manifestPath = public_path('react/manifest.json');
        $viteManifestPath = public_path('react/.vite/manifest.json');
        if (!file_exists($manifestPath) && file_exists($viteManifestPath)) {
            $manifestPath = $viteManifestPath;
        }
        if (!file_exists($manifestPath)) {
            die('manifest.json no encontrado en public/react');
        }
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $entry = $manifest['index.html'];
    @endphp

    {{-- CSS generado por Vite --}}
    @if(isset($entry['css']))
        @foreach($entry['css'] as $css)
            <link rel="stylesheet" href="{{ asset('react/'.$css) }}">
        @endforeach
    @endif
</head>
<body>
    <div id="root"></div>

    {{-- JS principal de React --}}
    <script type="module" src="{{ asset('react/'.$entry['file']) }}"></script>
</body>
</html>
