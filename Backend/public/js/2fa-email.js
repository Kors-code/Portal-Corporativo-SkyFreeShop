document.addEventListener("DOMContentLoaded", function() {
    
        const resendSection = document.getElementById("resend-section");

        // Mostrar "Reenviar OTP" después de 15 segundos
        setTimeout(() => {
            resendSection.style.display = "block";
        }, 15000);

        // Si hay alerta, desaparece suavemente en 5s
        const alertMessage = document.getElementById("alert-message");
        if(alertMessage) {
            setTimeout(() => {
                alertMessage.style.opacity = "0";
                alertMessage.style.transition = "opacity 1s ease";
            }, 5000);
        }
    });