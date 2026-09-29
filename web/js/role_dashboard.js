(function () {
  "use strict";

  const metricsContainer = document.getElementById("role-dashboard-metrics");
  if (!metricsContainer) return;

  const isLeader = document.getElementById("team-live-activities") !== null;
  const error = document.getElementById("role-dashboard-error");
  const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;"
  }[character]));
  const formatTime = (value) => {
    const seconds = Math.max(0, Math.floor(Number(value) || 0));
    return [Math.floor(seconds / 3600), Math.floor((seconds % 3600) / 60), seconds % 60]
      .map((part) => String(part).padStart(2, "0"))
      .join(":");
  };

  function metric(label, value, accent) {
    return `<article class="role-kpi-card${accent ? " role-kpi-accent" : ""}"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></article>`;
  }

  function renderLeader(data) {
    const values = data.metrics || {};
    metricsContainer.innerHTML = [
      metric("En progreso", values.en_progreso || 0, true),
      metric("Pausadas", values.pausadas || 0),
      metric("Pendientes", values.pendientes || 0),
      metric("Completadas", values.completadas || 0)
    ].join("");

    const assigned = Number(values.activity_types?.asignada) || 0;
    const spontaneous = Number(values.activity_types?.espontanea) || 0;
    const activityTotal = assigned + spontaneous;
    const assignedShare = activityTotal ? assigned / activityTotal * 100 : 0;
    document.getElementById("team-activity-chart").style.background = activityTotal
      ? `conic-gradient(var(--secondary-color) 0 ${assignedShare}%, var(--accent-color) ${assignedShare}% 100%)`
      : "var(--surface-muted)";
    document.getElementById("team-activity-legend").innerHTML =
      `<span><i class="legend-assigned"></i>Asignadas: ${assigned} (${Math.round(assignedShare)}%)</span>` +
      `<span><i class="legend-spontaneous"></i>Espontáneas: ${spontaneous} (${activityTotal ? Math.round(spontaneous / activityTotal * 100) : 0}%)</span>`;
    const distribution = values.time_distribution_percent || {};
    const worked = Number(distribution.worked) || 0;
    const dead = Number(distribution.dead) || 0;
    document.getElementById("team-time-chart").style.background =
      `conic-gradient(var(--secondary-color) 0 ${worked}%, var(--warning-color) ${worked}% ${worked + dead}%, var(--surface-muted) ${worked + dead}% 100%)`;
    document.getElementById("team-time-legend").innerHTML =
      `<span><i class="legend-assigned"></i>Trabajado: ${worked}%</span>` +
      `<span><i class="legend-dead-time"></i>Tiempo muerto: ${dead}%</span>`;

    const activities = (data.teamActivities || []).slice().sort((first, second) => {
      const order = { en_progreso: 0, pausada: 1, pendiente: 2, completada: 3 };
      return (order[first.status] ?? 4) - (order[second.status] ?? 4);
    });
    const list = document.getElementById("team-live-activities");
    const count = document.getElementById("leader-team-activities-count");
    if (count) count.textContent = `${activities.length} ${activities.length === 1 ? "actividad de hoy" : "actividades de hoy"}`;
    const statusLabels = { en_progreso: "En progreso", pausada: "Pausada", pendiente: "Pendiente", completada: "Completada" };
    list.innerHTML = activities.length
      ? activities.slice(0, 12).map((activity) => `
        <article class="team-activity-card">
          <div class="team-activity-main">
            <span class="activity-status status-${escapeHtml(activity.status)}">${escapeHtml(statusLabels[activity.status] || activity.status)}</span>
            ${activity.isOverdue ? '<span class="team-overdue-badge">Vencida</span>' : ""}
            <h3>${escapeHtml(activity.title || "Sin título")}</h3>
            <p>${escapeHtml(activity.assignedTo || "Sin asignar")} · ${escapeHtml(activity.project || "Sin proyecto")} · Prioridad ${escapeHtml(activity.priority || "media")}</p>
          </div>
          <div class="team-activity-meta">
            <span>${escapeHtml(activity.activityType === "espontanea" ? "Espontánea" : "Asignada")}</span>
            <strong>${formatTime(activity.elapsedSeconds)}</strong>
          </div>
        </article>`).join("")
      : '<div class="empty-state"><h3>Sin actividad del equipo hoy</h3><p>Las actividades de otras personas aparecerán aquí.</p></div>';
  }

  function renderBoss(data) {
    const values = data.metrics || {};
    metricsContainer.innerHTML = [
      metric("Total de hoy", values.total || 0),
      metric("En progreso", values.en_progreso || 0, true),
      metric("Pausadas", values.pausadas || 0),
      metric("Pendientes de completar", values.pendientes || 0),
      metric("Completadas", values.completadas || 0),
      metric("Tiempo trabajado", formatTime(values.total_elapsed_seconds || 0)),
      metric("Tiempo muerto", formatTime(values.total_dead_time_seconds || 0))
    ].join("");

    const assigned = Number(values.activity_types?.asignada) || 0;
    const spontaneous = Number(values.activity_types?.espontanea) || 0;
    const activityTotal = assigned + spontaneous;
    const assignedShare = activityTotal ? assigned / activityTotal * 100 : 0;
    const activityChart = document.getElementById("executive-activity-chart");
    activityChart.style.background = activityTotal
      ? `conic-gradient(var(--secondary-color) 0 ${assignedShare}%, var(--accent-color) ${assignedShare}% 100%)`
      : "var(--surface-muted)";
    document.getElementById("executive-activity-legend").innerHTML =
      `<span><i class="legend-assigned"></i>Asignadas: ${assigned} (${Math.round(assignedShare)}%)</span>` +
      `<span><i class="legend-spontaneous"></i>Espontáneas: ${spontaneous} (${activityTotal ? Math.round(spontaneous / activityTotal * 100) : 0}%)</span>`;

    const distribution = values.time_distribution_percent || {};
    const worked = Number(distribution.worked) || 0;
    const dead = Number(distribution.dead) || 0;
    document.getElementById("executive-time-chart").style.background =
      `conic-gradient(var(--secondary-color) 0 ${worked}%, var(--warning-color) ${worked}% ${worked + dead}%, var(--surface-muted) ${worked + dead}% 100%)`;
    document.getElementById("executive-time-legend").innerHTML =
      `<span><i class="legend-assigned"></i>Trabajado: ${worked}%</span>` +
      `<span><i class="legend-dead-time"></i>Tiempo muerto: ${dead}%</span>`;
  }

  async function refresh() {
    try {
      const response = await fetch("get_dashboard.php", { cache: "no-store" });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json();
      if (isLeader) renderLeader(data);
      else renderBoss(data);
      if (error) error.hidden = true;
    } catch (exception) {
      if (error) error.hidden = false;
      console.error("No se pudo actualizar el dashboard del rol:", exception);
    }
  }

  refresh();
  window.setInterval(refresh, 5000);
})();
