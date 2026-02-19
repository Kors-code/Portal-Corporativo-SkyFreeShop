<h1>Registrar Candidato</h1>

<form method="POST" action="{{ route('candidatos.store') }}" enctype="multipart/form-data">
    @csrf
    <input name="nombre" placeholder="Nombre" />
    <input name="email" type="email" placeholder="Email" />
    <input name="cv" type="file" />
    <button type="submit">Guardar</button>
</form>
