<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/calendar_store.php';

admin_bootstrap_session();
admin_require_login();

$current_user  = admin_current_user();
$current_role  = admin_user_role($current_user);
$current_email = admin_normalize_email($current_user['email'] ?? '');
$can_manage    = in_array($current_role, ['admin', 'coordinacion', 'directivo'], true);
$csrf_token    = admin_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Bitácora de Incidencias | CCG Admin</title>
    <meta name="theme-color" content="#2C4C74">
    <link rel="icon" href="/admin/calendar-icon.svg" type="image/svg+xml">
    <script src="castel-theme.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --forest: #4E8452; --navy: #2C4C74; --teal: #3d8f7a; --gold: #d6aa43;
            --paper: #f0f5f1; --ink: #17304b; --muted: rgba(23,48,75,.7);
            --line: rgba(44,76,116,.14); --danger: #c44f4f; --ok: #4E8452;
            --radius-lg: 20px; --radius-md: 14px;
            --shadow: 0 16px 36px rgba(44,76,116,.12);
        }
        :root[data-theme="dark"] {
            --paper: #081625; --ink: #ecf5ff; --muted: rgba(236,245,255,.72);
            --line: rgba(148,196,255,.14); --shadow: 0 18px 42px rgba(2,8,18,.38);
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(180deg, #eaf1f2 0%, #dfe9ea 100%);
            color: var(--ink); min-height: 100vh; display: flex; flex-direction: column;
        }
        :root[data-theme="dark"] body { background: linear-gradient(180deg, #0a1420 0%, #0d1c2a 100%); }

        /* ─── Barra superior ────────────────────────────── */
        .top-bar {
            position: sticky; top: 0; z-index: 50; padding: 12px 16px;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            flex-wrap: wrap;
            background: linear-gradient(135deg, rgba(238,245,245,.92), rgba(220,232,229,.78));
            border-bottom: 1px solid var(--line);
            backdrop-filter: blur(14px);
        }
        :root[data-theme="dark"] .top-bar {
            background: linear-gradient(135deg, rgba(12,29,46,.92), rgba(16,42,58,.82));
        }
        .top-bar__logo { display: flex; align-items: center; gap: 12px; text-decoration: none; color: var(--ink); }
        .top-bar__logo img { height: 46px; width: auto; }
        .top-bar__title { font-weight: 800; font-size: 1.05rem; }
        .top-bar__sub  { font-size: .76rem; color: var(--muted); text-transform: uppercase; letter-spacing: .1em; }
        .top-bar__nav  { display: flex; gap: 8px; flex-wrap: wrap; }
        .nav-pill {
            text-decoration: none; border-radius: 999px; padding: 8px 14px;
            font: inherit; font-weight: 700; font-size: .88rem;
            color: var(--ink); background: rgba(44,76,116,.07); border: 0; cursor: pointer;
            transition: background .18s;
        }
        .nav-pill:hover { background: rgba(78,132,82,.14); }
        .nav-pill--active { background: linear-gradient(135deg,#4E8452,#3a6b3e); color: #fff; }

        /* ─── Contenido ─────────────────────────────────── */
        main { flex: 1; padding: 22px 16px 64px; max-width: min(1100px, 100%); margin: 0 auto; width: 100%; }

        h1 { margin: 0 0 4px; font-size: clamp(1.4rem,3vw,2rem); letter-spacing: -.03em; }
        .sub { color: var(--muted); margin: 0 0 20px; font-size: .96rem; }

        /* ─── Filtros ────────────────────────────────────── */
        .filters {
            display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
            margin-bottom: 18px; padding: 14px 16px;
            background: rgba(255,255,255,.7); border: 1px solid var(--line);
            border-radius: var(--radius-md); backdrop-filter: blur(10px);
        }
        :root[data-theme="dark"] .filters { background: rgba(10,25,41,.7); }
        .filters label { font-size: .85rem; font-weight: 700; color: var(--muted); }
        .filters select, .filters input {
            padding: 8px 12px; border-radius: 10px; border: 1px solid var(--line);
            background: rgba(255,255,255,.85); color: var(--ink); font: inherit; font-size: .88rem;
        }
        :root[data-theme="dark"] .filters select, :root[data-theme="dark"] .filters input {
            background: rgba(255,255,255,.05); color: var(--ink);
        }
        .filters .count { margin-left: auto; font-weight: 800; font-size: .88rem; color: var(--muted); }

        /* ─── Tarjeta incidencia ─────────────────────────── */
        .inc-list { display: grid; gap: 12px; }
        .inc-card {
            background: rgba(255,255,255,.78); border: 1px solid var(--line);
            border-radius: var(--radius-lg); padding: 16px 18px;
            box-shadow: var(--shadow);
            display: grid; gap: 10px;
        }
        :root[data-theme="dark"] .inc-card { background: rgba(10,25,41,.78); }
        .inc-card--alta  { border-left: 4px solid #c44f4f; }
        .inc-card--media { border-left: 4px solid #d6aa43; }
        .inc-card--baja  { border-left: 4px solid #4E8452; }

        .inc-head { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 10px; justify-content: space-between; }
        .inc-head__left { display: flex; flex-direction: column; gap: 3px; }
        .inc-id { font-size: .75rem; font-weight: 700; color: var(--muted); }
        .inc-title { font-weight: 800; font-size: 1rem; }
        .inc-meta { font-size: .84rem; color: var(--muted); }

        .pill {
            display: inline-flex; align-items: center; gap: 5px;
            border-radius: 999px; padding: 4px 10px; font-size: .76rem; font-weight: 700;
        }
        .pill--alta     { background: rgba(196,79,79,.15); color: #8b2020; border: 1px solid rgba(196,79,79,.25); }
        .pill--media    { background: rgba(214,170,67,.15); color: #6b4d00; border: 1px solid rgba(214,170,67,.3); }
        .pill--baja     { background: rgba(78,132,82,.15); color: #2a5e2e; border: 1px solid rgba(78,132,82,.25); }
        .pill--abierta  { background: rgba(196,79,79,.12); color: #7a2424; }
        .pill--proceso  { background: rgba(214,170,67,.15); color: #5e3e00; }
        .pill--resuelta { background: rgba(78,132,82,.12); color: #1d4e21; }
        :root[data-theme="dark"] .pill--alta     { color: #ffb4b4; }
        :root[data-theme="dark"] .pill--media    { color: #ffe08a; }
        :root[data-theme="dark"] .pill--baja     { color: #a8dba9; }
        :root[data-theme="dark"] .pill--abierta  { color: #ffbaba; }
        :root[data-theme="dark"] .pill--proceso  { color: #ffe4a0; }
        :root[data-theme="dark"] .pill--resuelta { color: #b4efb6; }

        .inc-body { display: grid; gap: 6px; font-size: .9rem; }
        .inc-body p { margin: 0; line-height: 1.45; }
        .inc-body strong { color: var(--muted); font-size: .78rem; text-transform: uppercase; letter-spacing: .08em; }

        .inc-footer { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding-top: 8px; border-top: 1px solid var(--line); }
        .inc-footer .reporter { font-size: .82rem; color: var(--muted); flex: 1; }

        /* ─── Cambiar estado (admin) ─────────────────────── */
        .status-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .status-form select {
            padding: 7px 10px; border-radius: 10px; border: 1px solid var(--line);
            background: rgba(255,255,255,.8); color: var(--ink); font: inherit; font-size: .82rem;
        }
        :root[data-theme="dark"] .status-form select { background: rgba(255,255,255,.06); color: var(--ink); }
        .btn-sm {
            border: 0; border-radius: 999px; padding: 7px 14px; font: inherit;
            font-weight: 700; font-size: .82rem; cursor: pointer;
            background: linear-gradient(135deg, var(--forest), var(--teal)); color: #fff;
        }
        .btn-sm:disabled { opacity: .5; cursor: not-allowed; }

        /* ─── Vacío ──────────────────────────────────────── */
        .empty { padding: 32px; text-align: center; color: var(--muted); border: 1px dashed var(--line); border-radius: var(--radius-lg); }

        /* ─── Toast ──────────────────────────────────────── */
        #toast {
            position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%) translateY(60px);
            background: #17452f; color: #d4ffe8; padding: 12px 20px; border-radius: 999px;
            font-weight: 700; font-size: .9rem; z-index: 9999; transition: transform .25s ease, opacity .25s;
            opacity: 0; pointer-events: none; white-space: nowrap;
        }
        #toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        #toast.is-error { background: #6b1c1c; color: #ffe4e4; }

        .theme-fab {
            position: fixed; right: 16px; bottom: 16px; z-index: 80; border: 0;
            border-radius: 999px; padding: 12px 16px; font: inherit; font-weight: 700;
            color: #fff; background: linear-gradient(135deg,rgba(44,76,116,.95),rgba(78,132,82,.9));
            cursor: pointer; box-shadow: 0 14px 28px rgba(2,8,18,.28);
        }

        @media (max-width: 640px) {
            .filters { flex-direction: column; align-items: stretch; }
            .filters .count { margin: 0; }
            .inc-head { flex-direction: column; }
        }
    </style>
</head>
<body>
    <nav class="top-bar">
        <a class="top-bar__logo" href="/admin/calendar.php">
            <img src="/app/assets/LogoCastelGandolfoSinFondo.png" alt="CCG">
            <div>
                <div class="top-bar__sub">CCG Admin</div>
                <div class="top-bar__title">Bitácora de Incidencias</div>
            </div>
        </a>
        <div class="top-bar__nav">
            <button class="nav-pill" data-theme-toggle>Oscuro</button>
            <a class="nav-pill" href="/admin/calendar.php">Calendario</a>
            <?php if ($can_manage): ?>
            <a class="nav-pill" href="/admin/usuarios.php">Usuarios</a>
            <?php endif; ?>
            <a class="nav-pill" href="/admin/editor.php">Panel</a>
            <a class="nav-pill" href="/admin/index.php?logout=1">Salir</a>
        </div>
    </nav>

    <main>
        <h1>📋 Bitácora de Incidencias</h1>
        <p class="sub">Registro de problemas reportados en sala de computación. <?php echo $can_manage ? 'Puedes actualizar el estado de cada incidencia.' : 'Solo coordinación y admin pueden cambiar el estado.'; ?></p>

        <div class="filters">
            <label>Sala
                <select id="f-room">
                    <option value="">Todas</option>
                    <option value="basica">Sala Básica</option>
                    <option value="media">Sala Media</option>
                </select>
            </label>
            <label>Estado
                <select id="f-status">
                    <option value="">Todos</option>
                    <option value="abierta">Abierta</option>
                    <option value="en_proceso">En proceso</option>
                    <option value="resuelta">Resuelta</option>
                </select>
            </label>
            <label>Prioridad
                <select id="f-priority">
                    <option value="">Todas</option>
                    <option value="Alta">Alta</option>
                    <option value="Media">Media</option>
                    <option value="Baja">Baja</option>
                </select>
            </label>
            <label>Buscar
                <input type="search" id="f-search" placeholder="Puesto, detalle…">
            </label>
            <span class="count" id="f-count">— incidencias</span>
        </div>

        <div class="inc-list" id="inc-list">
            <div class="empty">Cargando incidencias…</div>
        </div>
    </main>

    <div id="toast"></div>
    <button class="theme-fab" data-theme-toggle>Oscuro</button>

    <script>
    (function () {
        var CAN_MANAGE = <?php echo $can_manage ? 'true' : 'false'; ?>;
        var CSRF      = <?php echo json_encode($csrf_token, JSON_UNESCAPED_UNICODE); ?>;
        var ME        = <?php echo json_encode($current_email, JSON_UNESCAPED_UNICODE); ?>;

        /* ── Toast ── */
        function toast(msg, isError) {
            var el = document.getElementById('toast');
            el.textContent = msg;
            el.className = 'show' + (isError ? ' is-error' : '');
            clearTimeout(el._t);
            el._t = setTimeout(function () { el.className = ''; }, 3200);
        }

        /* ── Helpers ── */
        function esc(v) {
            return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
                return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c];
            });
        }

        function pillClass(v, type) {
            if (type === 'pri')    return { Alta:'pill--alta', Media:'pill--media', Baja:'pill--baja' }[v] || '';
            if (type === 'status') return { abierta:'pill--abierta', en_proceso:'pill--proceso', resuelta:'pill--resuelta' }[v] || '';
            return '';
        }

        function statusLabel(v) {
            return { abierta:'Abierta', en_proceso:'En proceso', resuelta:'Resuelta' }[v] || v || 'Abierta';
        }

        function roomLabel(r) { return r === 'media' ? 'Sala Media' : 'Sala Básica'; }

        function formatDate(iso) {
            if (!iso) return '—';
            try {
                return new Date(iso).toLocaleString('es-CL', { day:'numeric', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' });
            } catch (e) { return iso; }
        }

        /* ── Estado global ── */
        var allInc = [];

        function getFilters() {
            return {
                room:     document.getElementById('f-room').value,
                status:   document.getElementById('f-status').value,
                priority: document.getElementById('f-priority').value,
                search:   document.getElementById('f-search').value.toLowerCase().trim()
            };
        }

        function applyFilters(list, f) {
            return list.filter(function (inc) {
                if (f.room     && (inc.room || 'basica') !== f.room) return false;
                if (f.status   && (inc.status || 'abierta') !== f.status) return false;
                if (f.priority && (inc.prioridad || 'Media') !== f.priority) return false;
                if (f.search) {
                    var blob = [inc.categoria, inc.detalle, inc.accion, inc.puesto, inc.reporter_name, inc.reporter_email, inc.slot_id, inc.date].join(' ').toLowerCase();
                    if (!blob.includes(f.search)) return false;
                }
                return true;
            });
        }

        function cardHtml(inc) {
            var priori = inc.prioridad || 'Media';
            var status = inc.status || 'abierta';
            var id     = inc.id || '?';
            var priClass = { Alta:'inc-card--alta', Media:'inc-card--media', Baja:'inc-card--baja' }[priori] || '';

            var statusSelect = CAN_MANAGE
                ? '<form class="status-form" data-update-form data-inc-id="' + esc(id) + '">' +
                      '<select data-status-select>' +
                          '<option value="abierta"'     + (status === 'abierta'     ? ' selected' : '') + '>Abierta</option>' +
                          '<option value="en_proceso"'  + (status === 'en_proceso'  ? ' selected' : '') + '>En proceso</option>' +
                          '<option value="resuelta"'    + (status === 'resuelta'    ? ' selected' : '') + '>Resuelta</option>' +
                      '</select>' +
                      '<button class="btn-sm" type="submit">Actualizar</button>' +
                  '</form>'
                : '<span class="pill ' + pillClass(status, 'status') + '">' + esc(statusLabel(status)) + '</span>';

            return '<article class="inc-card ' + priClass + '" id="inc-' + esc(id) + '">' +
                '<div class="inc-head">' +
                    '<div class="inc-head__left">' +
                        '<span class="inc-id">#' + esc(id) + ' · ' + esc(roomLabel(inc.room || 'basica')) + ' · ' + esc(inc.date || '') + (inc.slot_id ? ' · ' + esc(inc.slot_id.toUpperCase()) : '') + '</span>' +
                        '<span class="inc-title">' + esc(inc.categoria || 'Incidencia') + '</span>' +
                        '<span class="inc-meta">' + esc(inc.asignatura ? inc.asignatura + ' · ' : '') + esc(inc.curso ? inc.curso + (inc.letra ? ' ' + inc.letra : '') : '') + '</span>' +
                    '</div>' +
                    '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">' +
                        '<span class="pill ' + pillClass(priori, 'pri') + '">' + esc(priori) + '</span>' +
                        (CAN_MANAGE ? '' : '<span class="pill ' + pillClass(status, 'status') + '">' + esc(statusLabel(status)) + '</span>') +
                    '</div>' +
                '</div>' +

                '<div class="inc-body">' +
                    (inc.puesto ? '<p><strong>Puesto / equipo</strong><br>' + esc(inc.puesto) + '</p>' : '') +
                    (inc.continuidad ? '<p><strong>¿Siguió la clase?</strong><br>' + esc(inc.continuidad) + '</p>' : '') +
                    (inc.detalle ? '<p><strong>Detalle</strong><br>' + esc(inc.detalle) + '</p>' : '') +
                    (inc.accion  ? '<p><strong>Acción realizada</strong><br>' + esc(inc.accion) + '</p>' : '') +
                    (inc.resolution_note ? '<p><strong>Nota de resolución</strong><br>' + esc(inc.resolution_note) + '</p>' : '') +
                '</div>' +

                '<div class="inc-footer">' +
                    '<span class="reporter">Reportado por <strong>' + esc(inc.reporter_name || inc.reporter_email || '—') + '</strong> el ' + esc(formatDate(inc.created_at)) + '</span>' +
                    statusSelect +
                '</div>' +
            '</article>';
        }

        function render(list) {
            var el = document.getElementById('inc-list');
            var count = document.getElementById('f-count');
            if (!list.length) {
                el.innerHTML = '<div class="empty">No hay incidencias que coincidan con los filtros.</div>';
                count.textContent = '0 incidencias';
                return;
            }
            var sorted = list.slice().sort(function (a, b) {
                return (b.id || 0) - (a.id || 0);
            });
            el.innerHTML = sorted.map(cardHtml).join('');
            count.textContent = list.length + ' incidencia' + (list.length !== 1 ? 's' : '');
        }

        function rerender() {
            render(applyFilters(allInc, getFilters()));
        }

        /* ── Carga inicial ── */
        async function loadIncidences() {
            var res  = await fetch('/admin/calendar_api.php?action=list_incidences&csrf_nonce=1');
            var data = await res.json();
            if (!data.ok) throw new Error(data.message || 'Error al cargar.');
            allInc = Array.isArray(data.incidences) ? data.incidences : [];
            rerender();
        }

        /* ── Actualizar estado ── */
        document.getElementById('inc-list').addEventListener('submit', function (e) {
            var form = e.target.closest('[data-update-form]');
            if (!form) return;
            e.preventDefault();
            var incId  = form.getAttribute('data-inc-id');
            var status = form.querySelector('[data-status-select]').value;
            var btn    = form.querySelector('button');
            btn.disabled = true;
            fetch('/admin/calendar_api.php?action=update_incidence_status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ inc_id: incId, status: status, csrf_token: CSRF })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) throw new Error(data.message || 'Error.');
                // actualiza local
                allInc = allInc.map(function (inc) {
                    return String(inc.id) === String(incId) ? Object.assign({}, inc, { status: status }) : inc;
                });
                rerender();
                toast('Estado actualizado correctamente.');
            })
            .catch(function (err) { toast(err.message, true); })
            .finally(function () { btn.disabled = false; });
        });

        /* ── Filtros ── */
        ['f-room','f-status','f-priority'].forEach(function (id) {
            document.getElementById(id).addEventListener('change', rerender);
        });
        document.getElementById('f-search').addEventListener('input', rerender);

        /* ── Theme toggle ── */
        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var g = window.CASTEL_SCHEDULED_THEME;
                if (g && typeof g.applyToDom === 'function') {
                    var cur = document.documentElement.getAttribute('data-theme');
                    g.applyToDom(cur === 'dark' ? 'light' : 'dark', true);
                }
            });
        });

        /* ── Init ── */
        loadIncidences().catch(function (err) {
            document.getElementById('inc-list').innerHTML = '<div class="empty">No se pudo cargar: ' + esc(err.message) + '</div>';
        });
    })();
    </script>
</body>
</html>
