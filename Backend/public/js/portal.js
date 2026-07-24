document.addEventListener("DOMContentLoaded", function () {
  const options = Array.from(document.querySelectorAll("[data-area-target]"));
  const panels = Array.from(document.querySelectorAll("[data-area-panel]"));
  const showcaseButtons = Array.from(document.querySelectorAll("[data-showcase-trigger]"));
  const showcasePanels = Array.from(document.querySelectorAll("[data-showcase-panel]"));

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
});
