(function () {
    var boot = window.CASTEL_CALENDAR_BOOT || {};
    var app = document.querySelector('[data-calendar-month-app]');
    if (!app) return;

    var ROOMS = [
        { id: 'basica', label: 'Sala Básica' },
        { id: 'media', label: 'Sala Media' }
    ];
    var MONTH_NAMES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
var SUBJECTS = ['Matemática', 'Lenguaje', 'Lectura y escritura', 'Inglés', 'Historia y Geografía', 'Ciencias', 'Tecnología', 'Artes', 'Música', 'Orientación', 'Evaluación', 'Taller', 'Taller: Acondicionamiento físico general', 'Taller: Acondicionamiento físico y recomposición corporal', 'Taller: Artes y creatividad', 'Taller: Artes y Manualidades', 'Taller: Basquetbol', 'Taller: Cueca y Bailes latinoamericanos', 'Taller: Danza', 'Taller: Fútbol', 'Taller: Gimnasia Rítmica', 'Taller: Patinaje 1: Formación', 'Taller: Patinaje 2: Avanzado', 'Taller: Psicomotricidad', 'Taller: Taller Instrumental', 'Taller: Voleibol', 'Reunión', 'Otro / actividad especial'];

    var now = new Date();
    var state = {
        year: now.getFullYear(),
        month: now.getMonth(),
        room: 'basica',
        selectedDate: now.getDay() >= 1 && now.getDay() <= 5 ? dateKey(now) : '',
        autoOpenCurrentSlot: true,
        csrfToken: boot.csrfToken || '',
        currentUser: boot.currentUser || { email: '', name: '', role: 'profesor' },
        canOverride: false,
        canManageMaintenance: false,
        canManageHolidays: false,
        notifyEmail: (function () {
            try {
                return window.localStorage.getItem('castel-calendar-notify-email') !== '0';
            } catch (e) {
                return true;
            }
        }()),
        slots: [],
        cursos: [],
        cursoLetras: ['A', 'B'],
        responsibleEmails: [],
        docenteDefault: 'Pablo Elías Avendaño Miranda',
        statusColors: {},
        jornadaTi: null,
        reservas: {},
        institutionalCommitments: {},
        dayBadges: {},
        pendingRequests: [],
        customHolidays: {},
        holidaysInMonth: {},
        holidayLookup: {},
        calendarNotices: [],
        latestBlockUpdate: null,
        incidenceSlotId: '',
        drafts: loadDrafts(),
        monthLoadSequence: 0
    };

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
        });
    }

    function loadDrafts() {
        try {
            var stored = window.sessionStorage.getItem('castel-calendar-block-drafts-v1');
            var parsed = stored ? JSON.parse(stored) : {};
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function persistDrafts() {
        try {
            window.sessionStorage.setItem('castel-calendar-block-drafts-v1', JSON.stringify(state.drafts));
        } catch (e) {
            // El calendario sigue siendo usable aunque el navegador bloquee sessionStorage.
        }
    }

    function draftKey(slotId) {
        return [state.currentUser.email || 'anon', state.room, state.selectedDate, String(slotId || '')].join('|');
    }

    function draftForSlot(slotId) {
        return state.drafts[draftKey(slotId)] || null;
    }

    function saveDraftFromCard(card, changedByUser) {
        if (!card || !state.selectedDate) return;
        if (card.getAttribute('data-draft-enabled') !== '1') return;
        if (!changedByUser && card.getAttribute('data-draft-dirty') !== '1') return;
        var slotId = card.getAttribute('data-slot') || '';
        if (!slotId) return;
        card.setAttribute('data-draft-dirty', '1');
        state.drafts[draftKey(slotId)] = {
            asignatura: (card.querySelector('[data-field="asignatura"]') || {}).value || '',
            curso: (card.querySelector('[data-field="curso"]') || {}).value || '',
            curso_letra: (card.querySelector('[data-field="curso_letra"]') || {}).value || '',
            docente: (card.querySelector('[data-field="docente"]') || {}).value || '',
            notes: (card.querySelector('[data-field="notes"]') || {}).value || ''
        };
        persistDrafts();
    }

    function captureVisibleDrafts() {
        app.querySelectorAll('[data-day-blocks] [data-slot][data-draft-enabled="1"][data-draft-dirty="1"]').forEach(function (card) {
            saveDraftFromCard(card, false);
        });
    }

    function clearSlotDraft(slotId) {
        delete state.drafts[draftKey(slotId)];
        persistDrafts();
    }

    function slotLabel(slotId) {
        var sid = String(slotId || '');
        var i;
        for (i = 0; i < state.slots.length; i += 1) {
            if (String(state.slots[i].slot_id || '') === sid) {
                return state.slots[i].nombre || sid;
            }
        }
        return sid;
    }

    function mailResultNote(data) {
        if (!data) {
            return '';
        }
        if (data.send_email_requested === true) {
            if (data.mail_sent === true) {
                return ' El aviso quedó en cola de correo.';
            }
            return ' No se pudo enviar el correo (revisa configuración SMTP).';
        }
        if (data.send_email_requested === false) {
            return ' Aviso por correo desactivado (casilla arriba).';
        }
        return '';
    }

    function processMailQueueInBackground() {
        if (!state.csrfToken) return;
        window.fetch('/admin/calendar_api.php?action=process_mail_queue', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: state.csrfToken })
        }).catch(function () {
            // La cola es persistente; otro acceso posterior puede reintentarla.
        });
    }

    function dateKey(date) {
        var y = date.getFullYear();
        var m = String(date.getMonth() + 1).padStart(2, '0');
        var d = String(date.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    }

    function parseDate(key) {
        var parts = (key || '').split('-').map(Number);
        return new Date(parts[0] || 0, (parts[1] || 1) - 1, parts[2] || 1);
    }

    function cloneDate(date) {
        return new Date(date.getFullYear(), date.getMonth(), date.getDate());
    }

    function addDays(date, days) {
        var next = cloneDate(date);
        next.setDate(next.getDate() + days);
        return next;
    }

    /** Domingo de Pascua (algoritmo anónimo gregoriano), misma lógica que calendar_app.js */
    function calculateEasterSunday(year) {
        var a = year % 19;
        var b = Math.floor(year / 100);
        var c = year % 100;
        var d = Math.floor(b / 4);
        var e = b % 4;
        var f = Math.floor((b + 8) / 25);
        var g = Math.floor((b - f + 1) / 3);
        var h = (19 * a + b - d - g + 15) % 30;
        var i = Math.floor(c / 4);
        var k = c % 4;
        var l = (32 + 2 * e + 2 * i - h - k) % 7;
        var m = Math.floor((a + 11 * h + 22 * l) / 451);
        var month = Math.floor((h + l - 7 * m + 114) / 31) - 1;
        var day = ((h + l - 7 * m + 114) % 31) + 1;
        return new Date(year, month, day);
    }

    /** Feriados legales Chile (referencia calendar_app.js / calendario anterior) */
    function getBuiltInHolidays(year) {
        var easter = calculateEasterSunday(year);
        var builtIn = [
            { date: new Date(year, 0, 1), label: 'Año Nuevo', source: 'nacional' },
            { date: addDays(easter, -2), label: 'Viernes Santo', source: 'nacional' },
            { date: addDays(easter, -1), label: 'Sábado Santo', source: 'nacional' },
            { date: new Date(year, 4, 1), label: 'Día del Trabajador', source: 'nacional' },
            { date: new Date(year, 4, 21), label: 'Glorias Navales', source: 'nacional' },
            { date: new Date(year, 5, 21), label: 'Día Nacional de los Pueblos Indígenas', source: 'nacional' },
            { date: new Date(year, 6, 16), label: 'Virgen del Carmen', source: 'nacional' },
            { date: new Date(year, 7, 15), label: 'Asunción de la Virgen', source: 'nacional' },
            { date: new Date(year, 8, 18), label: 'Independencia Nacional', source: 'nacional' },
            { date: new Date(year, 8, 19), label: 'Glorias del Ejército', source: 'nacional' },
            { date: new Date(year, 9, 12), label: 'Encuentro de Dos Mundos', source: 'nacional' },
            { date: new Date(year, 9, 31), label: 'Día de las Iglesias Evangélicas', source: 'nacional' },
            { date: new Date(year, 10, 1), label: 'Todos los Santos', source: 'nacional' },
            { date: new Date(year, 11, 8), label: 'Inmaculada Concepción', source: 'nacional' },
            { date: new Date(year, 11, 25), label: 'Navidad', source: 'nacional' }
        ];
        return builtIn.map(function (item) {
            return {
                date: dateKey(item.date),
                label: item.label,
                source: item.source
            };
        });
    }

    function rebuildHolidayLookup() {
        var map = {};
        var item;
        var lab;
        getBuiltInHolidays(state.year).forEach(function (row) {
            map[row.date] = { label: row.label, source: 'nacional' };
        });
        if (state.canManageHolidays && state.customHolidays && typeof state.customHolidays === 'object') {
            Object.keys(state.customHolidays).forEach(function (dk) {
                item = state.customHolidays[dk];
                lab = typeof item === 'string' ? item : (item && item.label);
                if (lab) {
                    map[dk] = { label: String(lab).trim(), source: 'interno' };
                }
            });
        } else {
            Object.keys(state.holidaysInMonth || {}).forEach(function (dk) {
                lab = state.holidaysInMonth[dk];
                lab = typeof lab === 'string' ? lab : (lab && lab.label);
                if (lab) {
                    map[dk] = { label: String(lab).trim(), source: 'interno' };
                }
            });
        }
        state.holidayLookup = map;
    }

    function isWeekendKey(key) {
        if (!key) return false;
        var wd = parseDate(key).getDay();
        return wd === 0 || wd === 6;
    }

    function dayCellMeta(date) {
        var key = dateKey(date);
        var wd = date.getDay();
        var isWeekend = wd === 0 || wd === 6;
        var entry = state.holidayLookup[key];
        var showLabel = !!(entry && entry.label && !isWeekend);
        return {
            key: key,
            isWeekend: isWeekend,
            showLabel: showLabel,
            label: showLabel ? entry.label : '',
            source: showLabel ? entry.source : ''
        };
    }

    function queryString(params) {
        return Object.keys(params).map(function (key) {
            return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
        }).join('&');
    }

    async function fetchJson(url, options) {
        var response = await fetch(url, options);
        var data = await response.json();
        if (!response.ok || data.ok === false) {
            var err = new Error(data.message || 'No se pudo completar la operación.');
            err.data = data;               // conserva code y detalles para un mensaje rico
            err.code = data && data.code;
            throw err;
        }
        return data;
    }

    async function postJsonAction(action, payload) {
        return fetchJson('/admin/calendar_api.php?action=' + encodeURIComponent(action), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
    }

    // CSS (una vez) de las ventanitas de aviso. Un solo look, acento por tipo.
    function ensureDialogCss() {
        if (document.getElementById('m-dialog-css')) return;
        var css =
            '.m-modal--dialog{position:fixed;inset:0;z-index:9998;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(15,23,42,.66);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);animation:mDlgFade .16s ease-out}' +
            '@keyframes mDlgFade{from{opacity:0}to{opacity:1}}' +
            ".m-dialog-card{box-sizing:border-box;width:100%;max-width:440px;background:#fff;color:#1f2937;border-radius:16px;border-top:6px solid var(--dlg,#2C4C74);padding:24px 24px 20px;text-align:center;font-family:'Outfit',system-ui,sans-serif;box-shadow:0 22px 55px rgba(0,0,0,.34);animation:mDlgPop .18s cubic-bezier(.2,.8,.3,1.15)}" +
            '@keyframes mDlgPop{from{transform:translateY(10px) scale(.97);opacity:.5}to{transform:none;opacity:1}}' +
            '.m-dialog--ok{--dlg:#3A6B3E}.m-dialog--error{--dlg:#b3261e}.m-dialog--warning{--dlg:#A9761F}.m-dialog--info{--dlg:#2C4C74}' +
            '.m-dialog-icon{font-size:44px;line-height:1;margin-bottom:6px;color:var(--dlg,#2C4C74)}' +
            '.m-dialog-title{margin:0 0 8px;font-size:19px;font-weight:800;color:var(--dlg,#2C4C74)}' +
            '.m-dialog-body{margin:0 0 12px;font-size:14.5px;line-height:1.5;color:#374151}' +
            '.m-dialog-meta{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin-bottom:14px}' +
            '.m-dialog-meta span{background:#f1f4f7;color:#4b5563;border-radius:999px;padding:3px 10px;font-size:12px}' +
            '.m-dialog-ok{display:inline-block;border:0;cursor:pointer;background:var(--dlg,#2C4C74);color:#fff;font-weight:700;font-size:14.5px;padding:11px 30px;border-radius:9px;font-family:inherit}' +
            '.m-dialog-ok:hover{filter:brightness(.92)}' +
            '@media(max-width:520px){.m-dialog-card{padding:20px 16px 16px}}';
        var s = document.createElement('style');
        s.id = 'm-dialog-css';
        s.textContent = css;
        document.head.appendChild(s);
    }

    function closeDialog() {
        var el = app.querySelector('[data-app-dialog]');
        if (el && el.parentNode) el.parentNode.removeChild(el);
    }

    // Ventanita de aviso que el usuario debe cerrar (no un toast que se escapa).
    function showDialog(opts) {
        ensureDialogCss();
        opts = opts || {};
        var type = opts.type || 'info';
        var icons = { ok: '✓', error: '⛔', warning: '⚠', info: 'ℹ' };
        var icon = opts.icon || icons[type] || icons.info;
        var meta = (opts.meta || []).filter(Boolean);

        closeDialog();
        var wrap = document.createElement('div');
        wrap.className = 'm-modal--dialog is-open';
        wrap.setAttribute('data-app-dialog', '');
        wrap.setAttribute('role', 'alertdialog');
        wrap.setAttribute('aria-modal', 'true');
        wrap.innerHTML =
            '<div class="m-dialog-card m-dialog--' + type + '">' +
                '<div class="m-dialog-icon" aria-hidden="true">' + escapeHtml(icon) + '</div>' +
                (opts.title ? '<h2 class="m-dialog-title">' + escapeHtml(opts.title) + '</h2>' : '') +
                (opts.body ? '<p class="m-dialog-body">' + escapeHtml(opts.body) + '</p>' : '') +
                (meta.length ? '<div class="m-dialog-meta">' + meta.map(function (m) { return '<span>' + escapeHtml(m) + '</span>'; }).join('') + '</div>' : '') +
                '<button type="button" class="m-dialog-ok" data-close-dialog>Entendido</button>' +
            '</div>';
        app.appendChild(wrap);

        function onKey(e) { if (e.key === 'Escape') done(); }
        function done() { document.removeEventListener('keydown', onKey); closeDialog(); }
        wrap.addEventListener('click', function (e) {
            if (e.target === wrap || (e.target && e.target.hasAttribute('data-close-dialog'))) done();
        });
        document.addEventListener('keydown', onKey);
        var okBtn = wrap.querySelector('[data-close-dialog]');
        if (okBtn) okBtn.focus();
    }

    // Compatibilidad: cada aviso ahora abre su propia ventanita, según el tipo.
    function showStatus(message, type) {
        var dlgType = type === 'error' ? 'error' : (type === 'ok' ? 'ok' : (type === 'warning' || type === 'info' ? type : 'info'));
        var opts;
        if (message && typeof message === 'object') {
            opts = { type: dlgType, icon: message.icon, title: message.title, body: message.body, meta: message.meta };
        } else {
            opts = {
                type: dlgType,
                title: dlgType === 'error' ? 'No se pudo completar' : (dlgType === 'ok' ? 'Listo' : 'Aviso'),
                body: String(message == null ? '' : message)
            };
        }
        showDialog(opts);
    }

    function clearStatus() {
        closeDialog();
        var box = app.querySelector('[data-status]');
        if (!box) return;
        box.className = 'm-status';
        box.textContent = '';
    }

    function renderSkeleton() {
        app.innerHTML =
            '<section class="m-shell">' +
                '<div class="m-status" data-status></div>' +
                '<div class="m-toolbar">' +
                    '<div class="m-toolbar-left">' +
                        '<button class="m-btn" type="button" data-nav="prev">&larr;</button>' +
                        '<strong data-month-title></strong>' +
                        '<span class="m-room-context" data-room-context></span>' +
                        '<button class="m-btn" type="button" data-nav="next">&rarr;</button>' +
                    '</div>' +
                    '<div class="m-toolbar-right">' +
                        '<div class="m-bell-wrap">' +
                            '<button type="button" class="m-bell" data-notif-toggle aria-label="Notificaciones" aria-haspopup="true" aria-expanded="false">' +
                                '<span class="m-bell-ico" aria-hidden="true">🔔</span>' +
                                '<span class="m-bell-badge" data-notif-badge hidden>0</span>' +
                            '</button>' +
                            '<div class="m-notif-panel" data-notif-panel hidden></div>' +
                        '</div>' +
                        '<div data-room-chips></div>' +
                        '<span class="m-last-updated" data-last-updated aria-live="polite"></span>' +
                        '<label class="m-mail-label" title="Activa o desactiva los avisos de esta sesión">' +
                            '<input type="checkbox" data-notify-email' + (state.notifyEmail ? ' checked' : '') + '>' +
                            '<span>Avisos por correo</span>' +
                        '</label>' +
                    '</div>' +
                '</div>' +
                '<div class="m-grid-wrap">' +
                    // Columna del mes: además de la rejilla recibe leyenda, avisos,
                    // feriados y solicitudes, para no dejar un vacío largo al lado
                    // del panel de bloques, que siempre es la columna más alta.
                    '<div class="m-col">' +
                        '<article class="m-panel m-panel--calendar">' +
                            '<div class="m-weekdays"><div>L</div><div>M</div><div>Mi</div><div>J</div><div>V</div><div>S</div><div>D</div></div>' +
                            '<div class="m-grid" data-month-grid></div>' +
                            '<ul class="m-legend">' +
                                '<li><i class="is-free"></i>Disponible</li>' +
                                '<li><i class="is-reserved"></i>Reservada</li>' +
                                '<li><i class="is-maintenance"></i>Mantención</li>' +
                                '<li><i class="is-blocked"></i>Recreo / almuerzo</li>' +
                                '<li><i class="is-holiday"></i>Feriado</li>' +
                                '<li><i class="is-today"></i>Hoy</li>' +
                            '</ul>' +
                        '</article>' +
                        '<div data-calendar-notices></div>' +
                        '<div data-holidays-panel></div>' +
                        '<article class="m-panel" data-requests-panel hidden>' +
                            '<h3>Solicitudes de aprobación pendientes</h3>' +
                            '<div data-requests></div>' +
                        '</article>' +
                    '</div>' +
                    '<article class="m-panel">' +
                        '<h3 data-day-title>Selecciona un día</h3>' +
                        '<div data-day-blocks class="m-blocks"><div class="m-help">Haz clic en un día hábil (lunes a viernes) del calendario para ver bloques, recreos y almuerzo.</div></div>' +
                    '</article>' +
                '</div>' +
            '</section>' +
            '<div class="m-modal" data-seat-modal>' +
                '<div class="m-modal-card">' +
                    '<div data-seat-content></div>' +
                '</div>' +
            '</div>' +
            '<div class="m-modal" data-incidence-modal>' +
                '<div class="m-modal-card">' +
                    '<form class="m-incidence-form" data-incidence-form>' +
                        '<div class="m-seat-modal-head">' +
                            '<div><strong>Reportar incidencia</strong><p class="m-seat-summary" data-incidence-summary>Completa el detalle para que quede registrado con contexto.</p></div>' +
                            '<button class="m-btn" data-close-incidence type="button">Cerrar</button>' +
                        '</div>' +
                        '<div class="m-incidence-grid">' +
                            '<label>Tipo de problema<select class="m-select" data-incidence-field="categoria"><option value="Equipo no enciende">Equipo no enciende</option><option value="Internet / red">Internet / red</option><option value="Teclado o mouse">Teclado o mouse</option><option value="Pantalla / proyector">Pantalla / proyector</option><option value="Audio">Audio</option><option value="Software o acceso">Software o acceso</option><option value="Orden / mobiliario">Orden / mobiliario</option><option value="Otro">Otro</option></select></label>' +
                            '<label>Prioridad<select class="m-select" data-incidence-field="prioridad"><option value="Media">Media</option><option value="Alta">Alta</option><option value="Baja">Baja</option></select></label>' +
                            '<label>Puesto o equipo<input class="m-input" data-incidence-field="puesto" placeholder="Ej: Puesto 12, proyector, PC profesor"></label>' +
                            '<label>¿Se pudo seguir la clase?<select class="m-select" data-incidence-field="continuidad"><option value="Sí, con dificultad">Sí, con dificultad</option><option value="Sí, sin afectar la clase">Sí, sin afectar la clase</option><option value="No, bloqueó la actividad">No, bloqueó la actividad</option></select></label>' +
                        '</div>' +
                        '<label>Detalle<textarea class="m-textarea" data-incidence-field="detalle" placeholder="Describe qué pasó, desde cuándo ocurre y cualquier mensaje de error visible." required></textarea></label>' +
                        '<label>Acción realizada o sugerida<textarea class="m-textarea" data-incidence-field="accion" placeholder="Ej: reinicié el equipo, cambié de puesto, requiere revisión técnica."></textarea></label>' +
                        '<div class="m-actions"><button class="m-btn" type="submit">Guardar incidencia</button><button class="m-btn" data-close-incidence type="button">Cancelar</button></div>' +
                    '</form>' +
                '</div>' +
            '</div>';
    }

    function noticeTimesHtml(notice) {
        return (notice.weekly_times || []).map(function (row) {
            var label = row.weekday_label || ('Día ' + row.weekday);
            var slot = row.slot_hint ? ' · ' + row.slot_hint : '';
            return '<span>' + escapeHtml(label + ' ' + row.time + slot) + '</span>';
        }).join('');
    }

    function renderCalendarNotices() {
        var host = app.querySelector('[data-calendar-notices]');
        if (!host) {
            return;
        }
        if (!state.calendarNotices.length) {
            host.innerHTML = '';
            host.style.display = 'none';
            return;
        }
        host.style.display = 'block';
        host.innerHTML =
            '<article class="m-panel">' +
                '<h3>Información importante de calendario</h3>' +
                '<div class="m-notices">' +
                    state.calendarNotices.map(function (notice) {
                        return '<div class="m-notice-card">' +
                            '<strong>' + escapeHtml(notice.title || 'Aviso') + '</strong>' +
                            '<div>' + escapeHtml(notice.subtitle || '') + (notice.audience ? ' · ' + escapeHtml(notice.audience) : '') + '</div>' +
                            '<div class="m-notice-times">' + noticeTimesHtml(notice) + '</div>' +
                            (notice.room_note ? '<p class="m-help m-notice-room-note">' + escapeHtml(notice.room_note) + '</p>' : '') +
                        '</div>';
                    }).join('') +
                '</div>' +
            '</article>';
    }

    function noticesForSelectedDay() {
        if (!state.selectedDate || !state.calendarNotices.length) {
            return [];
        }
        var weekday = parseDate(state.selectedDate).getDay();
        var matches = [];
        state.calendarNotices.forEach(function (notice) {
            (notice.weekly_times || []).forEach(function (row) {
                if (Number(row.weekday) === weekday) {
                    matches.push({
                        title: notice.title || 'Aviso',
                        subtitle: notice.subtitle || '',
                        audience: notice.audience || '',
                        room_note: notice.room_note || '',
                        weekday_label: row.weekday_label || '',
                        time: row.time || '',
                        slot_hint: row.slot_hint || ''
                    });
                }
            });
        });
        return matches;
    }

    function bindNotifyCheckbox() {
        var input = app.querySelector('[data-notify-email]');
        if (!input || input.getAttribute('data-bound') === '1') {
            return;
        }
        input.setAttribute('data-bound', '1');
        input.checked = state.notifyEmail;
        input.addEventListener('change', function () {
            state.notifyEmail = !!input.checked;
            try {
                window.localStorage.setItem('castel-calendar-notify-email', state.notifyEmail ? '1' : '0');
            } catch (e) {}
        });
    }

    function renderHolidaysPanel() {
        var host = app.querySelector('[data-holidays-panel]');
        if (!host) {
            return;
        }
        if (!state.canManageHolidays) {
            host.innerHTML = '';
            host.style.display = 'none';
            return;
        }
        host.style.display = 'block';
        var keys = Object.keys(state.customHolidays || {}).sort();
        var rows = keys.map(function (dateKey) {
            var lab = state.customHolidays[dateKey];
            var labelText = typeof lab === 'string' ? lab : (lab && lab.label) || '';
            return '<div class="m-holiday-row"><span><strong>' + escapeHtml(dateKey) + '</strong> — ' + escapeHtml(labelText) + '</span>' +
                '<button type="button" class="m-btn" data-action="remove-holiday" data-holiday-date="' + escapeHtml(dateKey) + '">Quitar</button></div>';
        }).join('');
        host.innerHTML =
            '<article class="m-panel">' +
                '<h3>Días especiales (año ' + state.year + ')</h3>' +
                '<p class="m-help">Aparecen resaltados en el calendario para todos. Usa motivos claros (ej. ensayo PAES, actividad institucional).</p>' +
                '<div class="m-holiday-form">' +
                    '<div class="m-row-2">' +
                        '<input type="date" class="m-input" data-holiday-date>' +
                        '<input type="text" class="m-input" data-holiday-label placeholder="Motivo del día especial">' +
                    '</div>' +
                    '<button type="button" class="m-btn" data-action="save-holiday">Guardar día especial</button>' +
                '</div>' +
                '<div class="m-holiday-list">' + (rows || '<div class="m-help">Aún no hay días especiales registrados para este año.</div>') + '</div>' +
            '</article>';
    }

    /**
     * Construye la rejilla del mes con semana partiendo en lunes.
     * Solo genera las filas necesarias (35 o 42 celdas) en vez de forzar
     * siempre seis semanas: una fila vacía sobraba en la mitad de los meses.
     */
    function buildMonthDays() {
        var first = new Date(state.year, state.month, 1);
        var last = new Date(state.year, state.month + 1, 0);
        var days = [];
        var startPad = (first.getDay() + 6) % 7;
        var total = Math.ceil((startPad + last.getDate()) / 7) * 7;
        var i;
        for (i = 0; i < startPad; i += 1) days.push({ date: new Date(state.year, state.month, i - startPad + 1), current: false });
        for (i = 1; i <= last.getDate(); i += 1) days.push({ date: new Date(state.year, state.month, i), current: true });
        while (days.length < total) days.push({ date: new Date(state.year, state.month + 1, days.length - (startPad + last.getDate()) + 1), current: false });
        return days;
    }

    function statusMeta(status) {
        return state.statusColors[status] || { bg: '#2f8f62', label: status || 'Disponible' };
    }

    function readableTextColor(background) {
        var raw = String(background || '').trim();
        var match;
        var r;
        var g;
        var b;

        if (/^#[0-9a-f]{6}$/i.test(raw)) {
            r = parseInt(raw.slice(1, 3), 16);
            g = parseInt(raw.slice(3, 5), 16);
            b = parseInt(raw.slice(5, 7), 16);
        } else if (/^#[0-9a-f]{3}$/i.test(raw)) {
            r = parseInt(raw.charAt(1) + raw.charAt(1), 16);
            g = parseInt(raw.charAt(2) + raw.charAt(2), 16);
            b = parseInt(raw.charAt(3) + raw.charAt(3), 16);
        } else {
            match = raw.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
            if (match) {
                r = Number(match[1]);
                g = Number(match[2]);
                b = Number(match[3]);
            }
        }

        if (typeof r !== 'number' || typeof g !== 'number' || typeof b !== 'number') {
            return '#ffffff';
        }

        return ((r * 299 + g * 587 + b * 114) / 1000) > 145 ? '#172a3f' : '#ffffff';
    }

    function isOutsideSupportHours() {
        if (!state.jornadaTi) return false;
        var current = new Date();
        var weekday = current.getDay();
        var timeMinutes = current.getHours() * 60 + current.getMinutes();
        function toMin(raw) {
            var parts = String(raw || '00:00').split(':').map(Number);
            return (parts[0] || 0) * 60 + (parts[1] || 0);
        }
        if (weekday >= 1 && weekday <= 4) return timeMinutes > toMin(state.jornadaTi.dias_habiles && state.jornadaTi.dias_habiles.hora_salida);
        if (weekday === 5) return timeMinutes > toMin(state.jornadaTi.viernes && state.jornadaTi.viernes.hora_salida);
        return true;
    }

    function renderRooms() {
        var host = app.querySelector('[data-room-chips]');
        host.innerHTML = ROOMS.map(function (room) {
            var active = room.id === state.room;
            return '<button type="button" class="m-chip' + (active ? ' is-active' : '') + '" data-room="' + room.id + '">' + (active ? '<span class="m-chip__check">✓</span>' : '') + room.label + '</button>';
        }).join(' ');
    }

    function renderMonth() {
        app.querySelector('[data-month-title]').textContent = MONTH_NAMES[state.month] + ' ' + state.year;
        var context = app.querySelector('[data-room-context]');
        var activeRoom = ROOMS.filter(function (room) { return room.id === state.room; })[0] || ROOMS[0];
        if (context) {
            context.innerHTML = 'Viendo: <strong>' + escapeHtml(activeRoom.label) + '</strong>';
        }
        var grid = app.querySelector('[data-month-grid]');
        var todayKey = dateKey(new Date());
        grid.innerHTML = buildMonthDays().map(function (dayInfo) {
            var key = dateKey(dayInfo.date);
            var badge = activeReservationCount(key);
            var meta = dayCellMeta(dayInfo.date);
            var cls = 'm-day' +
                (dayInfo.current ? '' : ' is-other') +
                (state.selectedDate === key && !meta.isWeekend ? ' is-selected' : '') +
                (todayKey === key ? ' is-today' : '') +
                (meta.isWeekend ? ' is-weekend' : '') +
                (meta.showLabel && meta.source === 'nacional' ? ' is-feriado-nacional' : '') +
                (meta.showLabel && meta.source === 'interno' ? ' is-feriado-interno' : '');
            var titleAttr = meta.showLabel ? ' title="' + escapeHtml(meta.label) + '"' : '';
            var labelHtml = meta.showLabel ? '<span class="m-day-holiday-label">' + escapeHtml(meta.label) + '</span>' : '';
            var inner =
                '<div class="m-day-top">' + (badge > 0 ? '<span class="m-day-badge">' + badge + '</span>' : '') + '</div>' +
                '<span class="m-day-num">' + dayInfo.date.getDate() + '</span>' +
                labelHtml;
            if (meta.isWeekend) {
                return '<div class="' + cls + '"' + titleAttr + ' role="presentation">' + inner + '</div>';
            }
            return '<button type="button" class="' + cls + '" data-date="' + key + '"' + titleAttr + '>' + inner + '</button>';
        }).join('');
    }

    function courseOptions(selected) {
        return ['<option value="">Curso</option>'].concat(state.cursos.map(function (course) {
            return '<option value="' + escapeHtml(course) + '"' + (selected === course ? ' selected' : '') + '>' + escapeHtml(course) + '</option>';
        })).join('');
    }

    function courseLetterOptions(selected) {
        return ['<option value="">Letra</option>'].concat((state.cursoLetras || ['A', 'B']).map(function (letter) {
            return '<option value="' + escapeHtml(letter) + '"' + (selected === letter ? ' selected' : '') + '>' + escapeHtml(letter) + '</option>';
        })).join('');
    }

    function responsibleEmailOptions(selected) {
        var emails = state.responsibleEmails.slice();
        if (selected && emails.indexOf(selected) === -1) emails.unshift(selected);
        return emails.map(function (email) {
            return '<option value="' + escapeHtml(email) + '"' + (email === selected ? ' selected' : '') + '>' + escapeHtml(email) + '</option>';
        }).join('');
    }

    function subjectOptions(selected) {
        var value = String(selected || '').trim();
        var options = SUBJECTS.slice();
        if (value && options.indexOf(value) === -1) {
            options.unshift(value);
        }
        return ['<option value="">Selecciona actividad</option>'].concat(options.map(function (subject) {
            return '<option value="' + escapeHtml(subject) + '"' + (value === subject ? ' selected' : '') + '>' + escapeHtml(subject) + '</option>';
        })).join('');
    }

    /** Ofrece mantenimiento como un uso técnico explícito del bloque. */
    function blockStatusOptions(selected) {
        var value = selected === 'mantenimiento' ? 'mantenimiento' : 'reservada';
        var options = ['<option value="reservada"' + (value === 'reservada' ? ' selected' : '') + '>Reserva de clase</option>'];
        if (state.canManageMaintenance) {
            options.push('<option value="mantenimiento"' + (value === 'mantenimiento' ? ' selected' : '') + '>Mantenimiento TI</option>');
        }
        return options.join('');
    }

    function courseDisplay(reservation) {
        var course = (reservation && reservation.curso) || '';
        var letter = (reservation && reservation.curso_letra) || '';
        if (!course && !letter) return 'Sin curso';
        return course + (letter ? ' ' + letter : '');
    }

    /** Muestra en el bloque cerrado qué actividad está registrada, sin exponer notas. */
    function reservationSummary(reservation, finished) {
        if (!reservation) return finished ? 'Clase realizada: registro guardado.' : 'Bloque ocupado.';
        var course = courseDisplay(reservation);
        var details = [
            String(reservation.asignatura || '').trim(),
            course !== 'Sin curso' ? course : ''
        ].filter(Boolean);
        if (!details.length) {
            return finished ? 'Clase realizada: registro guardado.' : 'Bloque ocupado.';
        }
        return (finished ? 'Clase realizada: ' : 'Ocupado: ') + details.join(' · ');
    }

    function slotReservation(date, slotId) {
        var byDate = state.reservas[date] || {};
        return byDate[slotId] || null;
    }

    function slotCommitment(date, slotId) {
        var byDate = state.institutionalCommitments[date] || {};
        return byDate[slotId] || null;
    }

    function canEdit(reservation) {
        if (!reservation) return true;
        return state.canOverride || reservation.owner_email === state.currentUser.email;
    }

    /** Momento exacto en que termina un bloque de un día concreto. */
    function slotEndMoment(dateKey, slot) {
        var moment = parseDate(dateKey);
        var parts = String((slot && slot.hora_fin) || '00:00').split(':').map(Number);
        moment.setHours(parts[0] || 0, parts[1] || 0, 0, 0);
        return moment;
    }

    /**
     * Un bloque queda "finalizado" cuando su horario ya terminó. La reserva no
     * se borra: sigue guardada y visible en el detalle, pero deja de contarse
     * como ocupación vigente para que el calendario no arrastre días pasados.
     */
    function isSlotFinished(dateKey, slot) {
        if (!dateKey || !slot) return false;
        try {
            return slotEndMoment(dateKey, slot).getTime() <= Date.now();
        } catch (e) {
            return false;
        }
    }

    /**
     * Reservas todavía vigentes de un día. Reemplaza al contador que envía el
     * servidor, que no conoce la hora del navegador y por eso seguía marcando
     * días ya cumplidos.
     */
    function activeReservationCount(dateKey) {
        var byDate = state.reservas[dateKey] || {};
        return state.slots.reduce(function (total, slot) {
            if (slot.es_bloqueado) return total;
            var reservation = byDate[String(slot.slot_id || '')];
            if (!reservation || reservation.status === 'disponible') return total;
            if (isSlotFinished(dateKey, slot)) return total;
            return total + 1;
        }, 0);
    }

    function renderDayPanel() {
        var title = app.querySelector('[data-day-title]');
        var host = app.querySelector('[data-day-blocks]');
        if (!state.selectedDate || isWeekendKey(state.selectedDate)) {
            if (isWeekendKey(state.selectedDate)) {
                state.selectedDate = '';
            }
            title.textContent = 'Selecciona un día';
            host.innerHTML = '<div class="m-help">Haz clic en un día hábil (lunes a viernes) del calendario para ver bloques, recreos y almuerzo.</div>';
            return;
        }
        var date = parseDate(state.selectedDate);
        var currentSlotId = state.autoOpenCurrentSlot ? currentClassSlotId(date) : '';
        title.textContent = date.toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        var supportWarning = isOutsideSupportHours()
            ? '<div class="m-warning">' + escapeHtml((state.jornadaTi && state.jornadaTi.mensaje) || 'Fuera de Horario Soporte') + '</div>'
            : '';
        var dayNotices = noticesForSelectedDay().map(function (notice) {
            var details = notice.weekday_label + ' ' + notice.time + (notice.slot_hint ? ' · ' + notice.slot_hint : '');
            return '<div class="m-warning"><strong>' + escapeHtml(notice.title) + '</strong>: ' +
                escapeHtml(details) +
                (notice.audience ? ' · ' + escapeHtml(notice.audience) : '') +
                (notice.room_note ? '<br>' + escapeHtml(notice.room_note) : '') +
                '</div>';
        }).join('');
        host.innerHTML = supportWarning + dayNotices + state.slots.map(function (slot) {
            var slotId = String(slot.slot_id || '');
            var reservation = slotReservation(state.selectedDate, slotId);
            var commitment = slotCommitment(state.selectedDate, slotId);
            var blocked = !!slot.es_bloqueado;
            var displayedReservation = reservation || commitment;
            // Un bloque cumplido queda en solo lectura; coordinación conserva
            // la edición por si hay que corregir un registro pasado.
            var finished = isSlotFinished(state.selectedDate, slot);
            var editable = !commitment && canEdit(reservation) && (!finished || state.canOverride);
            // Los borradores solo existen antes de reservar. Una reserva guardada siempre es canónica.
            // Tampoco tiene sentido conservar uno sobre un horario ya cumplido.
            var canPersistDraft = !reservation && !commitment && !finished;
            var storedDraft = draftForSlot(slotId);
            var draft = canPersistDraft ? storedDraft : null;
            // Limpia cualquier borrador antiguo para que no pueda tapar datos guardados.
            if (!canPersistDraft && storedDraft) {
                clearSlotDraft(slotId);
            }
            var status = displayedReservation ? displayedReservation.status : (blocked ? 'bloqueada' : 'disponible');
            var meta = statusMeta(status);
            // El horario cumplido manda sobre el estado guardado: la reserva se
            // conserva, pero deja de mostrarse como ocupación activa.
            if (finished && !blocked) {
                meta = { bg: '#4A5A6A', label: displayedReservation ? 'Finalizada' : 'Fuera de horario' };
            }
            var pillBg = meta.bg || '#2f8f62';
            var pillColor = readableTextColor(pillBg);
            var ownerText = displayedReservation ? (displayedReservation.owner_name || displayedReservation.owner_email || '') : '';
            var disabledSave = displayedReservation && !editable ? ' disabled' : '';
            var slotClass = 'm-slot' + (blocked ? ' m-slot--blocked is-locked' : '') + (displayedReservation ? ' has-reservation' : ' is-free') + (commitment ? ' m-slot--institutional is-locked' : '') + (finished && !blocked ? ' is-finished' : '');
            var slotTime = (slot.hora_inicio || '') + ' a ' + (slot.hora_fin || '');
            var slotHeader = (slot.nombre || slotId) + ' · ' + slotTime;
            var values = draft || {
                status: (displayedReservation && displayedReservation.status) || 'reservada',
                asignatura: (displayedReservation && displayedReservation.asignatura) || '',
                curso: (displayedReservation && displayedReservation.curso) || '',
                curso_letra: (displayedReservation && displayedReservation.curso_letra) || '',
                docente: (displayedReservation && displayedReservation.docente) || (!displayedReservation ? (state.currentUser.email || state.docenteDefault) : ''),
                notes: (displayedReservation && displayedReservation.notes) || ''
            };
            var canSelectResponsible = state.canOverride && state.responsibleEmails.length > 0;
            if (!canSelectResponsible && state.currentUser.email) {
                values.docente = state.currentUser.email;
            }
            if (canSelectResponsible && !values.docente) values.docente = state.currentUser.email;

            var blockHtml =
                '<details class="' + slotClass + '" data-slot="' + escapeHtml(slotId) + '" data-draft-enabled="' + (canPersistDraft ? '1' : '0') + '" data-reservation-status="' + escapeHtml((reservation && reservation.status) || 'reservada') + '" data-reservation-asignatura="' + escapeHtml((reservation && reservation.asignatura) || '') + '" data-reservation-curso="' + escapeHtml((reservation && reservation.curso) || '') + '" data-reservation-curso-letra="' + escapeHtml((reservation && reservation.curso_letra) || '') + '" data-reservation-docente="' + escapeHtml((reservation && reservation.docente) || '') + '" data-reservation-notes="' + escapeHtml((reservation && reservation.notes) || '') + '"' + (draft || slotId === currentSlotId ? ' open' : '') + '>' +
                    '<summary class="m-slot-summary">' +
                        '<span class="m-slot-summary-main"><strong>' + escapeHtml(slotHeader) + '</strong><span>' + escapeHtml(commitment ? 'Congreso IV medios: sala por definir.' : (finished && !blocked ? (displayedReservation ? reservationSummary(displayedReservation, true) : 'Horario ya transcurrido.') : (reservation ? (status === 'mantenimiento' ? 'Mantenimiento programado: abre para ver el detalle.' : reservationSummary(reservation, false)) : (blocked ? 'Horario no reservable.' : 'Disponible para reservar.')))) + '</span></span>' +
                        (displayedReservation && !finished ? '<span class="m-occupancy">★ Ocupado</span>' : '') +
                        '<span class="m-pill' + (blocked ? ' is-blocked' : '') + '" style="background:' + escapeHtml(pillBg) + ';color:' + escapeHtml(pillColor) + '">' + escapeHtml(meta.label || status) + '</span>' +
                        '<span class="m-slot-caret" aria-hidden="true">›</span>' +
                    '</summary>';

            if (blocked) {
                blockHtml += '<div class="m-slot-body"><div class="m-help">Recreo / almuerzo bloqueado para reservas.</div></div></details>';
                return blockHtml;
            }

            if (commitment) {
                blockHtml +=
                    '<div class="m-slot-body">' +
                        '<div class="m-reservation-summary">' +
                            '<strong>' + escapeHtml(commitment.asignatura || 'Compromiso institucional') + '</strong>' +
                            '<div>' + escapeHtml(commitment.docente || 'Actividad institucional') + '</div>' +
                            '<div>' + escapeHtml(commitment.notes || 'Sala por definir según disponibilidad real del día.') + '</div>' +
                            (reservation ? '<div class="m-help">Existe una reserva de sala en este horario; coordinación debe resolver la disponibilidad real.</div>' : '') +
                        '</div>' +
                        '<div class="m-help">Bloque fijado hasta el cierre del año escolar 2026. No se puede editar desde el calendario.</div>' +
                    '</div></details>';
                return blockHtml;
            }

            // Horario cumplido: registro histórico en solo lectura. No se
            // ofrecen reserva ni solicitud de cambio sobre algo ya ocurrido.
            if (finished && !editable) {
                blockHtml += '<div class="m-slot-body">';
                if (reservation) {
                    blockHtml +=
                        '<div class="m-reservation-summary">' +
                            '<strong>' + escapeHtml(reservation.asignatura || 'Actividad sin especificar') + '</strong>' +
                            '<span>' + escapeHtml(courseDisplay(reservation)) + (reservation.docente ? ' · ' + escapeHtml(reservation.docente) : '') + '</span>' +
                            (reservation.notes ? '<span>Observaciones: ' + escapeHtml(reservation.notes) + '</span>' : '') +
                        '</div>' +
                        '<div class="m-help">Registro guardado · Reservó: ' + escapeHtml(ownerText) + '</div>' +
                        '<div class="m-actions">' +
                            '<button class="m-btn" type="button" data-action="report-slot" data-slot="' + escapeHtml(slotId) + '">Incidencia</button>' +
                        '</div>';
                } else {
                    blockHtml += '<div class="m-help">Bloque sin reserva registrada. El horario ya transcurrió.</div>';
                }
                blockHtml += '</div></details>';
                return blockHtml;
            }

            if (reservation && !editable) {
                blockHtml +=
                    '<div class="m-slot-body">' +
                        '<div class="m-reservation-summary">' +
                            '<strong>' + escapeHtml(reservation.asignatura || 'Actividad sin especificar') + '</strong>' +
                            '<span>' + escapeHtml(courseDisplay(reservation)) + (reservation.docente ? ' · ' + escapeHtml(reservation.docente) : '') + '</span>' +
                            (reservation.notes ? '<span>Observaciones: ' + escapeHtml(reservation.notes) + '</span>' : '') +
                        '</div>' +
                        '<div class="m-help">Reservado por: ' + escapeHtml(ownerText) + '</div>' +
                        '<div class="m-actions">' +
                            '<button class="m-btn" type="button" data-action="request-slot" data-slot="' + escapeHtml(slotId) + '">Solicitar cambio</button>' +
                            '<button class="m-btn" type="button" data-action="report-slot" data-slot="' + escapeHtml(slotId) + '">Incidencia</button>' +
                        '</div>' +
                    '</div></details>';
                return blockHtml;
            }

            blockHtml +=
                '<div class="m-slot-body">' +
                    (state.canManageMaintenance ? '<div class="m-row"><label class="m-field"><span>Uso del bloque</span><select class="m-select" data-field="status">' + blockStatusOptions(values.status) + '</select></label></div>' : '') +
                    '<div class="m-row"><label class="m-field"><span>Actividad</span>' +
                        '<select class="m-select" data-field="asignatura" required>' + subjectOptions(values.asignatura) + '</select></label>' +
                    '</div>' +
                    '<div class="m-row-course">' +
                        '<label class="m-field"><span>Curso</span><select class="m-select" data-field="curso" required>' + courseOptions(values.curso) + '</select></label>' +
                        '<label class="m-field"><span>Letra</span><select class="m-select" data-field="curso_letra" required>' + courseLetterOptions(values.curso_letra) + '</select></label>' +
                        '<label class="m-field"><span>Correo del docente responsable</span>' +
                        (canSelectResponsible
                            ? '<select class="m-select" data-field="docente" required>' + responsibleEmailOptions(values.docente) + '</select>'
                            : '<input class="m-input" data-field="docente" value="' + escapeHtml(values.docente) + '" readonly aria-readonly="true" required>') +
                        '</label>' +
                    '</div>' +
                    '<details class="m-details"' + (values.notes ? ' open' : '') + '><summary>' + (values.notes ? 'Observaciones' : 'Agregar observaciones') + '</summary><label class="m-field"><span>Observaciones</span><textarea class="m-textarea" data-field="notes" placeholder="Ej. evaluación, actividad especial o requerimiento técnico">' + escapeHtml(values.notes) + '</textarea></label></details>' +
                    (reservation ? '<div class="m-help">Propietario: ' + escapeHtml(ownerText) + '</div>' : '') +
                    (draft ? '<div class="m-help">Borrador local sin guardar.</div>' : '') +
                    '<div class="m-actions">' +
                        '<button class="m-btn" type="button" data-action="save-slot" data-slot="' + escapeHtml(slotId) + '" data-version="' + escapeHtml(String((reservation && reservation.version) || 0)) + '"' + disabledSave + '>Guardar reserva</button>' +
                        (reservation && editable ? '<button class="m-btn" type="button" data-action="clear-slot" data-slot="' + escapeHtml(slotId) + '">Liberar</button>' : '') +
                        (reservation ? '<button class="m-btn" type="button" data-action="report-slot" data-slot="' + escapeHtml(slotId) + '">Incidencia</button>' : '') +
                        (reservation ? '<button class="m-btn" type="button" data-action="map-slot" data-slot="' + escapeHtml(slotId) + '" disabled title="Mapa de puestos pendiente de nómina por curso">Mapa</button>' : '') +
                    '</div>' +
                '</div></details>';

            return blockHtml;
        }).join('');
        state.autoOpenCurrentSlot = false;
    }

    /**
     * En una sola columna (móvil/tablet) el panel de bloques queda bajo el mes.
     * Sin esto el docente toca un día y no ve ningún cambio en pantalla.
     * En escritorio ambos paneles conviven, así que no se desplaza nada.
     */
    function focusDayPanel() {
        try {
            if (!window.matchMedia || !window.matchMedia('(max-width: 1099px)').matches) return;
            var panel = app.querySelector('[data-day-title]');
            if (!panel || typeof panel.scrollIntoView !== 'function') return;
            var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            panel.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
        } catch (e) {
            // Un fallo de scroll nunca debe impedir ver los bloques del día.
        }
    }

    /** Identifica el bloque lectivo en curso, solo cuando la fecha mostrada es hoy. */
    function currentClassSlotId(date) {
        if (dateKey(date) !== dateKey(new Date())) return '';
        var now = new Date();
        var currentMinutes = now.getHours() * 60 + now.getMinutes();
        return state.slots.reduce(function (found, slot) {
            if (found || slot.es_bloqueado) return found;
            var start = String(slot.hora_inicio || '00:00').split(':').map(Number);
            var end = String(slot.hora_fin || '00:00').split(':').map(Number);
            var startMinutes = (start[0] || 0) * 60 + (start[1] || 0);
            var endMinutes = (end[0] || 0) * 60 + (end[1] || 0);
            return currentMinutes >= startMinutes && currentMinutes < endMinutes ? String(slot.slot_id || '') : '';
        }, '');
    }

    /** Muestra el último cambio persistido para que la comunidad sepa qué está viendo. */
    function renderLatestBlockUpdate() {
        var host = app.querySelector('[data-last-updated]');
        if (!host) return;
        var latest = state.latestBlockUpdate;
        if (!latest || !latest.updated_at) {
            host.textContent = 'Sin actualizaciones de bloques registradas aún.';
            return;
        }
        var timestamp = new Date(latest.updated_at);
        var when = isNaN(timestamp.getTime())
            ? latest.updated_at
            : timestamp.toLocaleString('es-CL', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
        host.textContent = 'Última actualización: ' + when + ' · ' + (latest.slot_label || 'Bloque') + ' · ' + (latest.date || '');
    }

    function renderPendingRequests() {
        var host = app.querySelector('[data-requests]');
        var panel = app.querySelector('[data-requests-panel]');
        if (!state.pendingRequests.length) {
            panel.hidden = true;
            host.innerHTML = '';
            return;
        }
        panel.hidden = false;
        host.innerHTML = state.pendingRequests.map(function (req) {
            return '<div class="m-slot">' +
                '<strong>' + escapeHtml(req.date || '') + ' · ' + escapeHtml(slotLabel(req.slot_id)) + '</strong>' +
                '<div class="m-help">Solicita: ' + escapeHtml(req.requested_by_name || req.requested_by_email || '') + ' · Propietario: ' + escapeHtml(req.owner_name || req.owner_email || '') + '</div>' +
                '<div class="m-help">Motivo: ' + escapeHtml(req.reason || '') + '</div>' +
                ((state.canOverride || req.owner_email === state.currentUser.email)
                    ? '<div class="m-actions"><button class="m-btn" data-action="approve-request" data-request="' + req.id + '">Aprobar</button><button class="m-btn" data-action="reject-request" data-request="' + req.id + '">Rechazar</button></div>'
                    : '') +
            '</div>';
        }).join('');
    }

    function getSlotCard(slotId) {
        return app.querySelector('[data-slot="' + slotId + '"]');
    }

    function cardFieldValue(card, field) {
        var input = card.querySelector('[data-field="' + field + '"]');
        if (input) return input.value || '';
        return card.getAttribute('data-reservation-' + field.replace('_', '-')) || '';
    }

    function payloadFromCard(slotId, mode) {
        var card = getSlotCard(slotId);
        var status = mode === 'clear' ? 'disponible' : cardFieldValue(card, 'status').trim() || 'reservada';
        return {
            room: state.room,
            date: state.selectedDate,
            slot_id: slotId,
            status: status,
            asignatura: mode === 'clear' ? '' : cardFieldValue(card, 'asignatura').trim(),
            curso: mode === 'clear' ? '' : cardFieldValue(card, 'curso'),
            curso_letra: mode === 'clear' ? '' : cardFieldValue(card, 'curso_letra'),
            docente: mode === 'clear' ? '' : cardFieldValue(card, 'docente').trim(),
            notes: mode === 'clear' ? '' : cardFieldValue(card, 'notes').trim(),
            version: Number((card.querySelector('[data-action="save-slot"]') || {}).getAttribute('data-version') || 0),
            csrf_token: state.csrfToken,
            send_email: state.notifyEmail
        };
    }

    /** Evita reservas incompletas antes de llamar al API; el servidor repite la validación. */
    function reservationMissingFields(payload) {
        if (!payload || (payload.status !== 'reservada' && payload.status !== 'mantenimiento')) return [];
        var labels = {
            asignatura: 'actividad',
            docente: 'correo del docente responsable'
        };
        if (payload.status === 'reservada') {
            labels.curso = 'curso';
            labels.curso_letra = 'letra';
        }
        return Object.keys(labels).filter(function (field) {
            return !String(payload[field] || '').trim();
        }).map(function (field) {
            return labels[field];
        });
    }

    async function loadMonth() {
        var requestSequence = ++state.monthLoadSequence;
        var data = await fetchJson('/admin/calendar_api.php?' + queryString({
            action: 'load_blocks',
            year: state.year,
            month: state.month + 1,
            room: state.room
        }));
        // Una respuesta antigua no puede volver a pintar reservas previas tras guardar o liberar.
        if (requestSequence !== state.monthLoadSequence) return;
        state.csrfToken = data.csrf_token || state.csrfToken;
        state.currentUser = data.user || state.currentUser;
        state.canOverride = !!(data.user && data.user.can_override);
        state.canManageMaintenance = !!(data.user && data.user.can_manage_maintenance);
        state.slots = data.slots || [];
        state.cursos = data.cursos || [];
        state.cursoLetras = data.curso_letras || state.cursoLetras;
        state.responsibleEmails = Array.isArray(data.responsible_emails) ? data.responsible_emails : [];
        state.docenteDefault = data.docente_default || state.docenteDefault;
        state.statusColors = data.status_colors || {};
        state.jornadaTi = data.jornada_ti || null;
        state.reservas = data.reservas || {};
        state.dayBadges = data.day_badges || {};
        state.pendingRequests = data.pending_requests || [];
        state.holidaysInMonth = data.custom_holidays_in_month || {};
        state.canManageHolidays = !!(data.user && data.user.can_manage_holidays);
        state.customHolidays = state.canManageHolidays ? (data.custom_holidays_for_year || {}) : {};
        state.calendarNotices = data.calendar_notices || [];
        state.institutionalCommitments = data.institutional_commitments || {};
        state.latestBlockUpdate = data.latest_block_update || null;
        rebuildHolidayLookup();
        if (state.selectedDate && isWeekendKey(state.selectedDate)) {
            state.selectedDate = '';
        }
        renderRooms();
        renderMonth();
        renderLatestBlockUpdate();
        renderDayPanel();
        renderPendingRequests();
        renderCalendarNotices();
        renderHolidaysPanel();
        window.setTimeout(processMailQueueInBackground, 0);
    }

    async function saveSlot(slotId, mode) {
        captureVisibleDrafts();
        var action = mode === 'request' ? 'request_block_change' : 'save_block';
        var payload = payloadFromCard(slotId, mode === 'clear' ? 'clear' : 'save');
        var missingFields = mode === 'save' ? reservationMissingFields(payload) : [];
        if (missingFields.length) {
            showStatus({
                icon: '!',
                title: 'Falta información obligatoria',
                body: 'Completa ' + missingFields.join(', ') + ' antes de guardar la reserva.',
                meta: ['El bloque no se guardó y el borrador permanece en pantalla.']
            }, 'error');
            return;
        }
        if (mode === 'request') {
            var reason = window.prompt('Motivo de la solicitud de aprobación:');
            if (!reason) return;
            payload.reason = reason.trim();
        }
        var data;
        try {
            data = await fetchJson('/admin/calendar_api.php?action=' + action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
        } catch (err) {
            // Rechazo por anticipación mínima: mensaje grande y detallado, no un toast fugaz.
            // El borrador queda intacto para que el docente vea qué intentó reservar.
            if (err && err.code === 'too_close_to_start' && err.data) {
                showBlockRuleModal(err.data);
                return;
            }
            throw err;
        }
        if (mode !== 'request') {
            clearSlotDraft(slotId);
        }
        await loadMonth();
        var base = mode === 'request' ? (data.message || 'Solicitud enviada.') : (data.message || 'Bloque actualizado.');
        showOperationStatus(data, mode, base);
    }

    async function respondRequest(requestId, decision) {
        var data = await fetchJson('/admin/calendar_api.php?action=respond_block_request', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                request_id: Number(requestId),
                decision: decision,
                csrf_token: state.csrfToken,
                send_email: state.notifyEmail
            })
        });
        await loadMonth();
        var base = data.message || (decision === 'approve' ? 'Solicitud aprobada.' : 'Solicitud rechazada.');
        showOperationStatus(data, decision === 'approve' ? 'approve' : 'reject', base);
    }

    function mailStatusLabel(data) {
        if (!data || data.send_email_requested !== true) {
            return data && data.send_email_requested === false ? 'Correo desactivado' : '';
        }
        return data.mail_sent === true ? 'Aviso en cola' : 'Correo no encolado';
    }

    function showOperationStatus(data, mode, fallback) {
        var mail = mailStatusLabel(data);
        var title = fallback || 'Operación completada';
        var body = 'El calendario quedó actualizado correctamente.';
        if (mode === 'save') {
            title = (data && data.message) || 'Bloque agendado correctamente';
            body = 'La reserva quedó guardada en la base de datos del calendario.';
        } else if (mode === 'clear') {
            title = (data && data.message) || 'Bloque liberado correctamente';
            body = 'El bloque volvió a quedar disponible para nuevas reservas.';
        } else if (mode === 'request') {
            title = (data && data.message) || 'Solicitud enviada';
            body = 'La solicitud quedó registrada para que el propietario o coordinación la revise.';
        } else if (mode === 'approve' || mode === 'reject') {
            body = 'La respuesta quedó registrada y el calendario ya refleja el estado actualizado.';
        }
        showStatus({
            icon: mode === 'reject' ? '!' : '✓',
            title: title,
            body: body,
            meta: [mail, data && data.mail_notice ? data.mail_notice : 'Registro confirmado'].filter(Boolean)
        }, mode === 'reject' ? 'error' : 'ok');
    }

    // Inyecta (una sola vez) el CSS del modal de regla. Autosuficiente: no depende de
    // calendar.css, así el aviso se ve igual aunque solo se despliegue el JS.
    function ensureRuleModalCss() {
        if (document.getElementById('m-rule-modal-css')) return;
        var css =
            '.m-modal--rule{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;' +
            'justify-content:center;padding:20px;background:rgba(15,23,42,.72);' +
            '-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);animation:mRuleFade .18s ease-out;}' +
            '@keyframes mRuleFade{from{opacity:0}to{opacity:1}}' +
            '.m-rule-card{box-sizing:border-box;width:100%;max-width:480px;background:#fff;color:#1f2937;' +
            'border-radius:18px;border-top:7px solid #c0392b;padding:26px 26px 22px;text-align:center;' +
            "font-family:'Outfit',system-ui,sans-serif;box-shadow:0 24px 60px rgba(0,0,0,.35);" +
            'animation:mRulePop .2s cubic-bezier(.2,.8,.3,1.2);}' +
            '@keyframes mRulePop{from{transform:translateY(12px) scale(.97);opacity:.4}to{transform:none;opacity:1}}' +
            '.m-rule-icon{font-size:52px;line-height:1;margin-bottom:6px;}' +
            '.m-rule-title{margin:0 0 10px;font-size:22px;font-weight:800;color:#b3261e;}' +
            '.m-rule-lead{margin:0 0 14px;font-size:15.5px;line-height:1.5;}' +
            '.m-rule-time{color:#6b7280;font-weight:600;}' +
            '.m-rule-box{background:#fdecea;border:1px solid #f5c6c0;color:#7a1c14;border-radius:12px;' +
            'padding:12px 14px;font-size:14.5px;line-height:1.5;margin-bottom:14px;}' +
            '.m-rule-help{text-align:left;background:#f4f6f8;border-radius:12px;padding:12px 14px 12px;margin-bottom:18px;}' +
            '.m-rule-help-title{display:block;font-weight:700;font-size:13.5px;color:#374151;margin-bottom:6px;}' +
            '.m-rule-help ul{margin:0;padding-left:18px;}' +
            '.m-rule-help li{font-size:14px;line-height:1.55;margin-bottom:3px;color:#374151;}' +
            '.m-rule-ok{display:inline-block;border:0;cursor:pointer;background:#2C4C74;color:#fff;' +
            'font-weight:700;font-size:15px;padding:12px 34px;border-radius:10px;font-family:inherit;}' +
            '.m-rule-ok:hover{background:#22395a;}' +
            '@media(max-width:520px){.m-rule-card{padding:22px 18px 18px;}.m-rule-title{font-size:20px;}.m-rule-icon{font-size:46px;}}';
        var style = document.createElement('style');
        style.id = 'm-rule-modal-css';
        style.textContent = css;
        document.head.appendChild(style);
    }

    // Aviso grande y detallado cuando la reserva se rechaza por la regla de anticipación mínima.
    // Ocupa la pantalla (overlay) para que el docente lo lea y entienda por qué no pudo reservarse.
    function showBlockRuleModal(data) {
        ensureRuleModalCss();
        data = data || {};
        var lead = Number(data.min_lead_minutes) || 30;
        var mins = Number(data.minutes_to_start);
        var label = data.block_label || 'Este bloque';
        var horario = data.block_start
            ? (data.block_start + (data.block_end ? ('–' + data.block_end) : ''))
            : '';
        var cuando;
        if (isFinite(mins) && mins >= 0) {
            cuando = 'Faltan solo <strong>' + mins + ' ' + (mins === 1 ? 'minuto' : 'minutos') + '</strong> para que empiece.';
        } else if (isFinite(mins)) {
            var atraso = Math.abs(mins);
            cuando = 'Ese bloque <strong>ya comenzó</strong> hace ' + atraso + ' ' + (atraso === 1 ? 'minuto' : 'minutos') + '.';
        } else {
            cuando = 'Ese bloque está por comenzar.';
        }

        // Elimina un modal previo si quedó abierto.
        var prev = app.querySelector('[data-rule-modal]');
        if (prev) prev.parentNode.removeChild(prev);

        var wrap = document.createElement('div');
        wrap.className = 'm-modal m-modal--rule is-open';
        wrap.setAttribute('data-rule-modal', '');
        wrap.setAttribute('role', 'alertdialog');
        wrap.setAttribute('aria-modal', 'true');
        wrap.innerHTML =
            '<div class="m-modal-card m-rule-card">' +
                '<div class="m-rule-icon" aria-hidden="true">⛔</div>' +
                '<h2 class="m-rule-title">No alcanzas a reservar este bloque</h2>' +
                '<p class="m-rule-lead">' +
                    '<strong>' + escapeHtml(label) + '</strong>' +
                    (horario ? ' <span class="m-rule-time">(' + escapeHtml(horario) + ')</span>' : '') +
                    '. ' + cuando +
                '</p>' +
                '<div class="m-rule-box">' +
                    'Las reservas deben hacerse con <strong>al menos ' + lead + ' minutos de anticipación</strong>. ' +
                    'Esta sala se coordina por adelantado: no se puede anotar sobre la hora.' +
                '</div>' +
                '<div class="m-rule-help">' +
                    '<span class="m-rule-help-title">¿Qué puedes hacer?</span>' +
                    '<ul>' +
                        '<li>Reservar un bloque <strong>más tarde</strong> en el día.</li>' +
                        '<li>Reservar para <strong>otro día</strong> con tiempo.</li>' +
                        '<li>Si es urgente, pedir a <strong>coordinación</strong> que registre el bloque por ti.</li>' +
                    '</ul>' +
                '</div>' +
                '<button type="button" class="m-btn m-btn--primary m-rule-ok" data-close-rule>Entendido</button>' +
            '</div>';
        app.appendChild(wrap);

        function close() {
            document.removeEventListener('keydown', onKey);
            if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
        }
        function onKey(e) { if (e.key === 'Escape') close(); }
        wrap.addEventListener('click', function (e) {
            if (e.target === wrap || (e.target && e.target.hasAttribute('data-close-rule'))) close();
        });
        document.addEventListener('keydown', onKey);
        var okBtn = wrap.querySelector('[data-close-rule]');
        if (okBtn) okBtn.focus();

        // Deja además un rastro persistente arriba, por si cierran el modal sin leer del todo.
        showStatus({
            icon: '⛔',
            title: 'Reserva no permitida: falta anticipación',
            body: (data.message || ('El bloque empieza en menos de ' + lead + ' minutos.')),
            meta: ['Mínimo ' + lead + ' minutos antes del inicio']
        }, 'error');
    }

    async function saveHolidayRow() {
        var dateInput = app.querySelector('[data-holiday-date]');
        var labelInput = app.querySelector('[data-holiday-label]');
        if (!dateInput || !labelInput) {
            return;
        }
        var date = (dateInput.value || '').trim();
        var label = (labelInput.value || '').trim();
        if (!date || !label) {
            showStatus('Indica fecha y motivo del día especial.', 'error');
            return;
        }
        var data = await postJsonAction('save_holiday', {
            date: date,
            label: label,
            csrf_token: state.csrfToken
        });
        state.customHolidays = data.custom_holidays || state.customHolidays;
        await loadMonth();
        showStatus(data.message || 'Día especial guardado.', 'ok');
    }

    async function removeHolidayRow(dateKey) {
        var data = await postJsonAction('remove_holiday', {
            date: dateKey,
            csrf_token: state.csrfToken
        });
        state.customHolidays = data.custom_holidays || {};
        await loadMonth();
        showStatus(data.message || 'Día especial eliminado.', 'ok');
    }

    function openIncidenceModal(slotId) {
        var modal = app.querySelector('[data-incidence-modal]');
        var form = app.querySelector('[data-incidence-form]');
        var summary = app.querySelector('[data-incidence-summary]');
        var reservation = slotReservation(state.selectedDate, slotId) || {};
        state.incidenceSlotId = slotId;
        if (form) {
            form.reset();
        }
        if (summary) {
            summary.textContent = slotLabel(slotId) + ' · ' + (state.selectedDate || '') + ' · ' + courseDisplay(reservation);
        }
        if (modal) {
            modal.classList.add('is-open');
        }
    }

    function closeIncidenceModal() {
        var modal = app.querySelector('[data-incidence-modal]');
        if (modal) {
            modal.classList.remove('is-open');
        }
        state.incidenceSlotId = '';
    }

    function incidenceValue(name) {
        var field = app.querySelector('[data-incidence-field="' + name + '"]');
        return field ? (field.value || '').trim() : '';
    }

    async function submitIncidence() {
        var slotId = state.incidenceSlotId;
        var detail = incidenceValue('detalle');
        if (!slotId || !detail) {
            showStatus({
                icon: '!',
                title: 'Falta el detalle de la incidencia',
                body: 'Escribe qué ocurrió para poder dejar un registro útil.'
            }, 'error');
            return;
        }
        var data = await fetchJson('/admin/calendar_api.php?action=report_incidence', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                room: state.room,
                date: state.selectedDate,
                slot_id: slotId,
                categoria: incidenceValue('categoria'),
                prioridad: incidenceValue('prioridad'),
                puesto: incidenceValue('puesto'),
                continuidad: incidenceValue('continuidad'),
                detalle: detail,
                accion: incidenceValue('accion'),
                csrf_token: state.csrfToken
            })
        });
        closeIncidenceModal();
        showStatus({
            icon: '✓',
            title: data.message || 'Incidencia registrada correctamente',
            body: 'Quedó guardada con fecha, sala, bloque, usuario y detalle técnico.',
            meta: [slotLabel(slotId), incidenceValue('prioridad') || 'Prioridad Media', mailStatusLabel(data), data.mail_notice || 'Aviso enviado a soporte'].filter(Boolean)
        }, 'ok');
    }

    async function openSeatMap(slotId) {
        var data = await fetchJson('/admin/calendar_api.php?' + queryString({
            action: 'seat_map',
            room: state.room,
            date: state.selectedDate,
            slot_id: slotId
        }));
        var reservation = data.reservation || {};
        var seats = data.seats || [];
        var modal = app.querySelector('[data-seat-modal]');
        var host = app.querySelector('[data-seat-content]');
        host.innerHTML =
            '<div class="m-seat-modal-head">' +
                '<div><strong>Mapa de 40 puestos</strong><p class="m-seat-summary">Curso: ' + escapeHtml(courseDisplay(reservation)) + ' · Asignatura: ' + escapeHtml(reservation.asignatura || 'Sin asignatura') + '</p></div>' +
                '<button class="m-btn" data-close-map type="button">Cerrar</button>' +
            '</div>' +
            '<div class="m-seat-grid">' +
            seats.map(function (seat) {
                return '<div class="m-seat"><strong>Puesto ' + seat.puesto + '</strong>' + escapeHtml(seat.alumno || 'Sin asignar') + '</div>';
            }).join('') +
            '</div>';
        modal.classList.add('is-open');
    }

    app.addEventListener('click', function (event) {
        var dayBtn = event.target.closest('[data-date]');
        if (dayBtn) {
            var picked = dayBtn.getAttribute('data-date') || '';
            if (isWeekendKey(picked)) {
                return;
            }
            captureVisibleDrafts();
            state.selectedDate = picked;
            clearStatus();
            renderMonth();
            renderDayPanel();
            focusDayPanel();
            return;
        }

        var navBtn = event.target.closest('[data-nav]');
        if (navBtn) {
            captureVisibleDrafts();
            var dir = navBtn.getAttribute('data-nav');
            state.month += dir === 'next' ? 1 : -1;
            if (state.month < 0) { state.month = 11; state.year -= 1; }
            if (state.month > 11) { state.month = 0; state.year += 1; }
            loadMonth().catch(function (error) { showStatus(error.message, 'error'); });
            return;
        }

        var roomBtn = event.target.closest('[data-room]');
        if (roomBtn) {
            captureVisibleDrafts();
            state.room = roomBtn.getAttribute('data-room') || 'basica';
            loadMonth().catch(function (error) { showStatus(error.message, 'error'); });
            return;
        }

        var actionBtn = event.target.closest('[data-action]');
        if (actionBtn) {
            var action = actionBtn.getAttribute('data-action');
            var slotId = actionBtn.getAttribute('data-slot') || '';
            if (action === 'save-slot') saveSlot(slotId, 'save').catch(function (error) { showStatus(error.message, 'error'); });
            if (action === 'clear-slot') saveSlot(slotId, 'clear').catch(function (error) { showStatus(error.message, 'error'); });
            if (action === 'request-slot') saveSlot(slotId, 'request').catch(function (error) { showStatus(error.message, 'error'); });
            if (action === 'report-slot') openIncidenceModal(slotId);
            if (action === 'map-slot') openSeatMap(slotId).catch(function (error) { showStatus(error.message, 'error'); });
            if (action === 'approve-request') respondRequest(actionBtn.getAttribute('data-request'), 'approve').catch(function (error) { showStatus(error.message, 'error'); });
            if (action === 'reject-request') respondRequest(actionBtn.getAttribute('data-request'), 'reject').catch(function (error) { showStatus(error.message, 'error'); });
            if (action === 'save-holiday') saveHolidayRow().catch(function (error) { showStatus(error.message, 'error'); });
            if (action === 'remove-holiday') {
                var hd = actionBtn.getAttribute('data-holiday-date') || '';
                if (hd) removeHolidayRow(hd).catch(function (error) { showStatus(error.message, 'error'); });
            }
            return;
        }

        if (event.target.closest('[data-close-map]')) {
            app.querySelector('[data-seat-modal]').classList.remove('is-open');
        }

        if (event.target.closest('[data-close-incidence]')) {
            closeIncidenceModal();
        }
    });

    app.addEventListener('toggle', function (event) {
        var opened = event.target;
        if (!opened.matches || !opened.matches('details[data-slot]') || !opened.open) return;
        app.querySelectorAll('details[data-slot][open]').forEach(function (other) {
            if (other === opened) return;
            saveDraftFromCard(other, false);
            other.open = false;
        });
    }, true);

    app.addEventListener('input', function (event) {
        if (!event.target.matches('[data-day-blocks] [data-field]')) return;
        saveDraftFromCard(event.target.closest('[data-slot]'), true);
    });

    app.addEventListener('change', function (event) {
        if (!event.target.matches('[data-day-blocks] [data-field]')) return;
        saveDraftFromCard(event.target.closest('[data-slot]'), true);
    });

    app.addEventListener('submit', function (event) {
        if (event.target.matches('[data-incidence-form]')) {
            event.preventDefault();
            submitIncidence().catch(function (error) {
                showStatus({
                    icon: '!',
                    title: 'No se pudo guardar la incidencia',
                    body: error.message || 'Intenta nuevamente.'
                }, 'error');
            });
        }
    });

    /**
     * El calendario suele quedar abierto toda la jornada en la sala. Este
     * repaso por minuto libera los bloques cuyo horario acaba de terminar sin
     * exigir recarga, y no toca el servidor: solo vuelve a pintar.
     */
    function watchFinishedSlots() {
        var ultimaFirma = '';
        window.setInterval(function () {
            try {
                if (!state.slots.length) return;
                // Firma de los bloques ya cumplidos del día visible y del mes.
                var firma = state.slots.map(function (slot) {
                    return isSlotFinished(state.selectedDate, slot) ? '1' : '0';
                }).join('') + '|' + dateKey(new Date());
                if (firma === ultimaFirma) return;
                ultimaFirma = firma;
                captureVisibleDrafts();
                renderMonth();
                renderDayPanel();
            } catch (e) {
                // Un fallo del repaso nunca debe dejar el calendario inservible.
            }
        }, 60000);
    }

    /**
     * Sincroniza reservas creadas por otra persona mientras la pestaña sigue
     * abierta. Si hay un formulario en edición, espera para no interrumpirlo.
     */
    function watchRemoteReservations() {
        var refreshInProgress = false;

        function hasActiveEditor() {
            if (app.querySelector('[data-draft-dirty="1"]')) return true;
            var active = document.activeElement;
            return !!(active && app.contains(active) && active.matches('input, select, textarea'));
        }

        async function refreshWhenSafe() {
            if (refreshInProgress || document.hidden || hasActiveEditor()) return;
            refreshInProgress = true;
            try {
                await loadMonth();
            } catch (error) {
                // Conserva la última vista válida ante un corte momentáneo.
            } finally {
                refreshInProgress = false;
            }
        }

        window.setInterval(refreshWhenSafe, 30000);
        window.addEventListener('focus', refreshWhenSafe);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) refreshWhenSafe();
        });
    }

    // =====================================================
    // === Campana de notificaciones + avisos del navegador
    // =====================================================
    function ensureBellCss() {
        if (document.getElementById('m-bell-css')) return;
        var css =
            '.m-bell-wrap{position:relative;display:inline-flex}' +
            '.m-bell{position:relative;background:transparent;border:0;cursor:pointer;font-size:20px;line-height:1;padding:6px;border-radius:8px}' +
            '.m-bell:hover{background:rgba(0,0,0,.06)}' +
            ".m-bell-badge{position:absolute;top:-2px;right:-2px;min-width:16px;height:16px;padding:0 4px;background:#b3261e;color:#fff;border-radius:999px;font-size:10px;font-weight:700;line-height:16px;text-align:center;font-family:'Outfit',system-ui,sans-serif}" +
            ".m-notif-panel{position:absolute;top:calc(100% + 8px);right:0;width:320px;max-width:86vw;max-height:70vh;overflow:auto;background:#fff;border-radius:12px;box-shadow:0 16px 40px rgba(0,0,0,.28);z-index:9997;font-family:'Outfit',system-ui,sans-serif;border:1px solid #e5e9ee}" +
            '.m-notif-head{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid #eef1f4;position:sticky;top:0;background:#fff}' +
            '.m-notif-head strong{font-size:14px;color:#1f2937}' +
            '.m-notif-readall{border:0;background:transparent;color:#2C4C74;font-weight:700;font-size:12px;cursor:pointer;font-family:inherit}' +
            '.m-notif-empty{padding:22px 14px;text-align:center;color:#8a94a0;font-size:13px}' +
            '.m-notif-list{list-style:none;margin:0;padding:0}' +
            '.m-notif-item{padding:11px 14px;border-bottom:1px solid #f1f4f7}' +
            '.m-notif-item.is-unread{background:#f4f8ff}' +
            '.m-notif-title{font-size:13.5px;font-weight:700;color:#1f2937;margin-bottom:2px}' +
            '.m-notif-body{font-size:12.5px;color:#4b5563;line-height:1.45}' +
            '.m-notif-meta{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}' +
            '.m-notif-meta span{background:#eef1f4;color:#54606e;border-radius:999px;padding:2px 8px;font-size:11px}' +
            '.m-notif-time{font-size:11px;color:#9aa4b0;margin-top:5px}';
        var s = document.createElement('style');
        s.id = 'm-bell-css';
        s.textContent = css;
        document.head.appendChild(s);
    }

    function bellEls() {
        return {
            btn: app.querySelector('[data-notif-toggle]'),
            badge: app.querySelector('[data-notif-badge]'),
            panel: app.querySelector('[data-notif-panel]')
        };
    }

    function renderBell() {
        var e = bellEls();
        if (!e.badge || !e.btn) return;
        var n = state.notifUnread || 0;
        if (n > 0) {
            e.badge.textContent = n > 99 ? '99+' : String(n);
            e.badge.hidden = false;
        } else {
            e.badge.hidden = true;
        }
    }

    function formatNotifTime(iso) {
        try {
            return new Date(iso).toLocaleString('es-CL', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
        } catch (e) { return ''; }
    }

    function renderNotifPanel() {
        var e = bellEls();
        if (!e.panel) return;
        var items = state.notifItems || [];
        var head = '<div class="m-notif-head"><strong>Notificaciones</strong>' +
            (items.length ? '<button type="button" class="m-notif-readall" data-notif-readall>Marcar leídas</button>' : '') + '</div>';
        var body;
        if (!items.length) {
            body = '<div class="m-notif-empty">Sin notificaciones por ahora.</div>';
        } else {
            body = '<ul class="m-notif-list">' + items.map(function (n) {
                var metaHtml = (n.meta && n.meta.length)
                    ? '<div class="m-notif-meta">' + n.meta.map(function (m) { return '<span>' + escapeHtml(m) + '</span>'; }).join('') + '</div>'
                    : '';
                return '<li class="m-notif-item' + (n.read ? '' : ' is-unread') + '">' +
                    '<div class="m-notif-title">' + escapeHtml(n.title || '') + '</div>' +
                    '<div class="m-notif-body">' + escapeHtml(n.body || '') + '</div>' +
                    metaHtml +
                    '<div class="m-notif-time">' + escapeHtml(formatNotifTime(n.created_at)) + '</div>' +
                    '</li>';
            }).join('') + '</ul>';
        }
        e.panel.innerHTML = head + body;
    }

    function openNotifPanel(force) {
        var e = bellEls();
        if (!e.panel || !e.btn) return;
        var willOpen = typeof force === 'boolean' ? force : e.panel.hidden;
        e.panel.hidden = !willOpen;
        e.btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen) {
            renderNotifPanel();
            if (state.notifUnread) markNotifRead();
        }
    }

    async function fetchNotifications(isPoll) {
        try {
            var data = await fetchJson('/admin/calendar_api.php?action=notifications');
            var prevMax = state.notifSeenMaxId || 0;
            state.notifItems = data.notifications || [];
            state.notifUnread = data.unread || 0;
            state.vapidKey = data.vapid_public_key || null;
            var maxId = 0, fresh = [];
            state.notifItems.forEach(function (n) {
                if (n.id > maxId) maxId = n.id;
                if (prevMax && n.id > prevMax && !n.read) fresh.push(n);
            });
            state.notifSeenMaxId = maxId;
            renderBell();
            var e = bellEls();
            if (e.panel && !e.panel.hidden) renderNotifPanel();
            if (isPoll && fresh.length) maybeNotifyBrowser(fresh);
            if (state.vapidKey) ensurePushSubscription();
        } catch (e) { /* el polling nunca debe romper el calendario */ }
    }

    async function markNotifRead() {
        try {
            await postJsonAction('notifications_read', { csrf_token: state.csrfToken });
            state.notifUnread = 0;
            (state.notifItems || []).forEach(function (n) { n.read = true; });
            renderBell();
        } catch (e) {}
    }

    function maybeNotifyBrowser(items) {
        try {
            if (!('Notification' in window) || Notification.permission !== 'granted') return;
            items.slice(0, 3).forEach(function (n) {
                new Notification(n.title || 'Sala de computación', {
                    body: n.body || '',
                    tag: 'castel-notif-' + n.id,
                    icon: '/admin/calendar-icon.svg'
                });
            });
        } catch (e) {}
    }

    function requestNotifPermission() {
        try {
            if (('Notification' in window) && Notification.permission === 'default') {
                Notification.requestPermission();
            }
        } catch (e) {}
    }

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(base64);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    // Suscribe al navegador a Web Push si hay clave VAPID configurada. Sin ella,
    // quedan la campana in-app y los avisos del navegador en primer plano.
    async function ensurePushSubscription() {
        if (state.__pushTried || !state.vapidKey) return;
        state.__pushTried = true;
        try {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
            if (Notification.permission !== 'granted') return;
            var reg = await navigator.serviceWorker.ready;
            var sub = await reg.pushManager.getSubscription();
            if (!sub) {
                sub = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(state.vapidKey)
                });
            }
            await postJsonAction('save_push_subscription', {
                csrf_token: state.csrfToken,
                subscription: sub.toJSON ? sub.toJSON() : sub
            });
        } catch (e) { /* push opcional */ }
    }

    function initNotifications() {
        ensureBellCss();
        var e = bellEls();
        if (e.btn) {
            e.btn.addEventListener('click', function (ev) { ev.stopPropagation(); openNotifPanel(); });
        }
        if (e.panel) {
            e.panel.addEventListener('click', function (ev) {
                if (ev.target && ev.target.hasAttribute('data-notif-readall')) {
                    ev.stopPropagation();
                    markNotifRead();
                    renderNotifPanel();
                }
            });
        }
        document.addEventListener('click', function (ev) {
            var p = bellEls();
            if (p.panel && !p.panel.hidden && p.btn) {
                if (!p.panel.contains(ev.target) && ev.target !== p.btn && !p.btn.contains(ev.target)) {
                    openNotifPanel(false);
                }
            }
        });
        requestNotifPermission();
        fetchNotifications(false);
        window.setInterval(function () { fetchNotifications(true); }, 30000);
        window.addEventListener('focus', function () { fetchNotifications(true); });
    }

    renderSkeleton();
    bindNotifyCheckbox();
    watchFinishedSlots();
    watchRemoteReservations();
    initNotifications();
    loadMonth().catch(function (error) {
        showStatus(error.message || 'No se pudo cargar la vista mensual.', 'error');
    });
})();
