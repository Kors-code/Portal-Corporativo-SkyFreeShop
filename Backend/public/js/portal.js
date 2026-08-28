document.addEventListener("DOMContentLoaded", function () {
  const options = Array.from(document.querySelectorAll("[data-area-target]"));
  const panels = Array.from(document.querySelectorAll("[data-area-panel]"));
  const showcaseButtons = Array.from(document.querySelectorAll("[data-showcase-trigger]"));
  const showcasePanels = Array.from(document.querySelectorAll("[data-showcase-panel]"));
  const moduleToggle = document.querySelector("[data-module-toggle]");
  const moduleDrawer = document.querySelector("[data-module-drawer]");
  const moduleSearch = document.querySelector("[data-module-search]");
  const moduleLinks = Array.from(document.querySelectorAll("[data-module-link]"));
  const moduleGroups = Array.from(document.querySelectorAll("[data-module-group]"));
  const moduleEmpty = document.querySelector("[data-module-empty]");

  function activateArea(area) {
    options.forEach((option) => {
      option.classList.toggle("active", option.dataset.areaTarget === area);
    });

    panels.forEach((panel) => {
      panel.classList.toggle("active", panel.dataset.areaPanel === area);
    });
  }

  function activateShowcase(group) {
    showcaseButtons.forEach((button) => {
      button.classList.toggle("active", button.dataset.showcaseTrigger === group);
    });

    showcasePanels.forEach((panel) => {
      panel.classList.toggle("active", panel.dataset.showcasePanel === group);
    });
  }

  options.forEach((option) => {
    option.addEventListener("click", () => activateArea(option.dataset.areaTarget));
  });

  showcaseButtons.forEach((button) => {
    button.addEventListener("click", () => activateShowcase(button.dataset.showcaseTrigger));
  });

  if (showcaseButtons[0]) {
    activateShowcase(showcaseButtons[0].dataset.showcaseTrigger);
  }

  function closeModuleDrawer() {
    moduleDrawer?.classList.remove("open");
    moduleToggle?.classList.remove("open");
    moduleToggle?.setAttribute("aria-expanded", "false");
  }

  function openModuleDrawer() {
    moduleDrawer?.classList.add("open");
    moduleToggle?.classList.add("open");
    moduleToggle?.setAttribute("aria-expanded", "true");
  }

  function filterModules(value) {
    const query = value.trim().toLowerCase();
    let visibleCount = 0;

    moduleLinks.forEach((link) => {
      const matches = !query || (link.dataset.searchText || "").includes(query);
      link.hidden = !matches;
      if (matches) visibleCount += 1;
    });

    moduleGroups.forEach((group) => {
      const hasVisibleLinks = Array.from(group.querySelectorAll("[data-module-link]")).some((link) => !link.hidden);
      group.hidden = !hasVisibleLinks;
    });

    moduleEmpty?.classList.toggle("show", visibleCount === 0);
  }

  moduleToggle?.addEventListener("click", () => {
    const isOpen = moduleDrawer?.classList.contains("open");

    if (isOpen) {
      closeModuleDrawer();
      return;
    }

    openModuleDrawer();
    window.setTimeout(() => moduleSearch?.focus(), 80);
  });

  moduleSearch?.addEventListener("input", (event) => {
    filterModules(event.target.value);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      closeModuleDrawer();
    }
  });

  document.addEventListener("click", (event) => {
    if (!moduleDrawer?.classList.contains("open")) return;
    const clickedInsideNav = event.target instanceof Element && event.target.closest("[data-portal-nav]");
    if (!clickedInsideNav) closeModuleDrawer();
  });
});
