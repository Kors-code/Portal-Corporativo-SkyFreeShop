// public/js/showvacantes.js
(function () {
  if (window.__showVacantesInit) return;
  window.__showVacantesInit = true;

  function safeParsePopupData() {
    try {
      const els = document.querySelectorAll('[data-popup]');
      if (!els || els.length === 0) return null;
      const last = els[els.length - 1];
      const raw = last.getAttribute('data-popup');
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (err) {
      console.error('[showvacantes] fallo parseando data-popup:', err);
      return null;
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    // cargar popup desde data-popup (CSP-safe)
    try {
      const popupFromData = safeParsePopupData();
      if (popupFromData && popupFromData.type && popupFromData.text) {
        window.popupMessage = popupFromData;
        console.log('[showvacantes] popupMessage cargado desde data-popup', window.popupMessage);
      }
    } catch (err) {
      console.error('[showvacantes] error leyendo data-popup:', err);
    }

    const overlay = document.getElementById('popup-overlay');
    const loading = document.getElementById('popup-loading');
    const messageBox = document.getElementById('popup-message');
    const closeBtn = document.getElementById('popup-close');
    const form = document.getElementById('cvForm');

    if (!form) {
      console.error('[showvacantes] Form #cvForm no encontrado en el DOM.');
      return;
    }

    const submitBtn = form.querySelector('[type="submit"]') || null;
    const spinnerText = loading ? loading.querySelector('p') : null;

    function showOverlay() {
      if (!overlay) return;
      overlay.classList.remove('hidden');
      overlay.style.display = 'flex';
    }
    function hideOverlay() {
      if (!overlay) return;
      overlay.classList.add('hidden');
      overlay.style.display = '';
    }
    function showLoading(msg = 'Enviando tu solicitud, por favor espera...') {
      if (!loading || !messageBox || !closeBtn) return;
      loading.classList.remove('hidden');
      messageBox.classList.add('hidden');
      closeBtn.classList.add('hidden');
      if (spinnerText) spinnerText.textContent = msg;
    }
     function showMessage(type, text) {
  if (!loading || !messageBox || !closeBtn) return;

  loading.classList.add('hidden');
  messageBox.classList.remove('hidden');
  closeBtn.classList.remove('hidden');
  messageBox.innerHTML = '';

  // obtener overlay (ya definido o buscado)
  const overlayEl = (typeof overlay !== 'undefined' && overlay) ? overlay : document.getElementById('popup-overlay');

  // rutas de imágenes
  const successSrc = overlayEl?.dataset?.successLogo || '/imagenes/loader.gif';
  const errorSrc   = overlayEl?.dataset?.errorLogo   || '/imagenes/error.png';
  const imgSrc = (type === 'success') ? successSrc : errorSrc;

  // Crear contenedor para imagen + texto (flexbox horizontal)
  const msgRow = document.createElement('div');
  msgRow.style.display = 'flex';
  msgRow.style.alignItems = 'center';
  msgRow.style.justifyContent = 'center';
  msgRow.style.gap = '10px'; // espacio entre imagen y texto
  msgRow.style.marginBottom = '10px';

  // Imagen
  const img = document.createElement('img');
  img.src = imgSrc;
  img.alt = type === 'success' ? 'Éxito' : 'Error';
  img.style.width = '50px';
  img.style.height = '50px';
  img.style.objectFit = 'contain';

  // Texto
  const h = document.createElement('h3');
  h.style.margin = '0';
  h.textContent = text;

  // Añadir ambos al contenedor
  msgRow.appendChild(img);
  msgRow.appendChild(h);

  // Insertar contenedor completo al messageBox
  messageBox.appendChild(msgRow);

  // Texto adicional si es éxito
  if (type === 'success') {
    const p = document.createElement('p');
    p.textContent = 'Gracias por registrar tu hoja de vida en Sky Free Shop.';
    p.style.marginTop = '10px';
    p.style.textAlign = 'center';
    messageBox.appendChild(p);
  }
}



    // Si existe popupMessage (lo cargamos desde data-popup), mostrarlo
    try {
      if (window.popupMessage && window.popupMessage.type && window.popupMessage.text) {
        showOverlay();
        showMessage(window.popupMessage.type, window.popupMessage.text);
      }
    } catch (err) {
      console.error('[showvacantes] Error leyendo window.popupMessage:', err);
    }

    // Evitar doble bind
    if (form.dataset.ajaxBound === '1') return;
    form.dataset.ajaxBound = '1';

    form.addEventListener('submit', function () {
      showOverlay();
      showLoading();
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.setAttribute('aria-disabled', 'true');
      }
      // El form se envía normalmente al servidor
    });

    if (closeBtn) closeBtn.addEventListener('click', hideOverlay);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && overlay && !overlay.classList.contains('hidden')) hideOverlay();
    });
  });
})();
