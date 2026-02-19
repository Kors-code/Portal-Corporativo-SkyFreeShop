let formToSubmit = null;
document.getElementById("modalConfirm").style.display = "none";
document.addEventListener("DOMContentLoaded", () => {

   document.querySelectorAll('.btn-delete, .btn-restore').forEach(btn => {
    btn.addEventListener('click', function () {
        const form = this.closest('form');
        const modal = document.getElementById('modalConfirm');

        modal.style.display = 'flex';

        document.getElementById('confirmBtn').onclick = function () {
            form.submit();
        };

        document.getElementById('cancelBtn').onclick = function () {
            modal.style.display = 'none';
        };
    });
});

    // Modal de éxito
    
const modalSuccess = document.getElementById("modalSuccess");
const successBtn = document.getElementById("successBtn");

if (modalSuccess) {

    // Cerrar al dar clic en OK
    successBtn.addEventListener("click", () => {
        modalSuccess.style.display = "none";
    });

    // Cerrar automáticamente en 2.5 segundos
    setTimeout(() => {
        modalSuccess.style.display = "none";
    }, 2500);
}


});
