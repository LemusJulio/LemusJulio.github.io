(function () {
  "use strict";

  const list = document.getElementById("boss-notifications-list");
  const count = document.getElementById("boss-notifications-count");
  if (!list || !count) return;

  let newestTimestamp = Number(localStorage.getItem("bossNotificationsSince") || 0);
  const seenIds = new Set(JSON.parse(localStorage.getItem("bossNotificationsSeen") || "[]"));

  function escapeHtml(value) {
    const element = document.createElement("div");
    element.textContent = value == null ? "" : String(value);
    return element.innerHTML;
  }

  function render(notifications) {
    count.textContent = String(notifications.length);
    if (!notifications.length) {
      list.innerHTML = "<p class=\"notifications-empty\">No hay novedades recientes.</p>";
      return;
    }

    list.innerHTML = notifications.slice().reverse().map(function (notification) {
      return "<article class=\"boss-notification-item\">" +
        "<strong>" + escapeHtml(notification.message) + "</strong>" +
        "<time datetime=\"" + escapeHtml(notification.createdAt || "") + "\">" +
        escapeHtml(notification.createdAt || "") + "</time>" +
        "</article>";
    }).join("");
  }

  async function loadNotifications() {
    try {
      const response = await fetch("get_notifications.php?since=" + encodeURIComponent(newestTimestamp), {
        cache: "no-store"
      });
      if (!response.ok) throw new Error("No se pudieron consultar las notificaciones.");
      const data = await response.json();
      const notifications = Array.isArray(data.notifications) ? data.notifications : [];

      notifications.forEach(function (notification) {
        const timestamp = Number(notification.createdAtUnix) || 0;
        if (timestamp > newestTimestamp) newestTimestamp = timestamp;
        if (notification.id && !seenIds.has(notification.id)) {
          seenIds.add(notification.id);
          if (typeof showNotification === "function") {
            showNotification(notification.message, "success");
          }
        }
      });

      localStorage.setItem("bossNotificationsSince", String(newestTimestamp));
      localStorage.setItem("bossNotificationsSeen", JSON.stringify(Array.from(seenIds).slice(-200)));
      render(notifications);
    } catch (error) {
      console.error("Error al cargar notificaciones del jefe:", error);
    }
  }

  loadNotifications();
  window.setInterval(loadNotifications, 5000);
})();
