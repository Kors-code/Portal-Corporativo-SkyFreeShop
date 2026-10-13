<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $authUser = auth()->user();
        $authEmpleado = null;
        $authPermissions = [];
        if ($authUser) {
            $authEmpleado = \App\Http\Controllers\EntregaController::resolverEmpleadoParaUsuario($authUser);
            $authPermissions = $authUser->getAllPermissions()->pluck('name')->values();
        }
    @endphp
    <meta name="laravel-user" content='@json($authUser)'>
    <meta name="laravel-empleado" content='@json($authEmpleado)'>
    <meta name="laravel-permissions" content='@json($authPermissions)'>

    @php
        $useViteDevServer = app()->environment('local') && request()->boolean('vite');
        $viteDevServer = rtrim(env('VITE_DEV_SERVER_URL', 'http://127.0.0.1:5173'), '/');
        $manifestPath = public_path('react/manifest.json');
        $viteManifestPath = public_path('react/.vite/manifest.json');
        if (! $useViteDevServer && file_exists($viteManifestPath)) {
            $manifestPath = $viteManifestPath;
        }
        if (! $useViteDevServer && !file_exists($manifestPath)) {
            die('manifest.json no encontrado en public/react');
        }
        $manifest = $useViteDevServer ? [] : json_decode(file_get_contents($manifestPath), true);
        $entry = $manifest['index.html'] ?? [];
    @endphp

    @if($useViteDevServer)
        <script type="module">
            import RefreshRuntime from "{{ $viteDevServer }}/panel/@@react-refresh";
            RefreshRuntime.injectIntoGlobalHook(window);
            window.$RefreshReg$ = () => {};
            window.$RefreshSig$ = () => (type) => type;
            window.__vite_plugin_react_preamble_installed__ = true;
        </script>
        <script type="module" src="{{ $viteDevServer }}/panel/@@vite/client"></script>
        <script type="module" src="{{ $viteDevServer }}/panel/src/main.tsx"></script>
    @elseif(isset($entry['css']))
        @foreach($entry['css'] as $css)
            <link rel="stylesheet" href="{{ asset('react/'.$css) }}?v={{ filemtime(public_path('react/'.$css)) }}">
        @endforeach
    @endif
</head>
<body>
    <div id="root"></div>

    @unless($useViteDevServer)
        <script type="module" src="{{ asset('react/'.$entry['file']) }}?v={{ filemtime(public_path('react/'.$entry['file'])) }}"></script>
    @endunless
</body>
</html>
