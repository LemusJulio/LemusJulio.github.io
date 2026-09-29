(function () {
  "use strict";

  function labelTableCells() {
    document.querySelectorAll("table").forEach(function (table) {
      const headings = Array.from(table.querySelectorAll("thead th")).map(function (heading) {
        return heading.textContent.trim();
      });
      if (!headings.length) return;

      table.classList.add("table-to-cards");
      table.querySelectorAll("tbody tr").forEach(function (row) {
        const cells = Array.from(row.children).filter(function (cell) {
          return cell.matches("td");
        });
        if (cells.length === 1 && cells[0].colSpan > 1) return;
        cells.forEach(function (cell, index) {
          if (!cell.hasAttribute("data-label")) {
            cell.dataset.label = headings[index] || "";
          }
        });
      });
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", labelTableCells, { once: true });
  } else {
    labelTableCells();
  }

  const observer = new MutationObserver(labelTableCells);
  observer.observe(document.documentElement, { childList: true, subtree: true });

  document.querySelectorAll(".container").forEach(function (container) {
    if (container.firstElementChild && container.firstElementChild.classList.contains("sidebar")) {
      container.classList.add("app-shell");
    }
  });
})();
