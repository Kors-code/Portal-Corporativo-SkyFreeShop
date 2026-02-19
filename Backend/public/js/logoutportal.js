document.addEventListener("DOMContentLoaded", function () {
    const modal = document.getElementById("logoutModal");
    const openBtn = document.getElementById("openLogoutModal");
    const cancelBtn = document.getElementById("cancelLogout");
    const confirmBtn = document.getElementById("confirmLogout");
    const logoutForm = document.getElementById("logout-form");

    openBtn.addEventListener("click", () => modal.classList.add("show"));
    cancelBtn.addEventListener("click", () => modal.classList.remove("show"));

    modal.addEventListener("click", (e) => {
        if (e.target === modal) modal.classList.remove("show");
    });

    confirmBtn.addEventListener("click", () => logoutForm.submit());
});
