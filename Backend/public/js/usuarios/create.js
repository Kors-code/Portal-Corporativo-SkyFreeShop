 function toggleImagenes() {
      var container = document.getElementById("imagenes-container");
      container.style.display = (container.style.display === "none" || container.style.display === "") ? "block" : "none";
    }
    

    
    // Código para el Drop Zone (Drag & Drop) de la subida de imagen
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('imagen');
    const dropZoneText = document.getElementById('drop-zone-text');

    dropZone.addEventListener('click', () => {
      fileInput.click();
    });

    fileInput.addEventListener('change', () => {
      if (fileInput.files.length) {
        dropZoneText.textContent = fileInput.files[0].name;
      }
    });

    dropZone.addEventListener('dragover', (e) => {
      e.preventDefault();
      dropZone.classList.add('dragover');
    });

    dropZone.addEventListener('dragleave', (e) => {
      e.preventDefault();
      dropZone.classList.remove('dragover');
    });

    dropZone.addEventListener('drop', (e) => {
      e.preventDefault();
      dropZone.classList.remove('dragover');
      const files = e.dataTransfer.files;
      if (files.length) {
        fileInput.files = files;
        dropZoneText.textContent = files[0].name;
      }
    });