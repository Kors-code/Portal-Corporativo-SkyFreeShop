document.addEventListener("DOMContentLoaded", function () {
    // Selecciona el botón de "Cerrar sesión" DENTRO DEL MODAL
    const confirmLogoutButton = document.getElementById('confirm-logout-btn');
    

    // Verifica que el botón exista antes de añadir el listener
    if (confirmLogoutButton) {
        confirmLogoutButton.addEventListener('click', function () {
            // Envía el formulario de cierre de sesión cuando se confirma en el modal
            document.getElementById('logout-form').submit();
        });
    } else {
        console.error("Error: El botón de confirmación de cierre de sesión ('confirm-logout-btn') no fue encontrado.");
    }
});
