<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="laravel-user" content='@json(auth()->user())'>

    @php
        $manifestPath = public_path('react/manifest.json');
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
