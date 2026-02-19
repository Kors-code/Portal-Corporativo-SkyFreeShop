 (function(){
      const sidebar = document.getElementById('sidebar');
      const toggleBtn = document.getElementById('toggleBtn');
      const closeBtn = document.getElementById('btnCloseSidebar');
      const backdrop = document.getElementById('backdrop');

      const isDesktop = () => window.matchMedia('(min-width: 992px)').matches;

      function openSidebar() {
        sidebar.classList.add('open');
        backdrop.classList.add('show');
        sidebar.setAttribute('aria-hidden','false');
        toggleBtn.setAttribute('aria-expanded','true');
      }
      function closeSidebar() {
        sidebar.classList.remove('open');
        backdrop.classList.remove('show');
        sidebar.setAttribute('aria-hidden','true');
        toggleBtn.setAttribute('aria-expanded','false');
      }

      // Toggle (mobile)
      toggleBtn.addEventListener('click', () => {
        if (sidebar.classList.contains('open')) closeSidebar();
        else openSidebar();
      });

      // close controls
      closeBtn && closeBtn.addEventListener('click', closeSidebar);
      backdrop.addEventListener('click', closeSidebar);

      // Close on ESC
      window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeSidebar();
      });

      // On resize: if switching to desktop, ensure sidebar is visible and backdrop removed.
      window.addEventListener('resize', () => {
        if (isDesktop()) {
          sidebar.classList.remove('open');
          backdrop.classList.remove('show');
          // keep aria consistent for desktop (sidebar visible)
          sidebar.setAttribute('aria-hidden','false');
          toggleBtn.setAttribute('aria-expanded','false');
        } else {
          // mobile: close by default
          sidebar.setAttribute('aria-hidden', sidebar.classList.contains('open') ? 'false' : 'true');
        }
      });

      // initial state: if desktop, show sidebar (no overlay)
      if (isDesktop()) {
        sidebar.setAttribute('aria-hidden','false');
      } else {
        sidebar.setAttribute('aria-hidden','true');
      }
    })();
    document.addEventListener("DOMContentLoaded", () => {
  const modal = document.getElementById("logoutModal");
  const openBtn = document.getElementById("openModalBtn");
  const closeBtn = document.getElementById("closeModalBtn");
  const cancelBtn = document.getElementById("cancelBtn");
  const confirmBtn = document.getElementById("confirmLogoutBtn");
  const logoutForm = document.getElementById("logout-form");

  // Abrir modal
  openBtn.addEventListener("click", () => {
    modal.classList.add("show");
  });

  // Cerrar modal (por botón ✕ o cancelar)
  closeBtn.addEventListener("click", () => {
    modal.classList.remove("show");
  });

  cancelBtn.addEventListener("click", () => {
    modal.classList.remove("show");
  });

  // Confirmar logout
  confirmBtn.addEventListener("click", () => {
    logoutForm.submit();
  });

  // Cerrar si clic fuera del contenido
  window.addEventListener("click", (e) => {
    if (e.target === modal) {
      modal.classList.remove("show");
    }
  });
});
