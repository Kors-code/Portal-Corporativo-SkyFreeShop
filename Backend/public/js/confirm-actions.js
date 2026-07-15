document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-confirm-action').forEach((element) => {
    const form = element.tagName === 'FORM' ? element : element.closest('form');
    if (!form) return;

    form.addEventListener('submit', (event) => {
      const message = element.getAttribute('data-confirm-message') || form.getAttribute('data-confirm-message') || 'Confirmar accion?';
      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  });
});
