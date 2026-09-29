(function () {
  "use strict";

  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  const status = document.getElementById("voice-input-status");
  const buttons = [
    { button: document.getElementById("mic-title"), target: "title", label: "🎤 Hablar título" },
    { button: document.getElementById("mic-description"), target: "description", label: "🎤 Hablar descripción" }
  ].filter((item) => item.button);

  if (!buttons.length) return;

  function showStatus(message, isError = false) {
    if (!status) return;
    status.textContent = message;
    status.classList.toggle("voice-input-error", isError);
  }

  if (!SpeechRecognition) {
    buttons.forEach(({ button }) => {
      button.disabled = true;
      button.textContent = "🎤 No compatible";
    });
    showStatus("Este navegador no admite dictado por voz. Prueba con Chrome o Edge actualizado.");
    return;
  }

  const recognition = new SpeechRecognition();
  let currentTarget = null;
  let isListening = false;
  let pendingTarget = null;

  recognition.lang = "es-MX";
  recognition.continuous = true;
  recognition.interimResults = true;

  function updateButtons() {
    buttons.forEach(({ button, target, label }) => {
      const active = isListening && currentTarget === target;
      button.classList.toggle("listening", active);
      button.textContent = active ? "⏹ Detener" : label;
      button.setAttribute("aria-pressed", String(active));
    });
  }

  function startRecognition(target) {
    const field = document.getElementById(target);
    if (!field) {
      showStatus("No se encontró el campo que recibirá el dictado.", true);
      return;
    }

    currentTarget = target;
    try {
      recognition.start();
      showStatus("Solicitando acceso al micrófono…");
    } catch (error) {
      currentTarget = null;
      updateButtons();
      showStatus("No se pudo iniciar el micrófono. Espera un momento e inténtalo de nuevo.", true);
      console.error("No se pudo iniciar el reconocimiento de voz:", error);
    }
  }

  recognition.onstart = () => {
    isListening = true;
    updateButtons();
    showStatus("Te escucho. Pulsa «Detener» cuando termines.");
  };

  recognition.onresult = (event) => {
    const field = document.getElementById(currentTarget);
    if (!field) return;

    let finalText = "";
    let interimText = "";
    for (let index = event.resultIndex; index < event.results.length; index++) {
      const result = event.results[index];
      if (result.isFinal) finalText += result[0].transcript;
      else interimText += result[0].transcript;
    }

    if (finalText.trim()) {
      field.value = `${field.value}${field.value.trim() ? " " : ""}${finalText.trim()}`;
      field.dispatchEvent(new Event("input", { bubbles: true }));
    }
    if (interimText.trim()) showStatus(`Escuchando: “${interimText.trim()}”`);
  };

  recognition.onerror = (event) => {
    const messages = {
      "not-allowed": "El navegador bloqueó el micrófono. Revisa los permisos del sitio y la conexión HTTPS.",
      "service-not-allowed": "El servicio de reconocimiento de voz no está permitido en este sitio.",
      "audio-capture": "No se encontró un micrófono disponible en el dispositivo.",
      "no-speech": "No se detectó voz. Pulsa el botón e inténtalo de nuevo.",
      network: "Falló la conexión con el servicio de dictado. Revisa tu conexión a internet."
    };
    showStatus(messages[event.error] || `Falló el reconocimiento de voz (${event.error}).`, true);
    console.error("Error en el reconocimiento de voz:", event.error);
  };

  recognition.onend = () => {
    isListening = false;
    currentTarget = null;
    updateButtons();
    if (pendingTarget) {
      const target = pendingTarget;
      pendingTarget = null;
      startRecognition(target);
      return;
    }
    if (!status?.classList.contains("voice-input-error")) {
      showStatus("Dictado detenido.");
    }
  };

  buttons.forEach(({ button, target }) => {
    button.disabled = false;
    button.addEventListener("click", () => {
      if (isListening && currentTarget === target) {
        recognition.stop();
        showStatus("Deteniendo dictado…");
      } else if (isListening) {
        pendingTarget = target;
        recognition.stop();
      } else {
        startRecognition(target);
      }
    });
  });

  if (!window.isSecureContext) {
    showStatus("Esta dirección usa HTTP. Algunos navegadores de teléfono bloquean el micrófono; si falla, abre la app con HTTPS.");
  }
})();
