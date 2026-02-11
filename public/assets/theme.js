(function () {
  const THEME_KEY = "receipt_theme";

  function getPreferredTheme() {
    try {
      const saved = localStorage.getItem(THEME_KEY);
      if (saved === "light" || saved === "dark") {
        return saved;
      }
    } catch (error) {
      // ignore storage errors
    }

    if (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) {
      return "dark";
    }
    return "light";
  }

  function updateToggle(theme) {
    const toggle = document.getElementById("themeToggle");
    if (!toggle) return;
    const isDark = theme === "dark";
    toggle.textContent = isDark ? "Light mode" : "Dark mode";
    toggle.setAttribute("aria-pressed", isDark ? "true" : "false");
  }

  function applyTheme(theme) {
    const isDark = theme === "dark";
    document.body.classList.toggle("dark", isDark);
    updateToggle(theme);
  }

  function setTheme(theme) {
    applyTheme(theme);
    try {
      localStorage.setItem(THEME_KEY, theme);
    } catch (error) {
      // ignore storage errors
    }
  }

  function bindToggle() {
    const toggle = document.getElementById("themeToggle");
    if (!toggle || toggle.dataset.themeBound === "1") {
      return;
    }
    toggle.dataset.themeBound = "1";
    toggle.addEventListener("click", function () {
      const isDark = document.body.classList.contains("dark");
      setTheme(isDark ? "light" : "dark");
    });
  }

  function initTheme() {
    const theme = getPreferredTheme();
    applyTheme(theme);
    bindToggle();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initTheme);
  } else {
    initTheme();
  }

  window.ReceiptTheme = {
    get: getPreferredTheme,
    set: setTheme,
    apply: applyTheme,
  };
})();
