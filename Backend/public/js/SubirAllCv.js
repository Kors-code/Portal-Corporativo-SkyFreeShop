const dropArea = document.getElementById('drop-area');
const input = document.getElementById('cvs');
const preview = document.getElementById('preview');

// Evitar comportamiento por defecto
['dragenter','dragover','dragleave','drop'].forEach(eventName => {
    dropArea.addEventListener(eventName, e => e.preventDefault(), false);
    document.body.addEventListener(eventName, e => e.preventDefault(), false);
});

// Resaltar el área cuando arrastran
['dragenter','dragover'].forEach(eventName => {
    dropArea.addEventListener(eventName, () => dropArea.classList.add('highlight'), false);
});
['dragleave','drop'].forEach(eventName => {
    dropArea.addEventListener(eventName, () => dropArea.classList.remove('highlight'), false);
});

// Manejar archivos soltados
dropArea.addEventListener('drop', e => {
    let files = e.dataTransfer.files;
    input.files = files;
    mostrarPreview(files);
});

// También al seleccionar con clic
input.addEventListener('change', e => {
    mostrarPreview(e.target.files);
});

function mostrarPreview(files) {
    preview.innerHTML = "";
    for (let file of files) {
        let item = document.createElement("div");
        item.classList.add("file-item");
        item.innerHTML = `<strong>${file.name}</strong> (${Math.round(file.size/1024)} KB)`;
        preview.appendChild(item);
    }
}



