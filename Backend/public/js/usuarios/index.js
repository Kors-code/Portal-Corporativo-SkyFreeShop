document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-profile-row').forEach((row) => {
    row.addEventListener('click', (event) => {
      if (event.target.closest('a, button, form, input')) return;
      const url = row.getAttribute('data-profile-url');
      if (url) window.location.href = url;
    });
  });

  document.querySelectorAll('.js-confirm-delete').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm('Eliminar?')) {
        event.preventDefault();
      }
    });
  });
});
