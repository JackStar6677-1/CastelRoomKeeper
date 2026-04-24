(function () {
    var boot = window.CASTEL_CALENDAR_BOOT || {};
    /** Tema: calendar.php solo carga este script (calendar_app.js no corre aquí). */
    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.textContent = theme === 'dark' ? 'Claro' : 'Oscuro';
        });
        try {
            localStorage.setItem('castel-theme', theme);
        } catch (error) {}
    }
    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var current = document.documentElement.getAttribute('data-theme') || 'light';
            setTheme(current === 'dark' ? 'light' : 'dark');
        });
    });
    setTheme(document.documentElement.getAttribute('data-theme') || 'light');

    var app = document.querySelector('[data-calendar-month-app]');
    if (!app) return;

    var ROOMS = [
        { id: 'basica', label: 'Sala Básica' },
        { id: 'media', label: 'Sala Media' }
    ];
    var MONTH_NAMES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    var now = new Date();
    var state = {
        year: now.getFullYear(),
        month: now.getMonth(),
        room: 'basica',
        selectedDate: '',
        csrfToken: boot.csrfToken || '',
        currentUser: boot.currentUser || { email: '', name: '', role: 'profesor' },
        canOverride: false,
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
        docenteDefault: 'Pablo Elías Avendaño Miranda',
        statusColors: {},
        jornadaTi: null,
        reservas: {},
        dayBadges: {},
        pendingRequests: [],
        customHolidays: {},
        holidaysInMonth: {},
        holidayLookup: {}
    };

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
        });
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
                return ' Se envió un aviso por correo.';
            }
            return ' No se pudo enviar el correo (revisa configuración SMTP).';
        }
        if (data.send_email_requested === false) {
            return ' Aviso por correo desactivado (casilla arriba).';
        }
        return '';
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
            throw new Error(data.message || 'No se pudo completar la operación.');
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

    function showStatus(message, type) {
        var box = app.querySelector('[data-status]');
        if (!box) return;
        box.textContent = message;
        box.className = 'm-status is-visible ' + (type === 'error' ? 'is-error' : 'is-ok');
    }

    function clearStatus() {
        var box = app.querySelector('[data-status]');
        if (!box) return;
        box.className = 'm-status';
        box.textContent = '';
    }

    function injectStyles() {
        if (document.getElementById('ccg-month-styles')) return;
        var style = document.createElement('style');
        style.id = 'ccg-month-styles';
        style.textContent =
            '[data-calendar-month-app] .m-shell{display:grid;gap:16px;width:100%;max-width:none;color:#e8f4ff}' +
            '[data-calendar-month-app] .m-status{display:none;padding:11px 14px;border-radius:10px;font-weight:700}' +
            '[data-calendar-month-app] .m-status.is-visible{display:block}' +
            '[data-calendar-month-app] .m-status.is-ok{background:rgba(36,170,112,.28);color:#d4ffe8;border:1px solid rgba(120,220,170,.25)}' +
            '[data-calendar-month-app] .m-status.is-error{background:rgba(210,80,80,.25);color:#ffe2e2;border:1px solid rgba(255,150,150,.2)}' +
            '[data-calendar-month-app] .m-teacher-panel ul{margin:0 0 10px;padding-left:18px;line-height:1.45;color:#b8d2ec}' +
            '[data-calendar-month-app] .m-teacher-panel h3{margin:0 0 8px;font-size:1rem;color:#fff}' +
            '[data-calendar-month-app] .m-mail-label{display:flex;gap:10px;align-items:flex-start;cursor:pointer;font-size:.88rem;color:#cfe6ff;line-height:1.45}' +
            '[data-calendar-month-app] .m-mail-label input{margin-top:3px}' +
            '[data-calendar-month-app] .m-inline-link{color:#8ec8ff}' +
            '[data-calendar-month-app] .m-holiday-form{display:grid;gap:10px;margin-top:10px}' +
            '[data-calendar-month-app] .m-holiday-list{display:grid;gap:8px;margin-top:10px;font-size:.85rem;color:#b8d2ec}' +
            '[data-calendar-month-app] .m-holiday-row{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;align-items:center;border-bottom:1px solid rgba(123,196,255,.12);padding:6px 0}' +
            '[data-calendar-month-app] .m-toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between}' +
            '[data-calendar-month-app] .m-toolbar-left,[data-calendar-month-app] .m-toolbar-right{display:flex;gap:10px;align-items:center;flex-wrap:wrap}' +
            '[data-calendar-month-app] .m-btn{border:1px solid rgba(123,196,255,.28);background:linear-gradient(180deg,rgba(32,72,112,.95),rgba(18,48,82,.98));color:#f2f8ff;border-radius:999px;padding:10px 16px;cursor:pointer;font:inherit;font-weight:600;font-size:.9rem}' +
            '[data-calendar-month-app] .m-btn:disabled{opacity:.45;cursor:not-allowed}' +
            '[data-calendar-month-app] .m-chip{border:1px solid rgba(123,196,255,.22);border-radius:999px;background:rgba(10,28,48,.75);padding:8px 13px;color:#e6f2ff;cursor:pointer}' +
            '[data-calendar-month-app] .m-chip.is-active{background:linear-gradient(135deg,#4E8452,#3a6b3e);border-color:transparent;color:#fff}' +
            '[data-calendar-month-app] .m-toolbar-left strong[data-month-title]{font-size:clamp(1.05rem,2vw,1.35rem);font-weight:800;color:#fff}' +
            '[data-calendar-month-app] .m-grid-wrap{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(380px,1fr);gap:clamp(14px,2vw,22px);align-items:start}' +
            '[data-calendar-month-app] .m-panel{background:linear-gradient(165deg,rgba(16,38,58,.95),rgba(10,24,42,.97));border:1px solid rgba(123,196,255,.2);border-radius:16px;padding:15px;color:#e8f4ff;box-shadow:0 18px 40px rgba(2,8,18,.45)}' +
            '[data-calendar-month-app] .m-weekdays,[data-calendar-month-app] .m-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:11px}' +
            '[data-calendar-month-app] .m-weekdays div{font-size:.8rem;color:#8eb6da;text-transform:uppercase;text-align:center;font-weight:700}' +
            '[data-calendar-month-app] .m-day{min-height:clamp(104px,14vw,132px);border-radius:14px;border:1px solid rgba(123,196,255,.18);background:rgba(8,22,40,.82);color:#f4f9ff;display:flex;flex-direction:column;align-items:stretch;padding:8px 8px 10px;cursor:pointer;text-align:center}' +
            '[data-calendar-month-app] .m-day-top{display:flex;justify-content:flex-end;align-items:flex-start;min-height:1.15em}' +
            '[data-calendar-month-app] .m-day-num{flex:1;display:flex;align-items:center;justify-content:center;font-size:1.05rem;font-weight:800;line-height:1.1}' +
            '[data-calendar-month-app] .m-day-holiday-label{min-height:2.4em;font-size:.68rem;line-height:1.15;font-weight:700;color:#fff6d4;text-align:center;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word;padding:0 2px}' +
            '[data-calendar-month-app] .m-day.is-other{opacity:.38}' +
            '[data-calendar-month-app] .m-day.is-selected{outline:2px solid #4da3ff;outline-offset:1px}' +
            '[data-calendar-month-app] .m-day.is-today{border-color:#47cf9a}' +
            '[data-calendar-month-app] .m-day.is-weekend{background:rgba(44,76,116,.35);border-color:rgba(123,196,255,.12);opacity:.92}' +
            '[data-calendar-month-app] .m-day.is-weekend.is-other{opacity:.32}' +
            '[data-calendar-month-app] .m-day.is-feriado-nacional{border-color:rgba(214,170,67,.75);background:linear-gradient(180deg,rgba(88,60,132,.35),rgba(8,22,40,.88));box-shadow:inset 0 0 0 1px rgba(214,170,67,.45)}' +
            '[data-calendar-month-app] .m-day.is-feriado-interno{border-color:rgba(123,196,255,.55);background:linear-gradient(180deg,rgba(31,99,187,.32),rgba(8,22,40,.9));box-shadow:inset 0 0 0 1px rgba(123,196,255,.25)}' +
            '[data-calendar-month-app] .m-day-badge{background:#c66a2b;color:#fff;border-radius:999px;min-width:22px;padding:2px 7px;font-size:.72rem;font-weight:700}' +
            '[data-calendar-month-app] .m-blocks{display:grid;gap:14px;max-height:none;overflow:visible}' +
            '[data-calendar-month-app] .m-slot{border:1px solid rgba(123,196,255,.16);background:rgba(6,20,36,.9);border-radius:14px;padding:15px 16px;display:grid;gap:11px}' +
            '[data-calendar-month-app] .m-slot-head{display:flex;justify-content:space-between;gap:10px;align-items:center}' +
            '[data-calendar-month-app] .m-slot-head strong{font-size:1.02rem;font-weight:800;color:#fff}' +
            '[data-calendar-month-app] .m-slot-time{font-size:.88rem;color:#9ec0e5}' +
            '[data-calendar-month-app] .m-pill{border-radius:999px;padding:4px 9px;font-size:.72rem;color:#fff;font-weight:700}' +
            '[data-calendar-month-app] .m-pill.is-blocked{background:#5c6b7f}' +
            '[data-calendar-month-app] .m-row{display:grid;gap:8px}' +
            '[data-calendar-month-app] .m-row-2{display:grid;grid-template-columns:1fr 1fr;gap:8px}' +
            '[data-calendar-month-app] .m-input,[data-calendar-month-app] .m-select,[data-calendar-month-app] .m-textarea{width:100%;padding:11px 12px;border-radius:11px;border:1px solid rgba(123,196,255,.24);background:rgba(4,14,28,.92);color:#f2f8ff;font:inherit;font-size:.95rem}' +
            '[data-calendar-month-app] .m-textarea{min-height:72px;resize:vertical}' +
            '[data-calendar-month-app] .m-actions{display:flex;flex-wrap:wrap;gap:8px}' +
            '[data-calendar-month-app] .m-help{font-size:.78rem;color:#9ec0e5}' +
            '[data-calendar-month-app] .m-warning{padding:8px 10px;border-radius:10px;background:rgba(214,170,67,.18);color:#f5e0b4;font-size:.8rem;border:1px solid rgba(214,170,67,.35)}' +
            '[data-calendar-month-app] .m-modal{position:fixed;inset:0;background:rgba(4,10,20,.72);display:none;align-items:center;justify-content:center;z-index:99}' +
            '[data-calendar-month-app] .m-modal.is-open{display:flex}' +
            '[data-calendar-month-app] .m-modal-card{width:min(960px,calc(100% - 24px));max-height:86vh;overflow:auto;background:linear-gradient(165deg,#0f2438,#0a1a2c);border:1px solid rgba(123,196,255,.22);border-radius:14px;padding:15px;color:#eaf4ff}' +
            '[data-calendar-month-app] .m-seat-grid{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:7px}' +
            '[data-calendar-month-app] .m-seat{padding:8px;border-radius:8px;background:rgba(255,255,255,.06);border:1px solid rgba(123,196,255,.12);font-size:.74rem;color:#dcebf8}' +
            '[data-calendar-month-app] .m-seat strong{display:block;color:#fff}' +
            '@media(max-width:1020px){[data-calendar-month-app] .m-grid-wrap{grid-template-columns:1fr}[data-calendar-month-app] .m-row-2{grid-template-columns:1fr}}';
        document.head.appendChild(style);
    }

    function renderSkeleton() {
        var reply = escapeHtml(boot.mailReplyTo || 'avisos@colegiocastelgandolfo.cl');
        app.innerHTML =
            '<section class="m-shell">' +
                '<div class="m-status" data-status></div>' +
                '<article class="m-panel m-teacher-panel">' +
                    '<h3>Calendario mensual y avisos</h3>' +
                    '<ul>' +
                        '<li>Elige sala (Básica o Media), navega el mes y pulsa un día para ver los bloques horarios.</li>' +
                        '<li><strong>Guardar</strong> reserva el bloque a tu nombre; <strong>Liberar</strong> lo deja disponible.</li>' +
                        '<li>Si el bloque es de otro docente, usa <strong>Solicitar aprobación</strong>; el dueño (o coordinación) verá la solicitud abajo.</li>' +
                        '<li>Los correos salen con remitente de respuesta visible: <strong>' + reply + '</strong> (configuración en administración).</li>' +
                        '<li><strong>Feriados Chile</strong> y <strong>días especiales</strong> (coordinación) muestran el motivo en la celda; fines de semana van en tono distinto sin texto.</li>' +
                    '</ul>' +
                    '<label class="m-mail-label">' +
                        '<input type="checkbox" data-notify-email' + (state.notifyEmail ? ' checked' : '') + '>' +
                        '<span>Enviar avisos automáticos por correo al guardar, liberar, solicitar cambio o aprobar/rechazar (recomendado). Desmárcalo solo si no quieres notificar en esa acción.</span>' +
                    '</label>' +
                    '<p class="m-help" style="margin:10px 0 0">' +
                        '<a class="m-inline-link" href="/admin/correo-avisos.php">Correo y avisos</a> · ' +
                        '<a class="m-inline-link" href="/admin/mail-test-calendar.php">Prueba de envío SMTP</a>' +
                    '</p>' +
                '</article>' +
                '<div data-holidays-panel></div>' +
                '<div class="m-toolbar">' +
                    '<div class="m-toolbar-left">' +
                        '<button class="m-btn" type="button" data-nav="prev">&larr;</button>' +
                        '<strong data-month-title></strong>' +
                        '<button class="m-btn" type="button" data-nav="next">&rarr;</button>' +
                    '</div>' +
                    '<div class="m-toolbar-right">' +
                        '<div data-room-chips></div>' +
                    '</div>' +
                '</div>' +
                '<div class="m-grid-wrap">' +
                    '<article class="m-panel">' +
                        '<div class="m-weekdays"><div>L</div><div>M</div><div>Mi</div><div>J</div><div>V</div><div>S</div><div>D</div></div>' +
                        '<div class="m-grid" data-month-grid></div>' +
                    '</article>' +
                    '<article class="m-panel">' +
                        '<h3 data-day-title>Selecciona un día</h3>' +
                        '<div data-day-blocks class="m-blocks"><div class="m-help">Haz clic en un día del calendario mensual para ver bloques, recreos y almuerzo.</div></div>' +
                    '</article>' +
                '</div>' +
                '<article class="m-panel">' +
                    '<h3>Solicitudes de aprobación pendientes</h3>' +
                    '<div data-requests></div>' +
                '</article>' +
            '</section>' +
            '<div class="m-modal" data-seat-modal>' +
                '<div class="m-modal-card">' +
                    '<div class="m-toolbar"><strong>Mapa de 40 puestos</strong><button class="m-btn" data-close-map type="button">Cerrar</button></div>' +
                    '<div data-seat-content></div>' +
                '</div>' +
            '</div>';
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

    function buildMonthDays() {
        var first = new Date(state.year, state.month, 1);
        var last = new Date(state.year, state.month + 1, 0);
        var days = [];
        var startPad = (first.getDay() + 6) % 7;
        var i;
        for (i = 0; i < startPad; i += 1) days.push({ date: new Date(state.year, state.month, i - startPad + 1), current: false });
        for (i = 1; i <= last.getDate(); i += 1) days.push({ date: new Date(state.year, state.month, i), current: true });
        while (days.length < 42) days.push({ date: new Date(state.year, state.month + 1, days.length - (startPad + last.getDate()) + 1), current: false });
        return days;
    }

    function statusMeta(status) {
        return state.statusColors[status] || { bg: '#2f8f62', label: status || 'Disponible' };
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
            return '<button type="button" class="m-chip' + (room.id === state.room ? ' is-active' : '') + '" data-room="' + room.id + '">' + room.label + '</button>';
        }).join(' ');
    }

    function renderMonth() {
        app.querySelector('[data-month-title]').textContent = MONTH_NAMES[state.month] + ' ' + state.year;
        var grid = app.querySelector('[data-month-grid]');
        var todayKey = dateKey(new Date());
        grid.innerHTML = buildMonthDays().map(function (dayInfo) {
            var key = dateKey(dayInfo.date);
            var badge = state.dayBadges[key] || 0;
            var meta = dayCellMeta(dayInfo.date);
            var cls = 'm-day' +
                (dayInfo.current ? '' : ' is-other') +
                (state.selectedDate === key ? ' is-selected' : '') +
                (todayKey === key ? ' is-today' : '') +
                (meta.isWeekend ? ' is-weekend' : '') +
                (meta.showLabel && meta.source === 'nacional' ? ' is-feriado-nacional' : '') +
                (meta.showLabel && meta.source === 'interno' ? ' is-feriado-interno' : '');
            var titleAttr = meta.showLabel ? ' title="' + escapeHtml(meta.label) + '"' : '';
            var labelHtml = meta.showLabel ? '<span class="m-day-holiday-label">' + escapeHtml(meta.label) + '</span>' : '';
            return '<button type="button" class="' + cls + '" data-date="' + key + '"' + titleAttr + '>' +
                '<div class="m-day-top">' + (badge > 0 ? '<span class="m-day-badge">' + badge + '</span>' : '') + '</div>' +
                '<span class="m-day-num">' + dayInfo.date.getDate() + '</span>' +
                labelHtml +
            '</button>';
        }).join('');
    }

    function courseOptions(selected) {
        return ['<option value="">Curso</option>'].concat(state.cursos.map(function (course) {
            return '<option value="' + escapeHtml(course) + '"' + (selected === course ? ' selected' : '') + '>' + escapeHtml(course) + '</option>';
        })).join('');
    }

    function slotReservation(date, slotId) {
        var byDate = state.reservas[date] || {};
        return byDate[slotId] || null;
    }

    function canEdit(reservation) {
        if (!reservation) return true;
        return state.canOverride || reservation.owner_email === state.currentUser.email;
    }

    function renderDayPanel() {
        var title = app.querySelector('[data-day-title]');
        var host = app.querySelector('[data-day-blocks]');
        if (!state.selectedDate) {
            title.textContent = 'Selecciona un día';
            host.innerHTML = '<div class="m-help">Haz clic en un día del calendario mensual para ver bloques, recreos y almuerzo.</div>';
            return;
        }
        var date = parseDate(state.selectedDate);
        title.textContent = date.toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        var supportWarning = isOutsideSupportHours()
            ? '<div class="m-warning">' + escapeHtml((state.jornadaTi && state.jornadaTi.mensaje) || 'Fuera de Horario Soporte') + '</div>'
            : '';
        host.innerHTML = supportWarning + state.slots.map(function (slot) {
            var slotId = String(slot.slot_id || '');
            var reservation = slotReservation(state.selectedDate, slotId);
            var blocked = !!slot.es_bloqueado;
            var editable = canEdit(reservation);
            var status = reservation ? reservation.status : (blocked ? 'bloqueada' : 'disponible');
            var meta = statusMeta(status);
            var ownerText = reservation ? (reservation.owner_name || reservation.owner_email || '') : '';
            var disabledSave = reservation && !editable ? ' disabled' : '';

            var blockHtml =
                '<article class="m-slot" data-slot="' + escapeHtml(slotId) + '">' +
                    '<div class="m-slot-head">' +
                        '<div><strong>' + escapeHtml(slot.nombre || slotId) + '</strong><div class="m-slot-time">' + escapeHtml(slot.hora_inicio || '') + ' - ' + escapeHtml(slot.hora_fin || '') + '</div></div>' +
                        '<span class="m-pill' + (blocked ? ' is-blocked' : '') + '" style="background:' + escapeHtml(meta.bg || '#2f8f62') + '">' + escapeHtml(meta.label || status) + '</span>' +
                    '</div>';

            if (blocked) {
                blockHtml += '<div class="m-help">Recreo / almuerzo bloqueado para reservas.</div></article>';
                return blockHtml;
            }

            blockHtml +=
                '<div class="m-row">' +
                    '<input class="m-input" data-field="asignatura" placeholder="Asignatura" value="' + escapeHtml((reservation && reservation.asignatura) || '') + '">' +
                '</div>' +
                '<div class="m-row-2">' +
                    '<select class="m-select" data-field="curso">' + courseOptions((reservation && reservation.curso) || '') + '</select>' +
                    '<input class="m-input" data-field="docente" placeholder="Docente responsable" value="' + escapeHtml((reservation && reservation.docente) || ((state.currentUser.role === 'admin' && !reservation) ? state.docenteDefault : '')) + '">' +
                '</div>' +
                '<div class="m-row"><textarea class="m-textarea" data-field="notes" placeholder="Observaciones">' + escapeHtml((reservation && reservation.notes) || '') + '</textarea></div>' +
                (reservation ? '<div class="m-help">Propietario: ' + escapeHtml(ownerText) + '</div>' : '') +
                (!editable ? '<div class="m-warning">Bloque reservado por otro usuario. Solo puedes solicitar aprobación.</div>' : '') +
                '<div class="m-actions">' +
                    '<button class="m-btn" type="button" data-action="save-slot" data-slot="' + escapeHtml(slotId) + '" data-version="' + escapeHtml(String((reservation && reservation.version) || 0)) + '"' + disabledSave + '>Guardar</button>' +
                    '<button class="m-btn" type="button" data-action="clear-slot" data-slot="' + escapeHtml(slotId) + '"' + (reservation && editable ? '' : ' disabled') + '>Liberar</button>' +
                    '<button class="m-btn" type="button" data-action="request-slot" data-slot="' + escapeHtml(slotId) + '"' + (reservation && !editable ? '' : ' disabled') + '>Solicitar aprobación</button>' +
                    '<button class="m-btn" type="button" data-action="report-slot" data-slot="' + escapeHtml(slotId) + '"' + (reservation ? '' : ' disabled') + '>Reportar Incidencia de Puesto</button>' +
                    '<button class="m-btn" type="button" data-action="map-slot" data-slot="' + escapeHtml(slotId) + '"' + (reservation ? '' : ' disabled') + '>Mapa 40 puestos</button>' +
                '</div>' +
                '</article>';

            return blockHtml;
        }).join('');
    }

    function renderPendingRequests() {
        var host = app.querySelector('[data-requests]');
        if (!state.pendingRequests.length) {
            host.innerHTML = '<div class="m-help">Sin solicitudes pendientes en este mes.</div>';
            return;
        }
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

    function payloadFromCard(slotId, mode) {
        var card = getSlotCard(slotId);
        var status = mode === 'clear' ? 'disponible' : 'reservada';
        return {
            room: state.room,
            date: state.selectedDate,
            slot_id: slotId,
            status: status,
            asignatura: mode === 'clear' ? '' : (card.querySelector('[data-field="asignatura"]').value || '').trim(),
            curso: mode === 'clear' ? '' : card.querySelector('[data-field="curso"]').value,
            docente: mode === 'clear' ? '' : (card.querySelector('[data-field="docente"]').value || '').trim(),
            notes: mode === 'clear' ? '' : (card.querySelector('[data-field="notes"]').value || '').trim(),
            version: Number((card.querySelector('[data-action="save-slot"]') || {}).getAttribute('data-version') || 0),
            csrf_token: state.csrfToken,
            send_email: state.notifyEmail
        };
    }

    async function loadMonth() {
        var data = await fetchJson('/admin/calendar_api.php?' + queryString({
            action: 'load_blocks',
            year: state.year,
            month: state.month + 1,
            room: state.room
        }));
        state.csrfToken = data.csrf_token || state.csrfToken;
        state.currentUser = data.user || state.currentUser;
        state.canOverride = !!(data.user && data.user.can_override);
        state.slots = data.slots || [];
        state.cursos = data.cursos || [];
        state.docenteDefault = data.docente_default || state.docenteDefault;
        state.statusColors = data.status_colors || {};
        state.jornadaTi = data.jornada_ti || null;
        state.reservas = data.reservas || {};
        state.dayBadges = data.day_badges || {};
        state.pendingRequests = data.pending_requests || [];
        state.holidaysInMonth = data.custom_holidays_in_month || {};
        state.canManageHolidays = !!(data.user && data.user.can_manage_holidays);
        state.customHolidays = state.canManageHolidays ? (data.custom_holidays_for_year || {}) : {};
        rebuildHolidayLookup();
        renderRooms();
        renderMonth();
        renderDayPanel();
        renderPendingRequests();
        renderHolidaysPanel();
    }

    async function saveSlot(slotId, mode) {
        var action = mode === 'request' ? 'request_block_change' : 'save_block';
        var payload = payloadFromCard(slotId, mode === 'clear' ? 'clear' : 'save');
        if (mode === 'request') {
            var reason = window.prompt('Motivo de la solicitud de aprobación:');
            if (!reason) return;
            payload.reason = reason.trim();
        }
        var data = await fetchJson('/admin/calendar_api.php?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        await loadMonth();
        var base = mode === 'request' ? (data.message || 'Solicitud enviada.') : (data.message || 'Bloque actualizado.');
        showStatus(base + mailResultNote(data), 'ok');
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
        showStatus(base + mailResultNote(data), 'ok');
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

    async function reportIncidence(slotId) {
        var detail = window.prompt('Describe la incidencia del puesto:');
        if (!detail) return;
        await fetchJson('/admin/calendar_api.php?action=report_incidence', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                room: state.room,
                date: state.selectedDate,
                slot_id: slotId,
                detalle: detail.trim(),
                csrf_token: state.csrfToken
            })
        });
        showStatus('Incidencia reportada correctamente.', 'ok');
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
            '<p class="m-help"><strong>Curso:</strong> ' + escapeHtml(reservation.curso || 'Sin curso') + ' · <strong>Asignatura:</strong> ' + escapeHtml(reservation.asignatura || 'Sin asignatura') + '</p>' +
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
            state.selectedDate = dayBtn.getAttribute('data-date') || '';
            clearStatus();
            renderMonth();
            renderDayPanel();
            return;
        }

        var navBtn = event.target.closest('[data-nav]');
        if (navBtn) {
            var dir = navBtn.getAttribute('data-nav');
            state.month += dir === 'next' ? 1 : -1;
            if (state.month < 0) { state.month = 11; state.year -= 1; }
            if (state.month > 11) { state.month = 0; state.year += 1; }
            loadMonth().catch(function (error) { showStatus(error.message, 'error'); });
            return;
        }

        var roomBtn = event.target.closest('[data-room]');
        if (roomBtn) {
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
            if (action === 'report-slot') reportIncidence(slotId).catch(function (error) { showStatus(error.message, 'error'); });
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
    });

    injectStyles();
    renderSkeleton();
    bindNotifyCheckbox();
    loadMonth().catch(function (error) {
        showStatus(error.message || 'No se pudo cargar la vista mensual.', 'error');
    });
})();