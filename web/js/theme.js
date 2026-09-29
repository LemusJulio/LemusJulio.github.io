(() => {
  const savedTheme = localStorage.getItem("actividades-theme");
  const preferredTheme = "dark";
  const theme = savedTheme || preferredTheme;

  document.documentElement.dataset.theme = theme;

  document.addEventListener("DOMContentLoaded", () => {
    const button = document.getElementById("theme-toggle");
    if (!button) return;

    const updateButton = () => {
      const darkMode = document.documentElement.dataset.theme === "dark";
      button.textContent = darkMode ? "☀️ Modo claro" : "🌙 Modo oscuro";
      button.setAttribute("aria-pressed", darkMode ? "true" : "false");
    };

    updateButton();
    button.addEventListener("click", () => {
      const nextTheme = document.documentElement.dataset.theme === "dark" ? "light" : "dark";
      document.documentElement.dataset.theme = nextTheme;
      localStorage.setItem("actividades-theme", nextTheme);
      updateButton();
    });
  });
})();
