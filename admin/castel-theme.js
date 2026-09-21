/**
 * Modo claro permanente — el modo oscuro fue retirado del sitio (decisión institucional, 2026-09-21).
 *
 * Este script reemplaza al antiguo motor de tema automático. Su única función es:
 *   1. Forzar data-theme="light" en <html>.
 *   2. Eliminar del DOM cualquier botón de tema ([data-theme-toggle]: header y flotante).
 *   3. Limpiar el estado guardado del tema en localStorage.
 * Mantiene una API global no-op para no romper código que aún la invoque.
 */
(function (global) {
    var doc = global.document;

    // Oculta los botones de tema de inmediato (anti-parpadeo), antes de removerlos del DOM.
    function injectHideStyle() {
        if (!doc || !doc.head || doc.getElementById('ccg-theme-removed-style')) return;
        var s = doc.createElement('style');
        s.id = 'ccg-theme-removed-style';
        s.textContent = '[data-theme-toggle],.theme-toggle,.theme-fab{display:none!important}';
        doc.head.appendChild(s);
    }

    function forceLight() {
        if (doc && doc.documentElement) {
            doc.documentElement.setAttribute('data-theme', 'light');
        }
    }

    // Quita los botones/switches de tema estén donde estén (header, FAB flotante, etc.).
    function removeToggles() {
        if (!doc || !doc.querySelectorAll) return;
        try {
            doc.querySelectorAll('[data-theme-toggle], .theme-toggle, .theme-fab').forEach(function (el) {
                if (el && el.parentNode) {
                    el.parentNode.removeChild(el);
                }
            });
        } catch (e) {}
    }

    // Borra cualquier preferencia de tema previa para que no reaparezca el modo oscuro.
    try {
        localStorage.removeItem('castel-theme');
        localStorage.removeItem('castel-theme-manual-override');
    } catch (e) {}

    injectHideStyle();
    forceLight();

    // API de compatibilidad: todo apunta a "claro" y no hace nada más.
    var noop = function () {};
    global.CASTEL_SCHEDULED_THEME = {
        chileHour: function () { return 12; },
        isNightChile: function () { return false; },
        themeFromSchedule: function () { return 'light'; },
        resolveInitialTheme: function () { return 'light'; },
        getOverride: function () { return false; },
        applyToDom: forceLight,
        resyncToSchedule: forceLight,
        updateToggleElements: noop,
        syncInstitutionalLogoPad: noop,
        STORAGE_THEME: 'castel-theme',
        STORAGE_OVERRIDE: 'castel-theme-manual-override',
        TIMEZONE: 'America/Santiago'
    };

    if (doc) {
        removeToggles();

        var onReady = function () {
            injectHideStyle();
            forceLight();
            removeToggles();
        };
        if (doc.readyState === 'loading') {
            doc.addEventListener('DOMContentLoaded', onReady, { once: true });
        } else {
            onReady();
        }

        // Por si un header se inyecta tarde (parciales/AJAX), vigila brevemente y limpia.
        try {
            var observer = new MutationObserver(removeToggles);
            observer.observe(doc.documentElement, { childList: true, subtree: true });
            // No hace falta observar para siempre: se apaga a los 5 s.
            global.setTimeout(function () {
                try { observer.disconnect(); } catch (e) {}
            }, 5000);
        } catch (e) {}
    }
})(typeof window !== 'undefined' ? window : this);
