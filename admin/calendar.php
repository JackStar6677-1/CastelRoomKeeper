<?php
require_once __DIR__ . '/auth.php';

admin_bootstrap_session();
admin_require_login();

$current_user = admin_current_user();
$current_email = $current_user ? $current_user['email'] : '';
$current_name = $current_user ? admin_user_display_name($current_user) : '';
$current_role = $current_user ? admin_user_role($current_user) : 'profesor';
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
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>Calendario Sala de Computación | CCG Admin</title>
    <meta name="theme-color" content="#2C4C74">
    <script>
        (function () {
            try {
                var storedTheme = localStorage.getItem('castel-theme');
                document.documentElement.setAttribute('data-theme', storedTheme || 'dark');
            } catch (error) {}
        })();
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
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Paleta extraída del escudo oficial (LogoCastelGandolfoSinFondo.png): azul #2C4C74, verde #4E8452 */
        :root {
            --forest: #4E8452;
            --forest-deep: #3a6b3e;
            --navy: #2C4C74;
            --navy-soft: #3a5f8c;
            --teal: #3d8f7a;
            --gold: #d6aa43;
            --paper: #f3f7f2;
            --paper-strong: rgba(255, 255, 255, 0.9);
            --line: rgba(44, 76, 116, 0.14);
            --ink: #17304b;
            --muted: rgba(23, 48, 75, 0.72);
            --danger: #c44f4f;
            --warning: #c38c22;
            --ok: #4E8452;
            --radius-xl: 28px;
            --radius-lg: 22px;
            --radius-md: 16px;
            --shadow-lg: 0 24px 48px rgba(44, 76, 116, 0.14);
            --shadow-md: 0 16px 28px rgba(44, 76, 116, 0.09);
            /* Vista mensual: más ancho para el grilla y el panel lateral */
            --site-width: min(1680px, calc(100vw - 40px));
        }

        :root[data-theme="dark"] {
            --paper: #081625;
            --paper-strong: rgba(12, 27, 46, 0.92);
            --ink: #ecf5ff;
            --muted: rgba(236, 245, 255, 0.74);
            --line: rgba(148, 196, 255, 0.14);
            --shadow-lg: 0 24px 54px rgba(2, 7, 18, 0.42);
            --shadow-md: 0 16px 34px rgba(2, 7, 18, 0.26);
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; overflow-x: hidden; }
        body {
            font-family: 'Outfit', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 12% 10%, rgba(123, 196, 255, 0.2), transparent 18%),
                radial-gradient(circle at 85% 8%, rgba(214, 170, 67, 0.14), transparent 18%),
                radial-gradient(circle at 78% 52%, rgba(28, 154, 138, 0.12), transparent 22%),
                linear-gradient(180deg, #e4ecf5 0%, #dfe8f0 38%, #e2ebe4 100%);
            min-height: 100vh;
        }
        :root[data-theme="dark"] body {
            background:
                radial-gradient(circle at 12% 10%, rgba(123, 196, 255, 0.12), transparent 18%),
                radial-gradient(circle at 85% 8%, rgba(214, 170, 67, 0.08), transparent 18%),
                radial-gradient(circle at 78% 52%, rgba(28, 154, 138, 0.1), transparent 22%),
                linear-gradient(180deg, #08111c 0%, #0b1a28 42%, #0c1824 100%);
        }

        .container {
            width: min(var(--site-width), calc(100% - 32px));
            margin: 0 auto;
        }

        .site-header {
            position: sticky;
            top: 0;
            z-index: 50;
            padding: 14px 0;
        }

        .site-header__bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 18px;
            min-height: 84px;
            border-radius: 999px;
            background: linear-gradient(135deg, rgba(255,255,255,0.88), rgba(235,244,238,0.7));
            border: 1px solid rgba(255,255,255,0.8);
            box-shadow: var(--shadow-md);
            backdrop-filter: blur(18px);
        }
        :root[data-theme="dark"] .site-header__bar {
            background: linear-gradient(135deg, rgba(11, 29, 50, 0.92), rgba(15, 49, 69, 0.78));
            border-color: rgba(148,196,255,0.12);
        }

        .site-logo {
            display: inline-flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
            text-decoration: none;
            color: var(--ink);
        }
        .site-logo img {
            height: 58px;
            width: auto;
            display: block;
        }
        .site-logo__meta { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .site-logo__eyebrow {
            font-size: 0.76rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .site-logo__name {
            font-size: 1.06rem;
            font-weight: 800;
            line-height: 1.1;
        }

        .site-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .theme-toggle,
        .nav-link {
            border: 0;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 16px;
            border-radius: 999px;
            font: inherit;
            font-weight: 700;
            color: var(--ink);
            background: rgba(44, 76, 116, 0.07);
            transition: transform 0.2s ease, background 0.2s ease;
        }
        .theme-toggle:hover,
        .nav-link:hover { transform: translateY(-1px); background: rgba(27, 130, 82, 0.12); }
        .nav-link--primary { background: linear-gradient(135deg, var(--forest), var(--teal)); color: #fff; }
        .theme-fab {
            position: fixed;
            right: 18px;
            bottom: 18px;
            z-index: 80;
            border: 0;
            border-radius: 999px;
            padding: 13px 18px;
            font: inherit;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, rgba(44, 76, 116, 0.95), rgba(78, 132, 82, 0.9));
            box-shadow: var(--shadow-md);
            cursor: pointer;
        }

        main { padding: 18px 0 64px; }

        .page-hero {
            position: relative;
            overflow: hidden;
            padding: clamp(34px, 5vw, 56px) clamp(24px, 4vw, 50px);
            border-radius: var(--radius-xl);
            color: #fff;
            background:
                linear-gradient(135deg, rgba(44, 76, 116, 0.92), rgba(44, 76, 116, 0.65)),
                url('/wp-content/uploads/2024/10/Colegio-logo-1.jpg') center 28%/cover no-repeat;
            box-shadow: var(--shadow-lg);
        }
        .page-hero__kicker {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            font-size: 0.76rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            font-weight: 700;
            color: rgba(255,255,255,0.72);
        }
        .page-hero__kicker::before {
            content: "";
            width: 40px;
            height: 1px;
            background: rgba(214, 170, 67, 0.92);
        }
        .page-hero h1 {
            margin: 0;
            max-width: 12ch;
            font-size: clamp(2rem, 4vw, 3.4rem);
            line-height: 1.02;
            letter-spacing: -0.05em;
        }
        .page-hero p {
            max-width: 72ch;
            margin: 14px 0 0;
            font-size: 1rem;
            color: rgba(255,255,255,0.86);
        }

        .surface {
            margin-top: 16px;
            padding: clamp(18px, 3vw, 30px);
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.94), rgba(228, 236, 246, 0.9));
            border: 1px solid rgba(44, 76, 116, 0.14);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            backdrop-filter: blur(18px);
        }
        :root[data-theme="dark"] .surface {
            background: linear-gradient(180deg, rgba(14, 32, 52, 0.94), rgba(8, 20, 36, 0.92));
            border-color: var(--line);
        }

        .calendar-intro h2,
        .calendar-panel h3,
        .calendar-section__header h3 {
            margin: 10px 0 6px;
            font-size: clamp(1.2rem, 2vw, 1.5rem);
            line-height: 1.14;
            letter-spacing: -0.03em;
        }
        .kicker {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 0.78rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--muted);
        }
        .kicker::after {
            content: "";
            width: 34px;
            height: 1px;
            background: linear-gradient(90deg, rgba(214,170,67,0.85), rgba(15,38,79,0.18));
        }

        .calendar-shell { display: grid; gap: 22px; }
        .calendar-toolbar {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 18px;
        }
        .calendar-panel {
            padding: 22px;
            border-radius: var(--radius-lg);
            background: rgba(255,255,255,0.74);
            border: 1px solid var(--line);
            box-shadow: 0 14px 24px rgba(15,38,79,0.05);
        }
        :root[data-theme="dark"] .calendar-panel { background: rgba(10, 25, 41, 0.74); }
        .calendar-field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .calendar-field {
            display: grid;
            gap: 8px;
        }
        .calendar-field label {
            font-size: 0.87rem;
            font-weight: 700;
            color: var(--muted);
        }
        .calendar-select,
        .calendar-input,
        .calendar-textarea {
            width: 100%;
            min-width: 0;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid rgba(15,38,79,0.14);
            background: rgba(255,255,255,0.84);
            color: var(--ink);
            font: inherit;
        }
        :root[data-theme="dark"] .calendar-select,
        :root[data-theme="dark"] .calendar-input,
        :root[data-theme="dark"] .calendar-textarea {
            background: rgba(255,255,255,0.05);
            border-color: rgba(148,196,255,0.18);
            color: var(--ink);
        }
        .calendar-textarea { min-height: 94px; resize: vertical; }

        .chip-group { display: flex; flex-wrap: wrap; gap: 8px; }
        .chip {
            border: 1px solid var(--line);
            background: rgba(15,38,79,0.04);
            color: var(--ink);
            border-radius: 999px;
            padding: 10px 14px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .chip.is-active {
            background: linear-gradient(135deg, rgba(27,130,82,0.14), rgba(28,154,138,0.12));
            border-color: rgba(27,130,82,0.3);
            color: var(--forest-deep);
        }
        :root[data-theme="dark"] .chip.is-active { color: #dffceb; }

        .action-row { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
        .button {
            border: 0;
            border-radius: 999px;
            padding: 12px 18px;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
            color: var(--navy);
            background: rgba(15,38,79,0.08);
        }
        .button--primary { color: #fff; background: linear-gradient(135deg, var(--forest), var(--teal)); }
        .button--ghost { background: rgba(255,255,255,0.56); border: 1px solid var(--line); }
        .button--danger { color: #fff; background: linear-gradient(135deg, #aa4343, #cf6262); }
        .button:disabled { opacity: 0.55; cursor: not-allowed; }

        .calendar-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }
        .stat {
            padding: 18px;
            border-radius: var(--radius-lg);
            background: rgba(255,255,255,0.7);
            border: 1px solid var(--line);
        }
        :root[data-theme="dark"] .stat { background: rgba(255,255,255,0.04); }
        .stat__label { display: block; font-size: 0.78rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.08em; }
        .stat__value { display: block; font-size: 1.8rem; font-weight: 800; margin-top: 6px; }
        .stat__hint { display: block; margin-top: 6px; color: var(--muted); font-size: 0.9rem; }

        .calendar-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(290px, 0.95fr);
            gap: 18px;
        }
        .calendar-section,
        .calendar-aside { display: grid; gap: 18px; align-content: start; }
        .calendar-section__header { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; }

        .legend { display: flex; flex-wrap: wrap; gap: 10px; color: var(--muted); font-size: 0.84rem; }
        .legend span { display: inline-flex; align-items: center; gap: 6px; }
        .legend i { width: 10px; height: 10px; border-radius: 999px; display: inline-block; }
        .legend .free { background: rgba(15,38,79,0.15); }
        .legend .reserved { background: linear-gradient(135deg, rgba(27,130,82,0.86), rgba(28,154,138,0.86)); }
        .legend .service { background: linear-gradient(135deg, rgba(195,140,34,0.9), rgba(214,170,67,0.88)); }
        .legend .blocked { background: linear-gradient(135deg, rgba(170,67,67,0.92), rgba(204,96,96,0.82)); }
        .legend .holiday { background: linear-gradient(135deg, rgba(15,38,79,0.62), rgba(214,170,67,0.86)); }

        .week-list { display: grid; gap: 14px; }
        .week {
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            background: rgba(255,255,255,0.64);
            overflow: hidden;
        }
        :root[data-theme="dark"] .week { background: rgba(255,255,255,0.03); }
        .week summary {
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 18px 20px;
            cursor: pointer;
        }
        .week summary::-webkit-details-marker { display: none; }
        .week__title strong { display: block; font-size: 1rem; }
        .week__title span { display: block; margin-top: 3px; color: var(--muted); font-size: 0.92rem; }
        .week__badges { display: flex; flex-wrap: wrap; gap: 8px; }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 0.8rem;
            font-weight: 700;
            background: rgba(15,38,79,0.08);
        }
        .week__body {
            display: grid;
            gap: 12px;
            padding: 0 18px 18px;
        }
        .day-card {
            border-radius: 18px;
            border: 1px solid var(--line);
            padding: 16px;
            background: rgba(255,255,255,0.72);
        }
        :root[data-theme="dark"] .day-card { background: rgba(255,255,255,0.04); }
        .day-card.is-holiday { background: linear-gradient(135deg, rgba(15,38,79,0.82), rgba(29,59,112,0.78)); color: #fff; }
        .day-card__meta {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }
        .day-card__weekday { display: block; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); font-weight: 700; }
        .day-card.is-holiday .day-card__weekday,
        .day-card.is-holiday .day-card__date { color: rgba(255,255,255,0.88); }
        .day-card__date { display: block; margin-top: 4px; font-size: 1rem; font-weight: 800; }
        .day-card__owner { display: block; margin-top: 6px; color: var(--muted); font-size: 0.88rem; }
        .day-card__status { white-space: nowrap; }
        .day-card__grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .note {
            margin-top: 10px;
            padding: 12px 14px;
            border-radius: 14px;
            background: rgba(15,38,79,0.05);
            color: var(--muted);
        }
        .warning-note {
            margin-top: 10px;
            padding: 12px 14px;
            border-radius: 14px;
            background: rgba(195,140,34,0.12);
            color: #7a5712;
            font-weight: 600;
        }
        .inline-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }

        .list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 10px;
        }
        .list li {
            padding: 14px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.58);
        }
        :root[data-theme="dark"] .list li { background: rgba(255,255,255,0.04); }
        .list strong { display: block; }
        .list span { display: block; margin-top: 4px; color: var(--muted); }
        .request-card {
            padding: 16px;
            border-radius: 18px;
            border: 1px solid rgba(27,130,82,0.22);
            background: linear-gradient(135deg, rgba(27,130,82,0.07), rgba(28,154,138,0.05));
        }
        .request-card__title { font-weight: 800; }
        .request-card__meta { margin-top: 4px; color: var(--muted); font-size: 0.9rem; }
        .request-card__body { margin-top: 10px; display: grid; gap: 6px; font-size: 0.95rem; }

        .status-message {
            padding: 14px 16px;
            border-radius: 16px;
            font-weight: 700;
            margin-bottom: 14px;
            display: none;
        }
        .status-message.is-visible { display: block; }
        .status-message.is-ok { background: rgba(15,123,88,0.12); color: var(--ok); }
        .status-message.is-error { background: rgba(196,79,79,0.12); color: var(--danger); }
        .empty {
            padding: 16px;
            border-radius: 16px;
            border: 1px dashed var(--line);
            color: var(--muted);
        }
        .loading {
            padding: 22px;
            color: var(--muted);
        }

        .calendar-surface-single {
            background: linear-gradient(180deg, rgba(195, 210, 228, 0.72), rgba(168, 188, 212, 0.58));
            border: 1px solid rgba(44, 76, 116, 0.16);
        }
        :root[data-theme="dark"] .calendar-surface-single {
            background: linear-gradient(180deg, rgba(8, 20, 38, 0.96), rgba(4, 12, 26, 0.94));
            border-color: rgba(123, 196, 255, 0.14);
        }
        .calendar-page-lead {
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--line);
        }
        .calendar-page-lead__title {
            margin: 6px 0 8px;
            font-size: clamp(1.25rem, 2.2vw, 1.55rem);
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

        .site-footer { padding-bottom: 34px; }
        .site-footer__panel {
            padding: 24px;
            border-radius: var(--radius-xl);
            background: linear-gradient(135deg, rgba(44, 76, 116, 0.96), rgba(34, 58, 88, 0.94));
            color: rgba(255,255,255,0.84);
        }
        .site-footer__grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }
        .site-footer h3 { margin: 0 0 10px; color: #fff; }
        .site-footer a { color: rgba(255,255,255,0.84); text-decoration: none; display: block; margin-top: 6px; }
        .site-footer__bottom {
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid rgba(255,255,255,0.12);
            display: flex;
            justify-content: space-between;
            gap: 10px;
            color: rgba(255,255,255,0.6);
            font-size: 0.9rem;
        }

        @media (max-width: 1080px) {
            .calendar-toolbar,
            .calendar-layout,
            .site-footer__grid,
            .calendar-summary { grid-template-columns: 1fr; }
        }

        @media (max-width: 820px) {
            .site-header__bar { border-radius: 28px; align-items: flex-start; }
            .site-actions { width: 100%; justify-content: flex-start; }
            .calendar-field-grid,
            .day-card__grid { grid-template-columns: 1fr; }
            .theme-toggle { display: none; }
        }

        @media (max-width: 640px) {
            .container { width: min(var(--site-width), calc(100% - 18px)); }
            .site-header { padding: 10px 0; }
            .site-header__bar {
                min-height: auto;
                padding: 12px 14px;
                border-radius: 24px;
                flex-direction: column;
                align-items: stretch;
            }
            .site-logo img { height: 48px; }
            .site-actions { gap: 8px; }
            .nav-link,
            .button { width: 100%; justify-content: center; }
            .page-hero { padding: 28px 18px; border-radius: 24px; }
            .surface { padding: 18px 14px; border-radius: 24px; }
            .week summary,
            .week__body { padding-left: 14px; padding-right: 14px; }
            .day-card__meta { flex-direction: column; }
            .inline-actions { flex-direction: column; }
            .theme-fab { bottom: 14px; right: 14px; }
            .site-footer__bottom { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="site-shell">
        <header class="site-header">
            <div class="container">
                <div class="site-header__bar">
                    <a class="site-logo" href="/admin/editor.php">
                        <img src="/app/assets/LogoCastelGandolfoSinFondo.png" alt="Colegio Castelgandolfo">
                        <span class="site-logo__meta">
                            <span class="site-logo__eyebrow">CCG Admin</span>
                            <span class="site-logo__name">Calendario Sala de Computación</span>
                        </span>
                    </a>

                    <div class="site-actions">
                        <button type="button" class="theme-toggle" data-theme-toggle>Oscuro</button>
                        <a class="nav-link" href="/admin/editor.php">Panel</a>
                        <a class="nav-link nav-link--primary" href="/admin/calendar.php">Calendario</a>
                        <a class="nav-link" href="/admin/correo-avisos.php">Correo / avisos</a>
                        <a class="nav-link" href="/app/" target="_blank" rel="noopener">Sitio público</a>
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
                            <a href="/admin/editor.php">Panel principal</a>
                            <a href="/admin/correo-avisos.php">Correo / avisos</a>
                            <a href="/admin/index.php?logout=1">Cerrar sesión</a>
                            <a href="/app/" target="_blank" rel="noopener">Sitio público</a>
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
    <script src="/admin/calendar_month_app.js"></script>
</body>
</html>
