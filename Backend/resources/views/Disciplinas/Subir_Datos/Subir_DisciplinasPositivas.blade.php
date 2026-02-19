<h3>📤 Cargar archivo Excel de Llamados de Atención</h3>

<form action="{{ route('llamados.importar') }}" method="POST" enctype="multipart/form-data" style="margin-top: 1rem;">
    @csrf

    <div style="margin-bottom: 1rem;">
        <label for="archivo_excel"><strong>Seleccionar archivo Excel (.xlsx o .csv)</strong></label><br>
        <input type="file" name="archivo_excel" id="archivo_excel" required accept=".xlsx,.csv">
    </div>

    <button type="submit" class="btn"
        style="background-color: #840028; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px;">
        📥 Subir archivo
    </button>
</form>

@if (session('import_success'))
    <div class="alert alert-success" style="margin-top: 1rem;">
        ✅ {{ session('import_success') }}
    </div>
@endif

@if (session('import_errors'))
    <div class="alert alert-danger" style="margin-top: 1rem;">
        ⚠️ Se encontraron errores en algunas filas:
        <ul>
            @foreach (session('import_errors') as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

