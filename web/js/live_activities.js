(() => {
  const cells = new Map();

  const formatTime = (seconds) => {
    const safeSeconds = Math.max(0, Math.floor(Number(seconds) || 0));
    const hours = Math.floor(safeSeconds / 3600);
    const minutes = Math.floor((safeSeconds % 3600) / 60);
    const remainingSeconds = safeSeconds % 60;
    return [hours, minutes, remainingSeconds]
      .map((value) => String(value).padStart(2, "0"))
      .join(":");
  };

  const render = () => {
    cells.forEach((state, cell) => {
      const runningSeconds = state.status === "en_progreso"
        ? Math.floor((Date.now() - state.syncedAt) / 1000)
        : 0;
      const value = cell.querySelector(".live-elapsed");
      if (value) value.textContent = formatTime(state.elapsedSeconds + runningSeconds);
    });
  };

  const sync = async () => {
    try {
      const response = await fetch("get_activities.php", { cache: "no-store" });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const activities = await response.json();

      activities.forEach((activity) => {
        const cell = Array.from(cells.keys()).find((candidate) =>
          candidate.dataset.activityId === String(activity.id)
        );
        if (!cell) return;

        cells.set(cell, {
          elapsedSeconds: Number(activity.elapsedSeconds) || 0,
          status: activity.status,
          syncedAt: Date.now()
        });
        cell.dataset.status = activity.status;
      });
      render();
    } catch (error) {
      console.error("Error al actualizar tiempos de actividades:", error);
    }
  };

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".live-time-cell").forEach((cell) => {
      cells.set(cell, {
        elapsedSeconds: Number(cell.dataset.elapsedSeconds) || 0,
        status: cell.dataset.status,
        syncedAt: Date.now()
      });
    });
    render();
    sync();
    window.setInterval(sync, 5000);
    window.setInterval(render, 1000);
  });
})();
