
document.addEventListener('DOMContentLoaded', () => {
        const toggleBtn = document.getElementById("toggleBtn");
    const btnGroup = document.getElementById("btnGroup");

    if (btnGroup) {
        btnGroup.style.width = "0";
    }
    
    let abierto = false;
    toggleBtn.addEventListener("click", () => {
      if (abierto) {
        btnGroup.style.width = "0";
        toggleBtn.innerText = "X";
      } else {
        btnGroup.style.width = "400px"; // Ajusta el ancho que quieras
        toggleBtn.innerText = "→";
      }
      abierto = !abierto;
    });
    const toggleStoreRoute = document
        .querySelector('meta[name="app-config-toggle-store-route"]')
        .content;
    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        .content;

    function saveToggle(key, value) {
        return fetch(toggleStoreRoute, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({ key, value })
        });
    }


    document.querySelectorAll('.toggle-button').forEach(btn => {
        btn.addEventListener('click', function () {
            this.classList.toggle('active');
        });
    });

    function enviarFormulario() {
        const contadorOn = document.getElementById('myToggleButton').classList.contains('active');
        const cajeroOn = document.getElementById('ToggleButtoncajero').classList.contains('active');
        const ventasOn = document.getElementById('ToggleButtonventas').classList.contains('active');

        document.getElementById('contador').value = contadorOn ? '1' : '0';
        document.getElementById('cajero').value = cajeroOn ? '1' : '0';
        document.getElementById('ventas').value = ventasOn ? '1' : '0';

        Promise.all([
            saveToggle('contador', contadorOn),
            saveToggle('cajero', cajeroOn),
            saveToggle('ventas', ventasOn)
        ])
            .then(() => {
                document.getElementById('form').submit();
            })
            .catch(err => {
                console.error('Error guardando toggles:', err);
                document.getElementById('form').submit(); // igual lo enviamos
            });
    }

    const aplicarBtn = document.getElementById('aplicarFiltrosBtn');
    if (aplicarBtn) {
        aplicarBtn.addEventListener('click', enviarFormulario);
    }
});
