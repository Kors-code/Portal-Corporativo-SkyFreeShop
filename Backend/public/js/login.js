// login.js
document.addEventListener("DOMContentLoaded", function () {

    const input = document.getElementById("passwordInput");
    const toggle = document.getElementById("passwordToggle");

    if (!input || !toggle) return;

    // Mostrar el toggle SOLO cuando el input está enfocado o tiene texto
    function updateToggleVisibility() {
        const shouldShow = input === document.activeElement || input.value.length > 0;
        toggle.classList.toggle('visible', shouldShow);
    }

    // Al hacer click: cambiar tipo y animar el icono
    toggle.addEventListener("click", function (e) {
        e.preventDefault();
        const isPwd = input.type === "password";
        input.type = isPwd ? "text" : "password";

        // accesibilidad
        toggle.setAttribute('aria-pressed', (!isPwd).toString());

        // clase que provoca la transición eye-open <-> eye-closed
        toggle.classList.toggle('show', isPwd);

        // pequeña pulsación visual
        toggle.animate([{ transform: 'translateY(-50%) scale(0.95)' }, { transform: 'translateY(-50%) scale(1)' }], {
            duration: 160, easing: 'ease-out'
        });

        // si se activó mostrar, mantiene el cursor al final del input
        if (isPwd) {
            input.focus();
            // colocar el cursor al final (compatible)
            const val = input.value;
            input.setSelectionRange(val.length, val.length);
        }
    });

    // eventos para controlar la aparición (focus / blur / input)
    input.addEventListener('input', updateToggleVisibility);
    input.addEventListener('focus', updateToggleVisibility);
    input.addEventListener('blur', function () {
        // dejamos un ligero delay para que el click del toggle no desaparezca antes de ser recibido
        setTimeout(updateToggleVisibility, 120);
    });

    // inicializa visibilidad al cargar la página
    updateToggleVisibility();
});
