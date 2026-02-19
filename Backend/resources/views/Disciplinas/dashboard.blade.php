@extends('layouts.dashboard')

@section('title', 'Panel Principal')

@section('content')
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 25px;">
    <div style="background: white; padding: 25px; border-radius: 15px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); text-align: center;">
        <h2>📋 Disciplinas Positivas</h2>
        <p>Consulta, exporta o filtra registros de disciplinas.</p>
        <a href="{{ route('Disciplinas.list') }}" class="btn" style="background:#840028; color:white; text-decoration:none; padding:10px 16px; border-radius:8px;">Ir al módulo</a>
    </div>

    <div style="background: white; padding: 25px; border-radius: 15px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); text-align: center;">
        <h2>👨‍💼 Empleados</h2>
        <p>Lista completa de colaboradores registrados.</p>
        <a href="{{ route('empleados.list') }}" class="btn" style="background:#840028; color:white; text-decoration:none; padding:10px 16px; border-radius:8px;">Ver empleados</a>
    </div>

    <div style="background: white; padding: 25px; border-radius: 15px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); text-align: center;">
        <h2>📤 Importar Excel</h2>
        <p>Sube archivos Excel con nuevos registros.</p>
        <a href="{{ route('excel.form') }}" class="btn" style="background:#840028; color:white; text-decoration:none; padding:10px 16px; border-radius:8px;">Importar</a>
    </div>

    <div style="background: white; padding: 25px; border-radius: 15px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); text-align: center;">
        <h2>🧾 Formulario PDF</h2>
        <p>Genera documentos PDF personalizados.</p>
        <a href="{{ route('form.show') }}" class="btn" style="background:#840028; color:white; text-decoration:none; padding:10px 16px; border-radius:8px;">Abrir</a>
    </div>

    <div style="background: white; padding: 25px; border-radius: 15px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); text-align: center;">
        <h2>📦 Exportaciones</h2>
        <p>Descarga datos de empleados o disciplinas en Excel.</p>
        <a href="{{ route('disciplinas.export') }}" class="btn" style="background:#840028; color:white; text-decoration:none; padding:10px 16px; border-radius:8px;">Exportar</a>
    </div>
</div>
@endsection
