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

// Mantenimiento de herramientas
$maintenance_tools_enabled = function_exists('admin_maintenance_tools_enabled') && admin_maintenance_tools_enabled();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Ecosistema Digital Docente | Colegio Castelgandolfo</title>
    <meta name="theme-color" content="#0f264f">
    <link rel="icon" href="/admin/calendar-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/castel-app-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --forest: #1b8252;
            --forest-deep: #11583b;
            --navy: #0f264f;
            --navy-soft: #1d3b70;
            --teal: #1c9a8a;
            --sky: #7bc4ff;
            --gold: #d6aa43;
            --gold-light: #fef08a;
            --white: #ffffff;
            --ink: #18304b;
            --line: rgba(15, 38, 79, 0.12);
            --shadow-lg: 0 24px 60px rgba(8, 18, 28, 0.35);
            --shadow-card: 0 16px 36px rgba(15, 38, 79, 0.18);
            --radius-xl: 3px;
            --radius-lg: 2px;
            --radius-md: 2px;
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }

        body {
            font-family: 'Outfit', sans-serif;
            color: var(--ink);
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            background:
                radial-gradient(circle at 10% 15%, rgba(123, 196, 255, 0.22), transparent 28%),
                radial-gradient(circle at 85% 85%, rgba(214, 170, 67, 0.2), transparent 30%),
                linear-gradient(135deg, rgba(15, 38, 79, 0.88) 0%, rgba(29, 59, 112, 0.74) 50%, rgba(15, 38, 79, 0.92) 100%),
                url('/wp-content/uploads/2024/10/Colegio-logo-1.jpg') center center / cover no-repeat fixed;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            display: none !important;
            content: none !important;
        }

        .hub-container {
            width: min(1140px, calc(100% - 32px));
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        /* Barra de navegación superior */
        .hub-header {
            padding: 16px 0;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .hub-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            background: rgba(15, 38, 79, 0.92);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: var(--radius-lg);
            padding: 12px 20px;
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            color: #ffffff;
        }

        .hub-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #ffffff;
        }

        .hub-logo img {
            height: 44px;
            width: auto;
            background: #ffffff;
            padding: 3px 6px;
            border-radius: 2px;
        }

        .hub-logo__text {
            display: flex;
            flex-direction: column;
        }

        .hub-logo__title {
            font-size: 1.05rem;
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -0.01em;
        }

        .hub-logo__subtitle {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            color: var(--sky);
            font-weight: 700;
        }

        .hub-user-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.88rem;
        }

        .user-tag {
            background: rgba(255, 255, 255, 0.12);
            padding: 6px 14px;
            border-radius: 2px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .role-badge {
            background: var(--gold);
            color: #18304b;
            font-size: 0.72rem;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 2px;
            text-transform: uppercase;
        }

        .hub-btn-logout {
            background: rgba(217, 106, 106, 0.22);
            border: 1px solid rgba(217, 106, 106, 0.45);
            color: #ffc9c9;
            text-decoration: none;
            padding: 7px 14px;
            border-radius: 2px;
            font-weight: 700;
            font-size: 0.84rem;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .hub-btn-logout:hover {
            background: #d96a6a;
            color: #ffffff;
        }

        /* Hero del Hub */
        .hub-hero {
            padding: 24px 0 16px;
            text-align: center;
            color: #ffffff;
        }

        .hub-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 5px 14px;
            background: rgba(214, 170, 67, 0.22);
            border: 1px solid rgba(214, 170, 67, 0.4);
            border-radius: 2px;
            color: var(--gold-light);
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            margin-bottom: 12px;
        }

        .hub-hero h1 {
            font-size: clamp(1.8rem, 4vw, 2.7rem);
            font-weight: 800;
            letter-spacing: -0.03em;
            margin: 0 0 10px;
            line-height: 1.1;
            text-shadow: 0 4px 18px rgba(0, 0, 0, 0.4);
        }

        .hub-hero p {
            max-width: 66ch;
            margin: 0 auto;
            font-size: 1.05rem;
            color: rgba(255, 255, 255, 0.88);
            line-height: 1.55;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        /* Grid de Plataformas */
        .systems-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 24px;
            margin: 28px 0 36px;
        }

        .system-card {
            background: rgba(255, 255, 255, 0.96);
            border-radius: var(--radius-xl);
            border: 1px solid rgba(255, 255, 255, 0.7);
            padding: 28px 24px;
            box-shadow: var(--shadow-card);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            transition: transform 0.25s cubic-bezier(0.2, 0, 0, 1), box-shadow 0.25s ease;
        }

        .system-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 24px 50px rgba(15, 38, 79, 0.25);
        }

        .system-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
        }

        .system-card--castelboard::before { background: linear-gradient(90deg, #1E3A5F, #3b82f6); }
        .system-card--edudocente::before { background: linear-gradient(90deg, #065f46, #10b981); }
        .system-card--calendar::before { background: linear-gradient(90deg, #b4851f, #d6aa43); }
        .system-card--biblioteca::before { background: linear-gradient(90deg, #143D2B, #1B8252); }
        .system-card--admin::before { background: linear-gradient(90deg, #4338ca, #6366f1); }
        .badge--biblioteca { background: #ECFDF5; color: #143D2B; border: 1px solid #A7F3D0; }

        .system-card__header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .system-icon-badge {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.76rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            padding: 4px 8px;
            background: rgba(15, 38, 79, 0.08);
            border: 1px solid rgba(15, 38, 79, 0.2);
            color: var(--navy);
            border-radius: 2px;
        }

        .system-badge {
            font-size: 0.74rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 4px 10px;
            border-radius: 2px;
        }

        .badge--castelboard { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .badge--edudocente { background: #f0fdf4; color: #065f46; border: 1px solid #bbf7d0; }
        .badge--calendar { background: #fefce8; color: #854d0e; border: 1px solid #fef08a; }
        .badge--admin { background: #eef2ff; color: #3730a3; border: 1px solid #c7d2fe; }

        .system-card h3 {
            margin: 0 0 6px;
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--navy);
            letter-spacing: -0.02em;
        }

        .system-card__kicker {
            font-size: 0.84rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--navy-soft);
            margin-bottom: 12px;
            display: block;
        }

        .system-card p {
            margin: 0 0 16px;
            font-size: 0.94rem;
            line-height: 1.58;
            color: #334155;
        }

        .system-card__features {
            list-style: none;
            margin: 0 0 20px;
            padding: 0;
            font-size: 0.88rem;
            line-height: 1.5;
            color: #475569;
        }

        .system-card__features li {
            position: relative;
            padding-left: 22px;
            margin-bottom: 7px;
        }

        .system-card__features li::before {
            content: "";
            position: absolute;
            left: 2px;
            top: 7px;
            width: 6px;
            height: 6px;
            background: var(--forest);
            border-radius: 1px;
        }

        .network-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.70rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 3px 8px;
            border-radius: 2px;
            background: rgba(15, 38, 79, 0.06);
            color: #1e3a52;
            border: 1px solid rgba(15, 38, 79, 0.15);
            margin-top: 6px;
            margin-bottom: 12px;
        }

        .system-card__actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: auto;
            border-top: 1px solid var(--line);
            padding-top: 18px;
        }

        .btn-launch {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 20px;
            border-radius: 2px;
            font-weight: 800;
            font-size: 0.95rem;
            text-decoration: none;
            color: #ffffff;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
            min-height: 48px;
            touch-action: manipulation;
        }

        .btn-launch:hover {
            transform: translateY(-2px);
            filter: brightness(1.08);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
        }

        .btn-launch--castelboard { background: #1E3A5F; }
        .btn-launch--edudocente { background: #065f46; }
        .btn-launch--calendar { background: #d6aa43; color: #18304b; }
        .btn-launch--admin { background: #4338ca; }

        .btn-sublink {
            text-align: center;
            font-size: 0.84rem;
            color: #475569;
            text-decoration: none;
            padding: 8px;
            border-radius: 2px;
            transition: background 0.15s, color 0.15s;
            display: block;
        }

        .btn-sublink:hover {
            color: var(--navy);
            background: rgba(15, 38, 79, 0.05);
            text-decoration: none;
        }

        /* Banner de Estudiantes Separados */
        .students-notice {
            background: rgba(15, 38, 79, 0.92);
            border: 1px solid rgba(123, 196, 255, 0.35);
            border-radius: 2px;
            padding: 20px 24px;
            color: #ffffff;
            margin-bottom: 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            box-shadow: var(--shadow-lg);
            backdrop-filter: blur(12px);
        }

        .students-notice__text h4 {
            margin: 0 0 6px;
            font-size: 1.1rem;
            color: var(--gold-light);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .students-notice__text p {
            margin: 0;
            font-size: 0.92rem;
            color: rgba(255, 255, 255, 0.88);
            line-height: 1.5;
        }

        .hub-footer {
            margin-top: auto;
            padding: 24px 0 32px;
            text-align: center;
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
            border-top: 1px solid rgba(255, 255, 255, 0.15);
        }

        .hub-footer a {
            color: var(--sky);
            text-decoration: none;
        }

        .hub-footer a:hover {
            text-decoration: underline;
        }

        /* ── Optimización Móvil Completa ── */
        @media (max-width: 768px) {
            .hub-container {
                width: calc(100% - 20px);
            }
            .hub-header {
                padding: 10px 0;
            }
            .hub-nav {
                flex-direction: column;
                align-items: stretch;
                padding: 10px 14px;
                gap: 10px;
            }
            .hub-logo img {
                height: 38px;
            }
            .hub-logo__title {
                font-size: 0.95rem;
            }
            .hub-logo__subtitle {
                font-size: 0.65rem;
            }
            .hub-user-meta {
                flex-wrap: wrap;
                width: 100%;
                gap: 8px;
            }
            .user-tag {
                width: 100%;
                justify-content: space-between;
                font-size: 0.80rem;
                padding: 6px 12px;
            }
            .user-tag > span:first-child {
                max-width: 200px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .hub-user-meta > a {
                flex: 1;
                text-align: center;
                min-height: 40px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
            .hub-hero {
                padding: 16px 0 8px;
            }
            .hub-hero h1 {
                font-size: 1.55rem;
            }
            .hub-hero p {
                font-size: 0.90rem;
            }
            .systems-grid {
                grid-template-columns: 1fr;
                gap: 16px;
                margin: 18px 0 24px;
            }
            .system-card {
                padding: 20px 16px;
                border-radius: var(--radius-lg);
            }
            .system-card h3 {
                font-size: 1.25rem;
            }
            .btn-launch {
                min-height: 48px;
                width: 100%;
            }
            .students-notice {
                flex-direction: column;
                align-items: stretch;
                padding: 18px 16px;
                gap: 14px;
            }
            .students-notice a {
                width: 100%;
                text-align: center;
                justify-content: center;
                min-height: 44px;
            }
        }
    </style>
</head>
<body>
    <header class="hub-header">
        <div class="hub-container">
            <nav class="hub-nav">
                <a href="/admin/hub.php" class="hub-logo">
                    <img src="/assets/LogoCastelGandolfoSinFondo.png" alt="Colegio Castelgandolfo">
                    <div class="hub-logo__text">
                        <span class="hub-logo__title">Colegio Castelgandolfo</span>
                        <span class="hub-logo__subtitle">Ecosistema Digital Docente</span>
                    </div>
                </a>

                <div class="hub-user-meta">
                    <div class="user-tag">
                        <span><?= htmlspecialchars($current_name ?: $current_email) ?></span>
                        <span class="role-badge"><?= htmlspecialchars(ucfirst($current_role)) ?></span>
                    </div>
                    <a href="/" target="_blank" rel="noopener" style="color:rgba(255,255,255,0.85);text-decoration:none;font-size:0.82rem;padding:6px 10px;background:rgba(255,255,255,0.08);border-radius:2px;">Sitio público ↗</a>
                    <a href="/admin/index.php?logout=1" class="hub-btn-logout">Cerrar sesión</a>
                </div>
            </nav>
        </div>
    </header>

    <main>
        <div class="hub-container">
            <section class="hub-hero">
                <span class="hub-kicker">Suite Digital Docente · Star Server</span>
                <h1>Ecosistema de Aplicaciones Escolares</h1>
                <p>Bienvenido al centro unificado de herramientas de aula y gestión pedagógica. Selecciona la plataforma a la que deseas acceder con tu sesión activa.</p>
            </section>

            <section class="systems-grid">
                <!-- 1. CastelBoard -->
                <article class="system-card system-card--castelboard">
                    <div>
                        <div class="system-card__header">
                            <span class="system-icon-badge">[PORTAFOLIO]</span>
                            <span class="system-badge badge--castelboard">Portafolio & Aula</span>
                        </div>
                        <h3>CastelBoard</h3>
                        <span class="system-card__kicker">Recepción de Entregas y Casilleros</span>
                        <span class="network-tag">Acceso Remoto Seguro & Red Escolar</span>
                        <p>Plataforma para recibir proyectos de alumnos por asignatura sin pendrives ni correos saturados.</p>
                        <ul class="system-card__features">
                            <li>Recepción multiformato hasta 100 MB (.sb3 Scratch, .blend Blender, ZIP, Office).</li>
                            <li><strong>Timbre Escolar Automático:</strong> Cierre de entregas programado (8:00 a 16:30 hrs).</li>
                            <li><strong>Escala Chilena al 60%:</strong> Nota 4.0 automática según Decreto 67.</li>
                            <li>Botón <em>"Copiar Notas"</em> en un clic para transferir a Sofia School.</li>
                        </ul>
                    </div>
                    <div class="system-card__actions">
                        <a href="/admin/sso_jump.php?target=castelboard" target="_blank" rel="noopener" class="btn-launch btn-launch--castelboard">
                            Abrir CastelBoard ↗
                        </a>
                        <a href="/admin/sso_jump.php?target=castelboard&lan=1" target="_blank" rel="noopener" class="btn-sublink">Acceso local LAN (castelboard.castelgandolfo)</a>
                    </div>
                </article>

                <!-- 2. EduDocente Studio -->
                <article class="system-card system-card--edudocente">
                    <div>
                        <div class="system-card__header">
                            <span class="system-icon-badge">[CURRICULAR]</span>
                            <span class="system-badge badge--edudocente">Diseño Curricular IA</span>
                        </div>
                        <h3>EduDocente Studio</h3>
                        <span class="system-card__kicker">Evaluaciones Word (.docx) con IA</span>
                        <span class="network-tag">Acceso Remoto Seguro & Red Escolar</span>
                        <p>Generador pedagógico de pruebas oficiales, temarios y pautas explicadas paso a paso con membrete del colegio.</p>
                        <ul class="system-card__features">
                            <li>Descarga directa en <strong>Word (.docx) editable</strong> listo para fotocopiar.</li>
                            <li>Preguntas de alternativa, desarrollo reflexivo, análisis y rúbricas.</li>
                            <li>Pauta de corrección anexa con solucionario detallado para el docente.</li>
                            <li><strong>Cuotas de IA Gratuitas ($0):</strong> Conexión con tu cuenta Google personal (Antigravity semanal, Codex gratis y Claude) con Smart Buffer offline.</li>
                        </ul>
                    </div>
                    <div class="system-card__actions">
                        <a href="https://ccg-fisico.tail0e08b5.ts.net:8443/" target="_blank" rel="noopener" class="btn-launch btn-launch--edudocente">
                            Abrir EduDocente Studio ↗
                        </a>
                        <a href="http://estudio.castelgandolfo" target="_blank" rel="noopener" class="btn-sublink">Acceso local LAN (estudio.castelgandolfo)</a>
                    </div>
                </article>

                <!-- 3. Calendario Sala de Computación -->
                <article class="system-card system-card--calendar">
                    <div>
                        <div class="system-card__header">
                            <span class="system-icon-badge">[LABORATORIOS]</span>
                            <span class="system-badge badge--calendar">Laboratorios</span>
                        </div>
                        <h3>Toma de Salas</h3>
                        <span class="system-card__kicker">Calendario Salas de Computación</span>
                        <span class="network-tag">Disponible en Red & Móvil</span>
                        <p>Agenda horaria de los laboratorios de informática para asegurar disponibilidad y coordinación entre asignaturas.</p>
                        <ul class="system-card__features">
                            <li>Reserva por bloques para <strong>Sala Básica</strong> y <strong>Sala Media</strong>.</li>
                            <li>Registro con curso, asignatura y objetivo pedagógico.</li>
                            <li>Sistema de <em>"Solicitud de Aprobación"</em> para intercambio entre profesores.</li>
                            <li>Supervisión de directivos y mantención de equipamiento.</li>
                        </ul>
                    </div>
                    <div class="system-card__actions">
                        <a href="/admin/calendar.php" class="btn-launch btn-launch--calendar">
                            Entrar a Computación ↗
                        </a>
                        <span class="btn-sublink">Acceso a Sala Básica y Sala Media</span>
                    </div>
                </article>

                <!-- 4. Calendario de Biblioteca -->
                <article class="system-card system-card--biblioteca">
                    <div>
                        <div class="system-card__header">
                            <span class="system-icon-badge">[BIBLIOTECA]</span>
                            <span class="system-badge badge--biblioteca">Lectura & Estudio</span>
                        </div>
                        <h3>Biblioteca</h3>
                        <span class="system-card__kicker">Espacio Único Institucional</span>
                        <span class="network-tag" style="background: rgba(20, 61, 43, 0.08); color: #143D2B; border-color: rgba(20, 61, 43, 0.2);">Plan Lector & Proyecciones</span>
                        <p>Agenda horaria exclusiva para la Biblioteca del colegio. Coordinación de lecturas guiadas, investigaciones con libros, cine debate y talleres pedagógicos.</p>
                        <ul class="system-card__features">
                            <li>Reserva por bloques para el <strong>Espacio Único de Biblioteca</strong>.</li>
                            <li>Registro con plan lector, investigación o actividad pedagógica.</li>
                            <li>Trazabilidad, autoría docente y solicitudes de intercambio.</li>
                            <li>Aislamiento visual y de agenda respecto a computación.</li>
                        </ul>
                    </div>
                    <div class="system-card__actions">
                        <a href="/admin/biblioteca.php" class="btn-launch" style="background: #143D2B; color: #fff;">
                            Entrar a Biblioteca ↗
                        </a>
                        <span class="btn-sublink">Acceso directo a la agenda mensual de biblioteca</span>
                    </div>
                </article>

                <?php if ($can_manage_users || $can_manage_site): ?>
                <!-- 5. Gestión Directiva y UTP -->
                <article class="system-card system-card--admin">
                    <div>
                        <div class="system-card__header">
                            <span class="system-icon-badge">[ADMINISTRACION]</span>
                            <span class="system-badge badge--admin">Directivo & UTP</span>
                        </div>
                        <h3>Gestión y Administración</h3>
                        <span class="system-card__kicker">Panel Administrativo y Soporte</span>
                        <span class="network-tag">Acceso Privado Directivo</span>
                        <p>Herramientas reservadas para equipo directivo, jefatura de UTP y administración de sistemas.</p>
                        <ul class="system-card__features">
                            <li><strong>Bitácora & Logs del Sistema:</strong> Auditoría completa en tiempo real de operaciones, calendario, portafolio y accesos.</li>
                            <?php if ($can_manage_site): ?>
                            <li><strong>Documentos Oficiales:</strong> Visibilidad y gestión directa del PEI, reglamentos, planes lectores y listas de útiles.</li>
                            <?php endif; ?>
                            <?php if ($can_manage_users): ?>
                            <li><strong>Gestión de Usuarios:</strong> Habilitación de cuentas docentes y control de claves.</li>
                            <?php endif; ?>
                            <?php if ($can_manage_site): ?>
                            <li><strong>Avisos y Comunicados:</strong> Envío de notificaciones por correo institucional.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="system-card__actions">
                        <a href="/admin/logs.php" class="btn-launch btn-launch--admin">
                            Ver Bitácora & Logs ↗
                        </a>
                        <a href="https://ccg-fisico.tail0e08b5.ts.net:10000/" target="_blank" rel="noopener" class="btn-sublink" style="color: #065f46; font-weight: 700; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 2px; padding: 9px 12px;">CCG Core Admin (Panel Servidor Físico) ↗</a>
                        <?php if ($can_manage_site): ?>
                        <a href="/admin/documentos.php" class="btn-sublink" style="color: #4338ca; font-weight: 700; background: #eef2ff; border-radius: 2px; padding: 9px 12px;">Documentos Oficiales Web ↗</a>
                        <?php endif; ?>
                        <?php if ($can_manage_users): ?>
                        <a href="/admin/usuarios.php" class="btn-sublink">Administrar Usuarios del Sistema</a>
                        <?php endif; ?>
                        <?php if ($can_manage_site): ?>
                        <a href="/admin/correo-avisos.php" class="btn-sublink">Despacho de Avisos por Correo</a>
                        <a href="/admin/editor.php" class="btn-sublink">Configuración del Sitio</a>
                        <?php endif; ?>
                        <?php if ($maintenance_tools_enabled): ?>
                        <a href="/admin/sql.php" class="btn-sublink">Herramientas SQL / Mantención</a>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endif; ?>
            </section>

            <!-- Sección de Separación Estricta: Estudiantes -->
            <section class="students-notice">
                <div class="students-notice__text">
                    <h4>Acceso de Estudiantes (Aislado e Independiente)</h4>
                    <p>Los estudiantes <strong>no</strong> ingresan por esta zona administrativa. Cuentan con su propio casillero digital simplificado donde acceden únicamente con su <strong>RUT y PIN de 4 dígitos</strong> desde la red del colegio, garantizando privacidad, rapidez y seguridad total en el aula.</p>
                </div>
                <div>
                    <a href="https://ccg-fisico.tail0e08b5.ts.net/" target="_blank" rel="noopener" style="display:inline-flex;padding:12px 18px;background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);color:#fff;border-radius:2px;text-decoration:none;font-weight:700;font-size:0.85rem;white-space:nowrap;min-height:44px;align-items:center;">
                        Ver Portal Alumnos ↗
                    </a>
                </div>
            </section>
        </div>
    </main>

    <footer class="hub-footer">
        <div class="hub-container">
            <p>&copy; <?= date('Y') ?> Colegio Castelgandolfo · Suite Digital Escolar Star Server. Todos los derechos reservados.</p>
            <p>Soporte Técnico Informática: <a href="mailto:pavendano@colegiocastelgandolfo.cl">pavendano@colegiocastelgandolfo.cl</a></p>
        </div>
    </footer>
</body>
</html>
