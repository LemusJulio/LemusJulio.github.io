// ============================================
// App.js - Lógica de la interfaz de usuario
// Solo maneja la UI y envía solicitudes al backend
// ============================================

// --- Constantes ---
const ACTIVITY_STATUS = {
  PENDING: "pendiente",
  STARTED: "en_progreso",
  PAUSED: "pausada",
  COMPLETED: "completada"
};

function escapeHtml(value) {
  const element = document.createElement("div");
  element.textContent = value == null ? "" : String(value);
  return element.innerHTML;
}

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.content || "";
}

// --- Estado de la UI ---
const UIState = {
  currentActivityId: null,
  timerInterval: null,
  activityRefreshInterval: null,
  activityFetchFailed: false,
  startTime: null,
  elapsedSeconds: 0,
  isTimerRunning: false,
  recognition: null,
  isListening: false,
  currentVoiceTarget: null,

  init() {
    this.setupEventListeners();
    this.setupSpeechRecognition();
    this.startActivityRefresh();
    this.loadInitialData();
  },

  startActivityRefresh() {
    const activitiesLists = document.querySelectorAll("#activities-list, [data-activities-list]");
    if (!activitiesLists.length) return;

    if (this.activityRefreshInterval) {
      clearInterval(this.activityRefreshInterval);
    }
    this.activityRefreshInterval = setInterval(() => {
      this.fetchActivities();
    }, 5000);
  },

  async loadInitialData() {
    // Cargar actividades y estado inicial desde el backend
    await this.fetchTimerStatus();
    await this.fetchActivities();
  },

  setupSpeechRecognition() {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) {
      const micButtons = document.querySelectorAll(".btn-mic");
      micButtons.forEach(btn => {
        btn.disabled = true;
        btn.textContent = "🎤 No soportado";
      });
      return;
    }

    this.recognition = new SpeechRecognition();
    this.recognition.lang = "es-ES";
    this.recognition.continuous = true;
    this.recognition.interimResults = true;

    this.recognition.onresult = (event) => {
      let finalTranscript = "";
      let interimTranscript = "";

      for (let i = event.resultIndex; i < event.results.length; i++) {
        const transcript = event.results[i][0].transcript;
        if (event.results[i].isFinal) {
          finalTranscript += transcript;
        } else {
          interimTranscript += transcript;
        }
      }

      const target = this.currentVoiceTarget;
      if (!target) return;

      const field = document.getElementById(target);
      if (!field) return;

      if (finalTranscript) {
        field.value = (field.value ? field.value + " " : "") + finalTranscript.trim();
        field.dispatchEvent(new Event("input", { bubbles: true }));
      }

      if (interimTranscript) {
        field.placeholder = interimTranscript;
      }
    };

    this.recognition.onerror = (event) => {
      this.isListening = false;
      this.updateMicButtons();
      showNotification("Error al reconocer voz: " + event.error, "error");
    };

    this.recognition.onend = () => {
      this.isListening = false;
      this.updateMicButtons();
    };

    const micTitle = document.getElementById("mic-title");
    const micDescription = document.getElementById("mic-description");
    if (micTitle) micTitle.disabled = false;
    if (micDescription) micDescription.disabled = false;

    if (micTitle) {
      micTitle.addEventListener("click", () => {
        this.toggleVoiceInput("title", micTitle);
      });
    }
    if (micDescription) {
      micDescription.addEventListener("click", () => {
        this.toggleVoiceInput("description", micDescription);
      });
    }
  },

  toggleVoiceInput(target, button) {
    if (!this.recognition) return;

    if (this.isListening && this.currentVoiceTarget === target) {
      this.recognition.stop();
      return;
    }

    if (this.isListening) {
      this.recognition.stop();
    }

    this.currentVoiceTarget = target;
    this.recognition.start();
    this.isListening = true;
    this.updateMicButtons();
  },

  updateMicButtons() {
    const micTitle = document.getElementById("mic-title");
    const micDescription = document.getElementById("mic-description");

    [micTitle, micDescription].forEach(btn => {
      if (!btn) return;
      if (this.isListening && btn.id === `mic-${this.currentVoiceTarget}`) {
        btn.classList.add("listening");
        btn.textContent = "⏹ Detener";
      } else {
        btn.classList.remove("listening");
        btn.textContent = btn.id === "mic-title" ? "🎤 Hablar título" : "🎤 Hablar descripción";
      }
    });
  },

  setupEventListeners() {
    // Botón de logout
    const logoutBtn = document.getElementById("logout-btn");
    if (logoutBtn) {
      logoutBtn.addEventListener("click", (e) => {
        e.preventDefault();
        window.location.href = "logout.php";
      });
    }

    // Botones de acciones (se delegarán)
    document.addEventListener("click", (e) => {
      const action = e.target.closest("[data-action]")?.dataset.action;
      if (!action) return;

      const activityId = e.target.closest("[data-activity-id]")?.dataset.activityId;
      if (!activityId) return;

      switch (action) {
        case "start":
          this.startActivity(activityId);
          break;
        case "pause":
          this.pauseActivity(activityId);
          break;
        case "resume":
          this.resumeActivity(activityId);
          break;
        case "stop":
          this.stopActivity(activityId);
          break;
      }
    });
  },

  // --- Solicitudes al Backend ---
  async fetchActivities() {
    try {
      const activitiesLists = document.querySelectorAll("#activities-list, [data-activities-list]");
      for (const container of activitiesLists) {
        const scope = container.dataset.activityScope === "own" ? "?scope=own" : "";
        const response = await fetch(`get_activities.php${scope}`, { cache: "no-store" });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const activities = await response.json();
        this.renderActivities(activities, container);
      }
      this.activityFetchFailed = false;
    } catch (error) {
      console.error("Error al cargar actividades:", error);
      if (!this.activityFetchFailed) {
        showNotification("No se pudieron actualizar las actividades. Se reintentará automáticamente.", "error");
        this.activityFetchFailed = true;
      }
    }
  },

  async fetchTimerStatus() {
    try {
      const response = await fetch("get_timer_status.php");
      const data = await response.json();
      if (data.active) {
        this.currentActivityId = data.activityId;
        this.elapsedSeconds = Number(data.elapsedSeconds) || 0;
        this.isTimerRunning = data.running === true;
        this.startTime = this.isTimerRunning ? Date.now() : null;
        if (this.isTimerRunning) this.startTicker();
        else this.stopTicker();
      } else {
        this.currentActivityId = null;
        this.startTime = null;
        this.elapsedSeconds = 0;
        this.isTimerRunning = false;
        this.stopTicker();
      }
      this.renderTimer();
    } catch (error) {
      console.error("Error al cargar estado del cronómetro:", error);
    }
  },

  async refreshTimerState() {
    await this.fetchTimerStatus();
    this.renderTimer();
  },

  async startActivity(activityId) {
    try {
      const response = await fetch("timer_actions.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ action: "start", activityId, csrf_token: csrfToken() })
      });
      const data = await response.json();
      if (data.success) {
        this.currentActivityId = activityId;
        this.startTime = Date.now();
        this.elapsedSeconds = Number(data.elapsedSeconds) || 0;
        this.isTimerRunning = true;
        this.startTicker();
        this.renderTimer();
        await this.fetchActivities();
        showNotification("Actividad iniciada", "success");
      } else {
        showNotification(data.message || "Error al iniciar actividad", "error");
      }
    } catch (error) {
      console.error("Error al iniciar actividad:", error);
      showNotification("Error de conexión", "error");
    }
  },

  async pauseActivity(activityId) {
    const pauseDetails = await this.requestPauseDetails();
    if (!pauseDetails) return;
    try {
      const response = await fetch("timer_actions.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          action: "pause",
          activityId,
          pauseReason: pauseDetails.reason,
          pauseNote: pauseDetails.note,
          csrf_token: csrfToken()
        })
      });
      const data = await response.json();
      if (data.success) {
        this.stopTicker();
        this.elapsedSeconds = Number(data.elapsedSeconds) || this.elapsedSeconds;
        this.startTime = null;
        this.isTimerRunning = false;
        await this.refreshTimerState();
        await this.fetchActivities();
        showNotification("Actividad pausada", "success");
      } else {
        showNotification(data.message || "No se pudo pausar la actividad.", "error");
      }
    } catch (error) {
      console.error("Error al pausar actividad:", error);
    }
  },

  requestPauseDetails() {
    return new Promise((resolve) => {
      const previousFocus = document.activeElement;
      const modal = document.createElement("div");
      modal.className = "notification-modal pause-reason-modal";
      modal.setAttribute("role", "dialog");
      modal.setAttribute("aria-modal", "true");
      modal.setAttribute("aria-labelledby", "pause-dialog-title");
      modal.innerHTML = `
        <div class="notification-content pause-reason-content">
          <h3 id="pause-dialog-title">Motivo de la pausa</h3>
          <label for="pause-reason">Selecciona una opción</label>
          <select id="pause-reason">
            <option value="Falta de material">Falta de material</option>
            <option value="Esperando autorización">Esperando autorización</option>
            <option value="Esperando instrucciones">Esperando instrucciones</option>
            <option value="Problema con herramienta o equipo">Problema con herramienta o equipo</option>
            <option value="Interrupción de otra área">Interrupción de otra área</option>
            <option value="Descanso">Descanso</option>
            <option value="Cambio de prioridad">Cambio de prioridad</option>
            <option value="Problema técnico">Problema técnico</option>
            <option value="Falta de información">Falta de información</option>
            <option value="Otra razón">Otra razón</option>
          </select>
          <label for="pause-note">Observación (opcional)</label>
          <textarea id="pause-note" rows="3"></textarea>
          <p class="pause-reason-error" role="alert" hidden>Especifica el motivo en la observación.</p>
          <div class="notification-actions">
            <button type="button" class="btn btn-secondary" data-pause-cancel>Cancelar</button>
            <button type="button" class="btn btn-primary" data-pause-confirm>Confirmar pausa</button>
          </div>
        </div>`;
      document.body.appendChild(modal);
      const finish = (value) => {
        modal.remove();
        if (previousFocus instanceof HTMLElement) previousFocus.focus();
        resolve(value);
      };
      modal.querySelector("[data-pause-cancel]").addEventListener("click", () => finish(null));
      modal.querySelector("[data-pause-confirm]").addEventListener("click", () => {
        const reason = modal.querySelector("#pause-reason").value;
        const note = modal.querySelector("#pause-note").value.trim();
        if (reason === "Otra razón" && !note) {
          modal.querySelector(".pause-reason-error").hidden = false;
          modal.querySelector("#pause-note").focus();
          return;
        }
        finish({ reason, note });
      });
      modal.addEventListener("keydown", (event) => {
        if (event.key === "Escape") finish(null);
      });
      modal.querySelector("#pause-reason").focus();
    });
  },

  async resumeActivity(activityId) {
    try {
      const response = await fetch("timer_actions.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ action: "resume", activityId, csrf_token: csrfToken() })
      });
      const data = await response.json();
      if (data.success) {
        await this.refreshTimerState();
        await this.fetchActivities();
        showNotification("Actividad reanudada", "success");
      }
    } catch (error) {
      console.error("Error al reanudar actividad:", error);
    }
  },

  async stopActivity(activityId) {
    if (!confirm("¿Estás seguro de que quieres terminar esta actividad?")) return;

    try {
      const response = await fetch("timer_actions.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ action: "stop", activityId, csrf_token: csrfToken() })
      });
      const data = await response.json();
      if (data.success) {
        this.stopTicker();
        this.currentActivityId = null;
        this.startTime = null;
        this.elapsedSeconds = 0;
        this.isTimerRunning = false;
        this.renderTimer();
        await this.fetchActivities();
        showNotification("Actividad terminada", "success");
      }
    } catch (error) {
      console.error("Error al terminar actividad:", error);
    }
  },

  // --- Cronómetro (solo UI) ---
  startTicker() {
    if (this.timerInterval) clearInterval(this.timerInterval);
    this.timerInterval = setInterval(() => {
      this.renderTimer();
    }, 1000);
  },

  stopTicker() {
    if (this.timerInterval) {
      clearInterval(this.timerInterval);
      this.timerInterval = null;
    }
  },

  renderTimer() {
    const timerEl = document.getElementById("timer");
    if (!timerEl) return;

    if (this.currentActivityId) {
      const runningSeconds = this.isTimerRunning && this.startTime
        ? Math.max(0, Math.floor((Date.now() - this.startTime) / 1000))
        : 0;
      timerEl.textContent = this.formatTime(this.elapsedSeconds + runningSeconds);
      timerEl.classList.add("active");
    } else {
      timerEl.textContent = "00:00:00";
      timerEl.classList.remove("active");
    }
  },

  formatTime(totalSeconds) {
    const minutes = Math.floor(totalSeconds / 60) % 60;
    const hours = Math.floor(totalSeconds / 3600);
    const seconds = Math.floor(totalSeconds % 60);
    return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
  },

  // --- Renderizado ---
  renderActivities(activities, container = document.getElementById("activities-list")) {
    if (!container) return;
    const countTarget = document.getElementById(container.dataset.activityCountTarget || "");
    if (countTarget) {
      countTarget.textContent = `${activities.length} ${activities.length === 1 ? "actividad" : "actividades"}`;
    }

    if (activities.length === 0) {
      container.innerHTML = `
        <div class="empty-state">
          <span class="empty-state-icon" aria-hidden="true">✓</span>
          <h3>¡Todo al día!</h3>
          <p>No tienes actividades asignadas por ahora.</p>
        </div>`;
      return;
    }

    container.innerHTML = "";
    const orderedActivities = activities.slice().sort((left, right) => {
      if (left.id == this.currentActivityId) return -1;
      if (right.id == this.currentActivityId) return 1;
      return 0;
    });
    orderedActivities.forEach(activity => {
      const card = document.createElement("div");
      const isCurrent = activity.id == this.currentActivityId;
      card.className = `activity-card${isCurrent ? " activity-card-hero is-active" : ""}`;
      card.dataset.activityId = activity.id;

      const statusClass = `status-${escapeHtml(activity.status)}`;
      const statusLabels = {
        pendiente: "Pendiente",
        en_progreso: "En progreso",
        pausada: "Pausada",
        completada: "Completada"
      };
      const statusText = escapeHtml(statusLabels[activity.status] || activity.status);

      let actionsHtml = "";
      if (isCurrent) {
        const action = activity.status === ACTIVITY_STATUS.STARTED ? "pause" : "resume";
        const label = action === "pause" ? "Pausar" : "Reanudar";
        actionsHtml = `
          <div class="activity-timer-panel">
            <span class="activity-timer-label">${activity.status === ACTIVITY_STATUS.PAUSED ? "Tiempo trabajado" : "Tiempo en actividad"}</span>
            <strong id="timer" class="timer-display">00:00:00</strong>
          </div>
          <div class="timer-actions">
            <button class="btn ${action === "pause" ? "btn-pausar" : "btn-reanudar"}" data-action="${action}">${label}</button>
            <button class="btn btn-terminar" data-action="stop">Terminar</button>
          </div>`;
      } else if (activity.status === ACTIVITY_STATUS.PENDING) {
        actionsHtml = `<button class="btn btn-iniciar" data-action="start">Iniciar actividad</button>`;
      }

      card.innerHTML = `
        <div class="activity-header">
          <div class="activity-title">${escapeHtml(activity.title || "Sin título")}</div>
          <span class="activity-status ${statusClass}">${statusText}</span>
        </div>
        ${isCurrent ? '<p class="activity-active-kicker">ACTIVIDAD ACTIVA</p>' : ""}
        ${activity.description ? `<p class="activity-description">${escapeHtml(activity.description)}</p>` : ""}
        <div class="activity-details">
          <p><strong>Proyecto</strong><span>${escapeHtml(activity.project || "Sin proyecto")}</span></p>
          <p><strong>Prioridad</strong><span class="priority-${escapeHtml(activity.priority || "media")}">${escapeHtml(activity.priority || "Media")}</span></p>
          <p><strong>Tipo</strong><span>${activity.activityType === "espontanea" ? "Espontánea" : "Asignada"}</span></p>
          ${activity.dueAt ? `<p><strong>Fecha límite</strong><span>${escapeHtml(activity.dueAt.slice(0, 10))}</span></p>` : ""}
          ${(Number(activity.elapsedSeconds) || 0) > 0 ?
            `<p><strong>Tiempo trabajado</strong><span>${this.formatTime(Number(activity.elapsedSeconds) || 0)}</span></p>` : ""}
          ${(Number(activity.currentDeadTimeSeconds) || 0) > 0 ?
            `<p><strong>Tu tiempo en pausa</strong><span>${this.formatTime(Number(activity.currentDeadTimeSeconds) || 0)}</span></p>` : ""}
        </div>
        <div class="activity-actions">
          ${actionsHtml}
        </div>
      `;
      container.appendChild(card);
    });
    this.renderTimer();
  },

};

// --- Notificaciones ---
function showNotification(message, type = "info") {
  // Cerrar notificaciones existentes
  const existing = document.querySelector(".notification-modal");
  if (existing) existing.remove();

  const modal = document.createElement("div");
  modal.className = `notification-modal notification-${type}`;
  modal.setAttribute("role", type === "error" ? "alert" : "status");
  modal.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
  modal.innerHTML = `
    <div class="notification-content">
      <h3>${type === "error" ? "❌ Error" : "✅ Notificación"}</h3>
      <p class="notification-message"></p>
      <button type="button" class="btn-close">Cerrar</button>
    </div>
  `;
  modal.querySelector(".notification-message").textContent = message;
  modal.querySelector(".btn-close").addEventListener("click", () => modal.remove());
  document.body.appendChild(modal);

  setTimeout(() => modal.remove(), 3000);
}

// --- Inicialización ---
document.addEventListener("DOMContentLoaded", () => {
  UIState.init();
});