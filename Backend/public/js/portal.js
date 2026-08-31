document.addEventListener("DOMContentLoaded", function () {
  const areaToggles = Array.from(document.querySelectorAll("[data-nav-area-toggle]"));
  const areaPanels = Array.from(document.querySelectorAll("[data-nav-area-panel]"));
  const modulePanel = document.querySelector("[data-nav-module-panel]");
  const portalNav = document.querySelector("[data-portal-nav]");
  const carouselButtons = Array.from(document.querySelectorAll("[data-carousel-area]"));
  const carouselSlides = Array.from(document.querySelectorAll("[data-carousel-slide]"));
  const carouselPanels = Array.from(document.querySelectorAll("[data-carousel-panel]"));
  const carouselCount = document.querySelector("[data-carousel-count]");
  const prevButton = document.querySelector("[data-carousel-prev]");
  const nextButton = document.querySelector("[data-carousel-next]");
  const guideAssistant = document.querySelector("[data-guide-assistant]");
  const guideStart = document.querySelector("[data-guide-start]");
  const guideDismiss = document.querySelector("[data-guide-dismiss]");
  const tourCard = document.querySelector("[data-tour-card]");
  const tourIcon = document.querySelector("[data-tour-icon]");
  const tourKicker = document.querySelector("[data-tour-kicker]");
  const tourTitle = document.querySelector("[data-tour-title]");
  const tourText = document.querySelector("[data-tour-text]");
  const tourProgress = document.querySelector("[data-tour-progress]");
  const tourPrev = document.querySelector("[data-tour-prev]");
  const tourNext = document.querySelector("[data-tour-next]");
  const tourClose = document.querySelector("[data-tour-close]");
  const carouselAreas = carouselButtons.map((button) => button.dataset.carouselArea).filter(Boolean);
  const tourSteps = [
    {
      icon: "fa-solid fa-map-location-dot",
      title: "Cada tarjeta es un área",
      text: "Este carrusel organiza el portal por áreas. Al elegir Comercial, Presupuesto, Analítica u otra sección, el portal cambia los accesos visibles.",
      target: ".area-carousel-cards",
      scrollTo: ".portal-stage",
    },
    {
      icon: "fa-solid fa-table-cells-large",
      title: "Módulos disponibles",
      text: "Aquí aparecen las rutas principales del área activa. Son los botones rápidos para entrar directo a los módulos más importantes.",
      target: ".featured-access",
      scrollTo: "#areas",
    },
    {
      icon: "fa-solid fa-route",
      title: "Rutas del área",
      text: "Debajo también queda la lista completa del área seleccionada. Si necesitas una ruta menos frecuente, la encuentras aquí sin mezclarla con otros equipos.",
      target: ".module-panel.active .module-grid",
      scrollTo: "#areas",
    },
    {
      icon: "fa-solid fa-chevron-down",
      title: "Navbar superior",
      text: "La barra de arriba funciona como una guía permanente: pasa el cursor o haz clic sobre un área y se despliega su lista de módulos.",
      target: ".category-bar",
      scrollTo: "#top",
      openNav: true,
    },
  ];
  let closeTimer;
  let activeCarouselIndex = 0;
  let activeTourIndex = 0;

  function cancelClose() {
    window.clearTimeout(closeTimer);
  }

  function scheduleClose() {
    cancelClose();
    closeTimer = window.setTimeout(closeModulePanel, 180);
  }

  function closeModulePanel() {
    cancelClose();
    modulePanel?.classList.remove("open");
    areaToggles.forEach((toggle) => {
      toggle.classList.remove("open");
      toggle.setAttribute("aria-expanded", "false");
    });
    areaPanels.forEach((panel) => panel.classList.remove("active"));
  }

  function activateCarouselArea(area, options = {}) {
    if (!area) return;

    const nextIndex = carouselAreas.indexOf(area);
    if (nextIndex >= 0) activeCarouselIndex = nextIndex;

    carouselButtons.forEach((button) => {
      const isActive = button.dataset.carouselArea === area;
      button.classList.toggle("active", isActive);
      button.setAttribute("aria-pressed", String(isActive));
    });

    carouselSlides.forEach((slide) => {
      slide.classList.toggle("active", slide.dataset.carouselSlide === area);
    });

    carouselPanels.forEach((panel) => {
      panel.classList.toggle("active", panel.dataset.carouselPanel === area);
    });

    const activePanel = carouselPanels.find((panel) => panel.dataset.carouselPanel === area);
    if (carouselCount && activePanel) {
      carouselCount.textContent = String(activePanel.querySelectorAll(".module-card").length);
    }

    if (options.scrollPanel) {
      document.getElementById("areas")?.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }

  function moveCarousel(direction) {
    if (!carouselAreas.length) return;
    const nextIndex = (activeCarouselIndex + direction + carouselAreas.length) % carouselAreas.length;
    activateCarouselArea(carouselAreas[nextIndex]);
  }

  function clearGuideHighlights() {
    document.querySelectorAll(".guide-highlight").forEach((element) => {
      element.classList.remove("guide-highlight");
    });
  }

  function getTourTarget(step) {
    return step?.target ? document.querySelector(step.target) : null;
  }

  function clamp(value, min, max) {
    return Math.max(min, Math.min(value, max));
  }

  function positionTourCard(step = tourSteps[activeTourIndex]) {
    if (!tourCard || tourCard.hidden) return;

    const target = getTourTarget(step);
    const margin = window.innerWidth <= 760 ? 12 : 18;
    const fallbackLeft = clamp(window.innerWidth - tourCard.offsetWidth - margin, margin, window.innerWidth - tourCard.offsetWidth - margin);
    const fallbackTop = clamp(window.innerHeight - tourCard.offsetHeight - margin, margin, window.innerHeight - tourCard.offsetHeight - margin);

    if (!(target instanceof HTMLElement)) {
      tourCard.style.setProperty("--tour-left", `${fallbackLeft}px`);
      tourCard.style.setProperty("--tour-top", `${fallbackTop}px`);
      tourCard.style.setProperty("--tour-right", "auto");
      tourCard.style.setProperty("--tour-bottom", "auto");
      tourCard.style.setProperty("--tour-arrow-left", "50%");
      tourCard.dataset.placement = "top";
      return;
    }

    const rect = target.getBoundingClientRect();
    const cardWidth = tourCard.offsetWidth || Math.min(410, window.innerWidth - margin * 2);
    const cardHeight = tourCard.offsetHeight || 190;
    const minLeft = margin;
    const maxLeft = Math.max(margin, window.innerWidth - cardWidth - margin);
    const centeredLeft = rect.left + rect.width / 2 - cardWidth / 2;
    const left = clamp(centeredLeft, minLeft, maxLeft);

    const preferredTop = rect.top - cardHeight - margin;
    const hasRoomAbove = preferredTop >= margin;
    const placeInsideLargeTarget = !hasRoomAbove && rect.height > cardHeight + margin * 3;
    const top = placeInsideLargeTarget
      ? clamp(rect.top + margin, margin, window.innerHeight - cardHeight - margin)
      : hasRoomAbove
        ? preferredTop
        : clamp(rect.bottom + margin, margin, window.innerHeight - cardHeight - margin);
    const placement = hasRoomAbove || placeInsideLargeTarget ? "top" : "bottom";
    const targetCenter = rect.left + rect.width / 2;
    const arrowLeft = clamp(targetCenter - left, 28, cardWidth - 28);

    tourCard.style.setProperty("--tour-left", `${left}px`);
    tourCard.style.setProperty("--tour-top", `${top}px`);
    tourCard.style.setProperty("--tour-right", "auto");
    tourCard.style.setProperty("--tour-bottom", "auto");
    tourCard.style.setProperty("--tour-arrow-left", `${arrowLeft}px`);
    tourCard.dataset.placement = placement;
  }

  function renderTourStep(index) {
    if (!tourCard) return;

    activeTourIndex = Math.max(0, Math.min(index, tourSteps.length - 1));
    const step = tourSteps[activeTourIndex];

    clearGuideHighlights();
    if (step.openNav) {
      openArea(carouselAreas[activeCarouselIndex] || carouselAreas[0]);
    } else {
      closeModulePanel();
    }
    document.body.classList.add("tour-active");
    tourCard.hidden = false;
    tourIcon.innerHTML = `<i class="${step.icon}"></i>`;
    tourKicker.textContent = `Paso ${activeTourIndex + 1} de ${tourSteps.length}`;
    tourTitle.textContent = step.title;
    tourText.textContent = step.text;
    tourPrev.disabled = activeTourIndex === 0;
    tourNext.innerHTML = activeTourIndex === tourSteps.length - 1
      ? 'Finalizar <i class="fa-solid fa-check"></i>'
      : 'Siguiente <i class="fa-solid fa-arrow-right"></i>';

    Array.from(tourProgress?.children ?? []).forEach((dot, dotIndex) => {
      dot.classList.toggle("active", dotIndex <= activeTourIndex);
    });

    if (step.scrollTo) {
      document.querySelector(step.scrollTo)?.scrollIntoView({ behavior: "smooth", block: "start" });
    }

    window.setTimeout(() => {
      getTourTarget(step)?.classList.add("guide-highlight");
      window.requestAnimationFrame(() => positionTourCard(step));
    }, step.scrollTo ? 380 : 20);
  }

  function openTour() {
    guideAssistant?.classList.add("hidden");
    renderTourStep(0);
  }

  function closeTour() {
    clearGuideHighlights();
    document.body.classList.remove("tour-active");
    if (tourCard) tourCard.hidden = true;
  }

  function openArea(area) {
    if (!area) return;

    const activeToggle = areaToggles.find((toggle) => toggle.dataset.navAreaToggle === area);
    const isAlreadyOpen = modulePanel?.classList.contains("open") && activeToggle?.classList.contains("open");

    activateCarouselArea(area);

    if (isAlreadyOpen) {
      closeModulePanel();
      return;
    }

    modulePanel?.classList.add("open");

    areaToggles.forEach((toggle) => {
      const isActive = toggle.dataset.navAreaToggle === area;
      toggle.classList.toggle("open", isActive);
      toggle.setAttribute("aria-expanded", String(isActive));
    });

    areaPanels.forEach((panel) => {
      panel.classList.toggle("active", panel.dataset.navAreaPanel === area);
    });
  }

  areaToggles.forEach((toggle) => {
    toggle.addEventListener("mouseenter", () => {
      cancelClose();
      openArea(toggle.dataset.navAreaToggle);
    });
    toggle.addEventListener("focus", () => {
      cancelClose();
      openArea(toggle.dataset.navAreaToggle);
    });
    toggle.addEventListener("click", () => openArea(toggle.dataset.navAreaToggle));
  });

  carouselButtons.forEach((button) => {
    button.addEventListener("click", () => {
      activateCarouselArea(button.dataset.carouselArea);
    });
  });

  prevButton?.addEventListener("click", () => moveCarousel(-1));
  nextButton?.addEventListener("click", () => moveCarousel(1));
  guideStart?.addEventListener("click", openTour);
  guideDismiss?.addEventListener("click", () => guideAssistant?.classList.add("hidden"));
  tourPrev?.addEventListener("click", () => renderTourStep(activeTourIndex - 1));
  tourNext?.addEventListener("click", () => {
    if (activeTourIndex === tourSteps.length - 1) {
      closeTour();
      return;
    }

    renderTourStep(activeTourIndex + 1);
  });
  tourClose?.addEventListener("click", closeTour);
  window.addEventListener("resize", () => {
    if (document.body.classList.contains("tour-active")) positionTourCard();
  });
  window.addEventListener("scroll", () => {
    if (document.body.classList.contains("tour-active")) positionTourCard();
  }, { passive: true });
  portalNav?.addEventListener("mouseenter", cancelClose);
  portalNav?.addEventListener("mouseleave", scheduleClose);
  modulePanel?.addEventListener("mouseenter", cancelClose);
  modulePanel?.addEventListener("mouseleave", scheduleClose);

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closeModulePanel();
    if (event.key === "Escape") closeTour();
    if (event.key === "ArrowLeft") moveCarousel(-1);
    if (event.key === "ArrowRight") moveCarousel(1);
  });

  document.addEventListener("click", (event) => {
    if (!modulePanel?.classList.contains("open")) return;
    const clickedInsideNav = event.target instanceof Element && event.target.closest("[data-portal-nav]");
    if (!clickedInsideNav) closeModulePanel();
  });

  if (carouselAreas[0]) activateCarouselArea(carouselAreas[0]);
});
