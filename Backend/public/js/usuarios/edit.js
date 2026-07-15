document.addEventListener('DOMContentLoaded', () => {
  const toggleButton = document.getElementById('toggle-imagenes');
  const container = document.getElementById('imagenes-container');

  toggleButton?.addEventListener('click', () => {
    if (!container) return;
    container.style.display = (container.style.display === 'none' || container.style.display === '') ? 'block' : 'none';
  });

  const dropZone = document.getElementById('drop-zone');
  const fileInput = document.getElementById('imagen');
  const dropZoneText = document.getElementById('drop-zone-text');

  if (!dropZone || !fileInput || !dropZoneText) return;

  dropZone.addEventListener('click', () => {
    fileInput.click();
  });

  fileInput.addEventListener('change', () => {
    if (fileInput.files.length) {
      dropZoneText.textContent = fileInput.files[0].name;
    }
  });

  dropZone.addEventListener('dragover', (event) => {
    event.preventDefault();
    dropZone.classList.add('dragover');
  });

  dropZone.addEventListener('dragleave', (event) => {
    event.preventDefault();
    dropZone.classList.remove('dragover');
  });

  dropZone.addEventListener('drop', (event) => {
    event.preventDefault();
    dropZone.classList.remove('dragover');
    const files = event.dataTransfer.files;
    if (files.length) {
      fileInput.files = files;
      dropZoneText.textContent = files[0].name;
    }
  });
});
