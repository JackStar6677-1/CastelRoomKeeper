/**
 * =============================================================================
 * 🏛️ Castel Analytics & Heatmap Tracker v1.0
 * Colegio Castel Gandolfo · Analítica Soberana On-Premise
 * Diseñado para CCG Físico (ccg-fisico) · Cero dependencias · Máxima privacidad
 * =============================================================================
 */
(function (window, document) {
  'use strict';

  // Evitar doble inicialización o ejecución dentro del visor de mapas de calor
  if (window.__CCG_ANALYTICS_LOADED || window.__CCG_HEATMAP_PREVIEW) return;
  window.__CCG_ANALYTICS_LOADED = true;

  var ENDPOINT = 'https://ccg-fisico.tail0e08b5.ts.net:10000/api/analytics/collect';
  var queue = [];
  var flushTimer = null;
  var startTime = Date.now();
  var reportedScrolls = {};

  // Generar o recuperar ID de sesión anónima de pestaña
  var sessionId = '';
  try {
    sessionId = sessionStorage.getItem('ccg_sid') || '';
    if (!sessionId) {
      sessionId = Math.random().toString(36).substring(2, 10) + Date.now().toString(36);
      sessionStorage.setItem('ccg_sid', sessionId);
    }
  } catch (e) {
    sessionId = Math.random().toString(36).substring(2, 10);
  }

  // Detección de dispositivo
  function getDevice() {
    var w = window.innerWidth;
    var isTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
    if (w < 768) return 'mobile';
    if (w < 1024 && isTouch) return 'tablet';
    return 'desktop';
  }

  // Envío seguro de eventos al servidor
  function flush() {
    if (!queue.length) return;
    var batch = queue.slice();
    queue = [];

    var payload = JSON.stringify({ events: batch });
    if (navigator.sendBeacon) {
      try {
        var blob = new Blob([payload], { type: 'application/json' });
        if (navigator.sendBeacon(ENDPOINT, blob)) return;
      } catch (err) {}
    }

    try {
      if (window.fetch) {
        fetch(ENDPOINT, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: payload,
          keepalive: true,
          mode: 'cors'
        }).catch(function () {});
      } else {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', ENDPOINT, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.send(payload);
      }
    } catch (e) {}
  }

  function scheduleFlush() {
    if (!flushTimer) {
      flushTimer = setTimeout(function () {
        flushTimer = null;
        flush();
      }, 3500);
    }
  }

  function enqueue(ev) {
    queue.push(ev);
    if (queue.length >= 8) {
      if (flushTimer) clearTimeout(flushTimer);
      flushTimer = null;
      flush();
    } else {
      scheduleFlush();
    }
  }

  // 1. Registro de Pageview inicial
  function trackPageview() {
    var dev = getDevice();
    enqueue({
      type: 'pageview',
      path: window.location.pathname || '/',
      session_id: sessionId,
      referrer: document.referrer || '',
      device: dev,
      screen_w: window.innerWidth,
      screen_h: window.innerHeight
    });
  }

  // 2. Registro de Clics para Mapa de Calor
  document.addEventListener('click', function (e) {
    try {
      var target = e.target;
      if (!target) return;

      // Ignorar inputs confidenciales (contraseñas)
      if (target.type === 'password') return;

      var docW = Math.max(
        document.documentElement.scrollWidth || 0,
        document.body.scrollWidth || 0,
        window.innerWidth || 1
      );
      var pageX = e.pageX !== undefined ? e.pageX : (e.clientX + window.scrollX);
      var pageY = e.pageY !== undefined ? e.pageY : (e.clientY + window.scrollY);

      var x_pct = Math.min(100.0, Math.max(0.0, (pageX / docW) * 100));
      var y_px = Math.round(pageY);

      // Limpieza de texto de elemento
      var text = (target.innerText || target.value || target.getAttribute('aria-label') || target.getAttribute('title') || '').trim();
      text = text.replace(/\s+/g, ' ').substring(0, 50);

      var tag = (target.tagName || 'DIV').toUpperCase();

      enqueue({
        type: 'click',
        path: window.location.pathname || '/',
        session_id: sessionId,
        x_pct: Math.round(x_pct * 10) / 10,
        y_px: y_px,
        tag: tag,
        text: text,
        device: getDevice(),
        screen_w: window.innerWidth
      });
    } catch (err) {}
  }, { passive: true, capture: true });

  // 3. Registro de Profundidad de Scroll (25%, 50%, 75%, 100%)
  var scrollDebounce = null;
  function checkScroll() {
    try {
      var docH = Math.max(
        document.documentElement.scrollHeight || 0,
        document.body.scrollHeight || 0,
        window.innerHeight || 1
      );
      var winH = window.innerHeight;
      var scrollY = window.scrollY || window.pageYOffset || 0;
      var totalScrollable = docH - winH;
      if (totalScrollable <= 0) return;

      var currentPct = Math.min(100, Math.round(((scrollY + winH) / docH) * 100));
      var milestones = [25, 50, 75, 100];

      for (var i = 0; i < milestones.length; i++) {
        var m = milestones[i];
        if (currentPct >= m && !reportedScrolls[m]) {
          reportedScrolls[m] = true;
          var duration = Math.round((Date.now() - startTime) / 1000);
          enqueue({
            type: 'scroll',
            path: window.location.pathname || '/',
            session_id: sessionId,
            max_depth: m,
            duration_sec: duration
          });
        }
      }
    } catch (err) {}
  }

  window.addEventListener('scroll', function () {
    if (scrollDebounce) return;
    scrollDebounce = setTimeout(function () {
      scrollDebounce = null;
      checkScroll();
    }, 400);
  }, { passive: true });

  // Vaciar buffer antes de que la página se cierre o cambie de visibilidad
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      flush();
    }
  });
  window.addEventListener('pagehide', flush);

  // Inicializar al cargar
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', trackPageview);
  } else {
    trackPageview();
  }
})(window, document);
