<?php
require_once __DIR__ . '/auth.php';

admin_bootstrap_session();
admin_require_login();

$current_user = admin_current_user();
$current_email = $current_user ? $current_user['email'] : '';
$current_name = $current_user ? admin_user_display_name($current_user) : '';
$current_role = $current_user ? admin_user_role($current_user) : 'profesor';
$can_manage_site = admin_user_can_manage_site($current_user);
$can_manage_users = in_array($current_role, array('admin', 'directivo'), true);
$csrf_token = admin_csrf_token();
$cal_mail_reply = 'avisos@colegiocastelgandolfo.cl';
$mail_cfg_path = __DIR__ . '/mail_config.php';
if (is_file($mail_cfg_path)) {
    $mail_cfg = require $mail_cfg_path;
    if (is_array($mail_cfg) && !empty($mail_cfg['reply_to'])) {
        $cal_mail_reply = (string) $mail_cfg['reply_to'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Calendario Sala de Computación | CCG Admin</title>
    <meta name="theme-color" content="#18304B">
    <meta name="application-name" content="Calendario CCG">
    <meta name="apple-mobile-web-app-title" content="Calendario CCG">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="/admin/manifest.webmanifest">
    <link rel="icon" href="/admin/calendar-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/castel-app-icon.png">
    <script src="castel-theme.js"></script>
    <script>
        window.CASTEL_CALENDAR_BOOT = {
            csrfToken: <?php echo json_encode($csrf_token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            mailReplyTo: <?php echo json_encode($cal_mail_reply, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            currentUser: {
                email: <?php echo json_encode($current_email, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                name: <?php echo json_encode($current_name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                role: <?php echo json_encode($current_role, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/calendar.css?v=4">
    <style>

        /* Paleta institucional del sitio público: azul #18304B, azul medio
           #2C4C74, verde #3A6B3E, oro #C38F31, pizarra #1F2F3D.
           Diseño plano: sin degradados, sin sombras difusas, esquinas rectas. */
        :root {
            --navy: #18304B;
            --navy-2: #2C4C74;
            --forest: #3A6B3E;
            --forest-deep: #2E5632;
            --gold: #A9761F;
            --ink: #18304B;
            --muted: #4A6480;
            --page: #C7D2DB;
            --surface: #DFE6EC;
            --surface-2: #D3DCE4;
            --field: #F1F4F7;
            --line: #9FB0C0;
            --line-soft: #B5C2CE;
            --danger: #A33131;
            --warning: #A9761F;
            --ok: #3A6B3E;
            /* Ancho fluido (rem + viewport), sin tope fijo en px */
            --site-width: min(96rem, calc(100vw - max(24px, env(safe-area-inset-left, 0px) + env(safe-area-inset-right, 0px))));
        }

        :root[data-theme="dark"] {
            --navy: #0E1E30;
            --navy-2: #23405F;
            --forest: #4E8452;
            --forest-deep: #3A6B3E;
            --gold: #C38F31;
            --ink: #E6EEF6;
            --muted: #9AAFC2;
            --page: #08121C;
            --surface: #12232F;
            --surface-2: #0D1B27;
            --field: #0A161F;
            --line: #2C4560;
            --line-soft: #22384F;
            --danger: #D96A6A;
            --warning: #C38F31;
            --ok: #6FAE74;
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; overflow-x: hidden; }
        html { background: var(--page); }
        body {
            font-family: 'Outfit', sans-serif;
            color: var(--ink);
            background: var(--page);
            min-height: 100vh;
        }

        .container {
            width: min(100%, var(--site-width));
            max-width: 100%;
            margin: 0 auto;
            padding-left: max(12px, env(safe-area-inset-left, 0px));
            padding-right: max(12px, env(safe-area-inset-right, 0px));
        }

        .site-header {
            position: sticky;
            top: 0;
            z-index: 50;
            padding: 10px 0;
            background: var(--page);
        }

        .site-header__bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 10px 14px;
            min-height: 72px;
            background: var(--navy);
            border: 1px solid var(--navy);
        }

        .site-logo {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            text-decoration: none;
            color: #fff;
        }
        .site-logo img {
            height: 52px;
            width: auto;
            display: block;
            background: #fff;
            padding: 4px 6px;
        }
        .site-logo__meta { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .site-logo__eyebrow {
            font-size: 0.72rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.72);
        }
        .site-logo__name {
            font-size: 1.02rem;
            font-weight: 800;
            line-height: 1.1;
            color: #fff;
        }

        .site-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .mobile-menu-toggle {
            display: none;
            border: 1px solid rgba(255, 255, 255, 0.42);
            padding: 10px 14px;
            font: inherit;
            font-weight: 800;
            color: #fff;
            background: transparent;
            cursor: pointer;
        }
        .theme-toggle,
        .nav-link {
            border: 1px solid rgba(255, 255, 255, 0.3);
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 13px;
            font: inherit;
            font-weight: 700;
            font-size: 0.88rem;
            color: rgba(255, 255, 255, 0.9);
            background: transparent;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .theme-toggle:hover,
        .nav-link:hover { background: rgba(255, 255, 255, 0.12); color: #fff; }
        .nav-link--primary { background: var(--forest); border-color: var(--forest); color: #fff; }
        .nav-link--primary:hover { background: var(--forest-deep); border-color: var(--forest-deep); }

        .theme-fab {
            position: fixed;
            right: 16px;
            bottom: 16px;
            z-index: 80;
            border: 1px solid var(--navy);
            padding: 12px 16px;
            font: inherit;
            font-weight: 700;
            color: #fff;
            background: var(--navy);
            cursor: pointer;
        }
        .theme-fab:hover { background: var(--navy-2); border-color: var(--navy-2); }

        main { padding: 12px 0 48px; }

        .page-hero {
            padding: clamp(24px, 4vw, 40px) clamp(20px, 3vw, 40px);
            background: var(--navy);
            border-left: 4px solid var(--gold);
            color: #fff;
        }
        .page-hero__kicker {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 0.74rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            font-weight: 700;
            color: rgba(255, 255, 255, 0.7);
        }
        .page-hero h1 {
            margin: 0;
            max-width: 16ch;
            font-size: clamp(1.7rem, 3.4vw, 2.6rem);
            line-height: 1.06;
            letter-spacing: -0.035em;
        }
        .page-hero p {
            max-width: 72ch;
            margin: 12px 0 0;
            font-size: 0.96rem;
            line-height: 1.55;
            color: rgba(255, 255, 255, 0.82);
        }

        .surface {
            margin-top: 12px;
            padding: clamp(14px, 2vw, 22px);
            background: var(--surface);
            border: 1px solid var(--line);
        }

        .calendar-intro h2,
        .calendar-panel h3,
        .calendar-section__header h3 {
            margin: 8px 0 6px;
            font-size: clamp(1.15rem, 2vw, 1.4rem);
            line-height: 1.14;
            letter-spacing: -0.025em;
        }
        .kicker {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 0.74rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            font-weight: 800;
            color: var(--muted);
        }

        .calendar-shell { display: grid; gap: 18px; }
        .calendar-toolbar {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 14px;
        }
        .calendar-panel {
            padding: 18px;
            background: var(--surface-2);
            border: 1px solid var(--line);
        }
        .calendar-field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .calendar-field { display: grid; gap: 6px; }
        .calendar-field label {
            font-size: 0.8rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
        }
        .calendar-select,
        .calendar-input,
        .calendar-textarea {
            width: 100%;
            min-width: 0;
            padding: 10px 12px;
            border: 1px solid var(--line);
            background: var(--field);
            color: var(--ink);
            font: inherit;
        }
        .calendar-textarea { min-height: 94px; resize: vertical; }

        .chip-group { display: flex; flex-wrap: wrap; gap: 8px; }
        .chip {
            border: 1px solid var(--line);
            background: var(--field);
            color: var(--ink);
            padding: 9px 13px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .chip.is-active { background: var(--forest); border-color: var(--forest); color: #fff; }

        .action-row { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .button {
            border: 1px solid var(--line);
            padding: 10px 16px;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
            color: var(--ink);
            background: var(--field);
        }
        .button--primary { color: #fff; background: var(--navy); border-color: var(--navy); }
        .button--ghost { background: transparent; }
        .button--danger { color: #fff; background: var(--danger); border-color: var(--danger); }
        .button:disabled { opacity: 0.5; cursor: not-allowed; }

        .calendar-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }
        .stat {
            padding: 16px;
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-top: 3px solid var(--navy-2);
        }
        .stat__label { display: block; font-size: 0.74rem; font-weight: 800; color: var(--muted); text-transform: uppercase; letter-spacing: 0.08em; }
        .stat__value { display: block; font-size: 1.7rem; font-weight: 800; margin-top: 6px; }
        .stat__hint { display: block; margin-top: 6px; color: var(--muted); font-size: 0.88rem; }

        .calendar-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(290px, 0.95fr);
            gap: 14px;
        }
        .calendar-section,
        .calendar-aside { display: grid; gap: 14px; align-content: start; }
        .calendar-section__header { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; }

        .legend { display: flex; flex-wrap: wrap; gap: 10px; color: var(--muted); font-size: 0.82rem; }
        .legend span { display: inline-flex; align-items: center; gap: 6px; }
        .legend i { width: 10px; height: 10px; display: inline-block; }
        .legend .free { background: var(--line); }
        .legend .reserved { background: var(--gold); }
        .legend .service { background: var(--navy-2); }
        .legend .blocked { background: var(--danger); }
        .legend .holiday { background: var(--navy); }

        .week-list { display: grid; gap: 10px; }
        .week {
            border: 1px solid var(--line);
            background: var(--surface-2);
        }
        .week summary {
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 16px;
            cursor: pointer;
        }
        .week summary::-webkit-details-marker { display: none; }
        .week__title strong { display: block; font-size: 0.98rem; }
        .week__title span { display: block; margin-top: 3px; color: var(--muted); font-size: 0.9rem; }
        .week__badges { display: flex; flex-wrap: wrap; gap: 8px; }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            font-size: 0.78rem;
            font-weight: 800;
            background: var(--field);
            border: 1px solid var(--line-soft);
        }
        .week__body {
            display: grid;
            gap: 10px;
            padding: 0 16px 16px;
        }
        .day-card {
            border: 1px solid var(--line-soft);
            padding: 14px;
            background: var(--field);
        }
        .day-card.is-holiday { background: var(--navy); color: #fff; }
        .day-card__meta {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }
        .day-card__weekday { display: block; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); font-weight: 800; }
        .day-card.is-holiday .day-card__weekday,
        .day-card.is-holiday .day-card__date { color: rgba(255,255,255,0.86); }
        .day-card__date { display: block; margin-top: 4px; font-size: 0.98rem; font-weight: 800; }
        .day-card__owner { display: block; margin-top: 6px; color: var(--muted); font-size: 0.86rem; }
        .day-card__status { white-space: nowrap; }
        .day-card__grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .note {
            margin-top: 10px;
            padding: 10px 12px;
            background: var(--surface-2);
            border-left: 3px solid var(--line);
            color: var(--muted);
        }
        .warning-note {
            margin-top: 10px;
            padding: 10px 12px;
            background: var(--surface-2);
            border-left: 3px solid var(--warning);
            color: var(--ink);
            font-weight: 600;
        }
        .inline-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }

        .list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 8px;
        }
        .list li {
            padding: 12px;
            border: 1px solid var(--line-soft);
            background: var(--field);
        }
        .list strong { display: block; }
        .list span { display: block; margin-top: 4px; color: var(--muted); }
        .request-card {
            padding: 14px;
            border: 1px solid var(--line-soft);
            border-left: 3px solid var(--forest);
            background: var(--field);
        }
        .request-card__title { font-weight: 800; }
        .request-card__meta { margin-top: 4px; color: var(--muted); font-size: 0.88rem; }
        .request-card__body { margin-top: 10px; display: grid; gap: 6px; font-size: 0.93rem; }

        .status-message {
            padding: 12px 14px;
            font-weight: 700;
            margin-bottom: 12px;
            display: none;
            background: var(--surface-2);
            border: 1px solid var(--line-soft);
            border-left: 4px solid var(--line);
        }
        .status-message.is-visible { display: block; }
        .status-message.is-ok { border-left-color: var(--ok); color: var(--ink); }
        .status-message.is-error { border-left-color: var(--danger); color: var(--ink); }
        .empty {
            padding: 14px;
            border: 1px dashed var(--line);
            color: var(--muted);
        }
        .loading {
            padding: 20px;
            color: var(--muted);
        }

        .calendar-surface-single {
            background: var(--surface);
            border: 1px solid var(--line);
            border-top: 3px solid var(--navy);
        }
        .calendar-page-lead {
            margin-bottom: 14px;
            padding-bottom: 12px;
            padding-left: 12px;
            border-bottom: 1px solid var(--line-soft);
            border-left: 3px solid var(--navy-2);
        }
        .calendar-page-lead__title {
            margin: 6px 0 8px;
            font-size: clamp(1.2rem, 2.2vw, 1.5rem);
            letter-spacing: -0.02em;
        }
        .calendar-page-lead__text {
            margin: 0;
            max-width: 72ch;
            color: var(--muted);
            line-height: 1.55;
        }
        .calendar-month-mount {
            width: 100%;
            min-height: 420px;
        }

        .site-footer { padding-bottom: 28px; }
        .site-footer__panel {
            padding: 20px;
            background: var(--navy);
            color: rgba(255,255,255,0.8);
        }
        .site-footer__grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }
        .site-footer h3 { margin: 0 0 10px; color: #fff; font-size: 1rem; }
        .site-footer a { color: rgba(255,255,255,0.8); text-decoration: none; display: block; margin-top: 6px; }
        .site-footer a:hover { color: #fff; text-decoration: underline; }
        .site-footer__bottom {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.14);
            display: flex;
            justify-content: space-between;
            gap: 10px;
            color: rgba(255,255,255,0.56);
            font-size: 0.86rem;
        }

        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
        }

        @media (max-width: 1080px) {
            .calendar-toolbar,
            .calendar-layout,
            .site-footer__grid,
            .calendar-summary { grid-template-columns: 1fr; }
        }

        @media (max-width: 820px) {
            .site-header__bar { align-items: flex-start; }
            .site-actions { width: 100%; justify-content: flex-start; }
            .calendar-field-grid,
            .day-card__grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 640px) {
            .container { width: min(var(--site-width), calc(100% - 16px)); }
            .site-header { padding: 8px 0; }
            .site-header__bar {
                min-height: auto;
                padding: 10px 12px;
                display: grid;
                grid-template-columns: 1fr auto;
                align-items: center;
            }
            .site-logo img { height: 44px; }
            .site-logo__eyebrow { font-size: 0.66rem; }
            .site-logo__name { font-size: 0.94rem; }
            .mobile-menu-toggle { display: inline-flex; align-items: center; justify-content: center; }
            .site-actions {
                grid-column: 1 / -1;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px;
                max-height: 0;
                overflow: hidden;
                opacity: 0;
                pointer-events: none;
                transition: max-height .2s ease, opacity .15s ease;
            }
            .site-header__bar.is-menu-open .site-actions {
                max-height: 360px;
                opacity: 1;
                pointer-events: auto;
                padding-top: 10px;
            }
            .nav-link,
            .theme-toggle,
            .button { width: 100%; justify-content: center; min-height: 44px; }
            main { padding: 8px 0 36px; }
            .page-hero { display: none; }
            .calendar-surface-single { margin-top: 8px; }
            /* La cabecera ya dice qué herramienta es. El lead completo empujaba
               el calendario fuera de la primera pantalla. */
            .calendar-page-lead { display: none; }
            .surface { padding: 10px 8px; }
            .week summary,
            .week__body { padding-left: 12px; padding-right: 12px; }
            .day-card__meta { flex-direction: column; }
            .inline-actions { flex-direction: column; }
            /* El FAB tapaba los botones Guardar / Liberar / Incidencia.
               En móvil el cambio de tema vive dentro del menú de cabecera. */
            .theme-fab { display: none; }
            /* Pie compacto: los textos descriptivos ocupaban una pantalla
               completa bajo los bloques. Los accesos ya están en el menú. */
            .site-footer { padding-bottom: 16px; }
            .site-footer__panel { padding: 14px; }
            .site-footer__grid { gap: 0; }
            .site-footer__grid section:first-child p,
            .site-footer__grid section:nth-child(2),
            .site-footer__grid section:nth-child(3) { display: none; }
            .site-footer h3 { margin: 0; font-size: 0.92rem; }
            .site-footer__bottom { flex-direction: column; margin-top: 12px; padding-top: 12px; }
        }
    </style>
</head>
<body>
    <div class="site-shell">
        <header class="site-header">
            <div class="container">
                <div class="site-header__bar">
                    <a class="site-logo" href="<?php echo $can_manage_site ? '/admin/editor.php' : '/admin/calendar.php'; ?>">
                        <img src="/assets/LogoCastelGandolfoSinFondo.png" alt="Colegio Castelgandolfo">
                        <span class="site-logo__meta">
                            <span class="site-logo__eyebrow">CCG Admin</span>
                            <span class="site-logo__name">Calendario Sala de Computación</span>
                        </span>
                    </a>

                    <button type="button" class="mobile-menu-toggle" data-admin-menu-toggle aria-expanded="false">Menú</button>

                    <div class="site-actions">
                        <button type="button" class="theme-toggle" data-theme-toggle>Oscuro</button>
                        <button type="button" class="nav-link" data-pwa-install hidden>Instalar app</button>
                        <?php if ($can_manage_site): ?>
                        <a class="nav-link" href="/admin/editor.php">Panel</a>
                        <a class="nav-link" href="/admin/correo-avisos.php">Correo / avisos</a>
                        <a class="nav-link" href="/admin/calendar_alert_settings.php">Avisos TI</a>
                        <a class="nav-link" href="/admin/sql.php">SQL / prueba</a>
                        <a class="nav-link" href="/" target="_blank" rel="noopener">Sitio público</a>
                        <?php endif; ?>
                        <?php if ($can_manage_users): ?>
                        <a class="nav-link" href="/admin/usuarios.php">Usuarios</a>
                        <?php endif; ?>
                        <a class="nav-link nav-link--primary" href="/admin/calendar.php">Calendario</a>
                        <a class="nav-link" href="/admin/index.php?logout=1">Cerrar sesión</a>
                    </div>
                </div>
            </div>
        </header>

        <main>
            <div class="container">
                <section class="page-hero">
                    <div class="page-hero__kicker">Herramienta privada</div>
                    <h1>Calendario Sala de Computación</h1>
                    <p>Agenda interna para Sala Básica y Sala Media. Cada reserva tiene propietario, los cambios quedan registrados y las modificaciones sobre reservas ajenas requieren solicitud y aprobación.</p>
                </section>

                <section class="surface calendar-surface-single">
                    <div class="calendar-page-lead">
                        <span class="kicker">Uso en sala</span>
                        <h2 class="calendar-page-lead__title">Un solo calendario por mes y bloques</h2>
                        <p class="calendar-page-lead__text">Elige sala y día, completa cada franja de clase y guarda. Si el bloque pertenece a otro docente, usa <strong>Solicitar aprobación</strong>. Puedes activar avisos por correo (según la casilla de abajo) para que el propietario reciba un recordatorio.</p>
                    </div>
                    <div class="calendar-month-mount" data-calendar-month-app></div>
                </section>
            </div>
        </main>

        <footer class="site-footer">
            <div class="container">
                <div class="site-footer__panel">
                    <div class="site-footer__grid">
                        <section>
                            <h3>Colegio Castelgandolfo</h3>
                            <p>Herramienta privada del panel administrativo para ordenar la ocupación de las salas de computación.</p>
                        </section>
                        <section>
                            <h3>Accesos</h3>
                            <a href="/admin/calendar.php">Calendario</a>
                            <?php if ($can_manage_site): ?>
                            <a href="/admin/editor.php">Panel principal</a>
                            <a href="/admin/correo-avisos.php">Correo / avisos</a>
                            <a href="/admin/sql.php">SQL / prueba</a>
                            <a href="/" target="_blank" rel="noopener">Sitio público</a>
                            <?php endif; ?>
                            <?php if ($can_manage_users): ?>
                            <a href="/admin/usuarios.php">Usuarios</a>
                            <?php endif; ?>
                            <a href="/admin/index.php?logout=1">Cerrar sesión</a>
                        </section>
                        <section>
                            <h3>Seguridad</h3>
                            <p>Las reservas quedan con propietario, se registra auditoría y los cambios sensibles no dependen solo del navegador.</p>
                        </section>
                    </div>
                    <div class="site-footer__bottom">
                        <span>Colegio Castelgandolfo</span>
                        <span>Calendario privado · Admin</span>
                    </div>
                </div>
            </div>
        </footer>
    </div>

    <button type="button" class="theme-fab" data-theme-toggle>Oscuro</button>
    <script src="/admin/calendar_month_app.js?v=40"></script>
    <script src="/admin/pwa.js" defer></script>
    <script>
        (function () {
            var g = window.CASTEL_SCHEDULED_THEME;
            if (!g || typeof g.applyToDom !== 'function') return;
            document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var cur = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
                    g.applyToDom(cur === 'dark' ? 'light' : 'dark', true);
                });
            });
            g.updateToggleElements(document.documentElement.getAttribute('data-theme') || 'light');

            var menuToggle = document.querySelector('[data-admin-menu-toggle]');
            var headerBar = document.querySelector('.site-header__bar');
            if (menuToggle && headerBar) {
                menuToggle.addEventListener('click', function () {
                    var open = !headerBar.classList.contains('is-menu-open');
                    headerBar.classList.toggle('is-menu-open', open);
                    menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    menuToggle.textContent = open ? 'Cerrar' : 'Menú';
                });
            }
        })();
    </script>
</body>
</html>
