(function () {
  "use strict";
  const input = document.getElementById("title");
  const container = document.getElementById("activity-suggestions");
  if (!input || !container) return;
  let timeout;
  input.addEventListener("input", function () {
    clearTimeout(timeout);
    const query = input.value.trim();
    if (query.length < 2) {
      container.innerHTML = "";
      return;
    }
    timeout = setTimeout(async function () {
      try {
        const response = await fetch("get_activity_suggestions.php?q=" + encodeURIComponent(query), { cache: "no-store" });
        if (!response.ok) return;
        const data = await response.json();
        container.replaceChildren();
        (data.suggestions || []).forEach(function (item) {
          const button = document.createElement("button");
          button.type = "button";
          button.className = "suggestion-item";
          button.dataset.title = String(item.title || "");
          button.textContent = String(item.title || "");
          container.appendChild(button);
        });
      } catch (error) {
        console.error("No se pudieron cargar sugerencias:", error);
      }
    }, 250);
  });
  container.addEventListener("click", function (event) {
    const button = event.target.closest("[data-title]");
    if (!button) return;
    input.value = button.dataset.title;
    container.innerHTML = "";
    input.focus();
  });
})();
