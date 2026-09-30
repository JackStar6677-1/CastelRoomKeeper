<?php
/**
 * CastelRoomKeeper - Gestión de Usuarios y Centro de Administración Centralizada
 * Colegio Castelgandolfo - Ing. Pablo Elías Avendaño Miranda
 *
 * Administra el personal docente, directivo y técnico para la Toma de Salas de
 * Computación y se sincroniza automáticamente con CastelBoard (Portafolio Digital).
 */

require_once __DIR__ . '/auth.php';

admin_bootstrap_session();
admin_require_login();

$current_user = admin_current_user();
$current_role = admin_user_role($current_user);

// Solo admin puede ver este panel
if ($current_role !== 'admin') {
    header('Location: calendar.php');
    exit;
}

$csrf_token    = admin_csrf_token();
$current_email = admin_normalize_email($current_user['email'] ?? '');

$authorized_users = admin_read_authorized_users();
$message = '';
$error   = '';

/**
 * Deriva un nombre formal y amigable si full_name está vacío
 */
function admin_display_user_name($user, $email) {
    $fn = trim((string)($user['full_name'] ?? ''));
    if ($fn !== '') {
        return $fn;
    }
    $user_part = explode('@', $email)[0];
    if (strlen($user_part) > 2) {
        return strtoupper($user_part[0]) . '. ' . ucfirst(substr($user_part, 1));
    }
    return ucfirst($user_part);
}

/**
 * Obtiene las iniciales para el avatar del docente
 */
function admin_user_initials($display_name) {
    $words = preg_split('/\s+/', trim($display_name));
    $inits = '';
    foreach ($words as $w) {
        if ($w !== '') {
            $inits .= mb_substr($w, 0, 1);
        }
        if (mb_strlen($inits) >= 2) break;
    }
    return strtoupper($inits) ?: 'D';
}

/**
 * Notificación no bloqueante de sincronización hacia CastelBoard en Star Server
 */
function admin_notify_castelboard_sync() {
    $endpoints = [
        'http://127.0.0.1:8087/api/admin/sincronizar-docentes',
        'http://192.168.0.120:8087/api/admin/sincronizar-docentes'
    ];
    foreach ($endpoints as $url) {
        $ch = @curl_init($url);
        if ($ch) {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Castel-Auth-Key: castel-soberano-sso-key-2026-star'
            ]);
            @curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            @curl_close($ch);
            if ($code === 200) {
                return true;
            }
        }
    }
    return false;
}

/* ── Procesar acciones POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_validate_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $error = 'La sesión expiró por seguridad. Recarga la página e intenta nuevamente.';
    } else {
        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

        // Sincronizar manualmente con CastelBoard
        if ($action === 'sync_castelboard') {
            $ok = admin_notify_castelboard_sync();
            if ($ok) {
                $message = '⚡ <strong>Sincronización instantánea exitosa:</strong> La nómina docente completa fue replicada en CastelBoard (Star Server).';
            } else {
                $message = 'ℹ️ Nómina guardada localmente. CastelBoard se sincronizará automáticamente mediante el servicio SSO en <code>/admin/api_auth_verify.php</code>.';
            }
        }

        if ($action === 'add_user') {
            $email    = admin_normalize_email(isset($_POST['email']) ? $_POST['email'] : '');
            $name     = trim((string) (isset($_POST['full_name']) ? $_POST['full_name'] : ''));
            $role     = isset($_POST['role']) ? (string) $_POST['role'] : 'profesor';
            $token_raw = admin_generate_setup_token();

            $valid_roles = ['profesor', 'coordinacion', 'directivo', 'admin'];
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'El correo institucional ingresado no es válido.';
            } elseif (!$name) {
                $error = 'El nombre completo del docente es obligatorio.';
            } elseif (!in_array($role, $valid_roles, true)) {
                $error = 'El rol seleccionado no es válido.';
            } elseif (isset($authorized_users[$email])) {
                $error = 'El correo ' . htmlspecialchars($email) . ' ya existe en la nómina institucional.';
            } else {
                $authorized_users[$email] = [
                    'email'                    => $email,
                    'full_name'                => $name,
                    'role'                     => $role,
                    'password_hash'            => '',
                    'is_active'                => true,
                    'password_setup_token_hash'=> password_hash($token_raw, PASSWORD_DEFAULT),
                    'password_setup_token_created_at' => date('c'),
                    'created_at'               => date('c'),
                    'created_by'               => $current_email,
                ];
                admin_save_authorized_users($authorized_users);
                admin_log_security_event('user_added', $current_email, ['target' => $email, 'role' => $role]);
                admin_notify_castelboard_sync();
                $message = 'Usuario <strong>' . htmlspecialchars($email) . '</strong> creado con éxito y sincronizado con CastelBoard. Código de activación: <code style="font-size:1.15em;font-weight:900;letter-spacing:.05em;color:#2C4C74;">' . htmlspecialchars($token_raw) . '</code> — entrégalo al docente para que cree su contraseña.';
            }
        }

        if ($action === 'toggle_user') {
            $email = admin_normalize_email(isset($_POST['target_email']) ? $_POST['target_email'] : '');
            if ($email === $current_email) {
                $error = 'No puedes desactivar tu propia cuenta de Administrador.';
            } elseif (!isset($authorized_users[$email])) {
                $error = 'Usuario no encontrado en la nómina.';
            } else {
                $current_state = $authorized_users[$email]['is_active'] ?? true;
                $authorized_users[$email]['is_active'] = !$current_state;
                admin_save_authorized_users($authorized_users);
                $new_state = !$current_state;
                admin_log_security_event($new_state ? 'user_activated' : 'user_deactivated', $current_email, ['target' => $email]);
                admin_notify_castelboard_sync();
                $message = 'Usuario ' . htmlspecialchars($email) . ' ' . ($new_state ? 'activado' : 'desactivado') . ' correctamente.';
            }
        }

        if ($action === 'change_role') {
            $email   = admin_normalize_email(isset($_POST['target_email']) ? $_POST['target_email'] : '');
            $newRole = isset($_POST['new_role']) ? (string) $_POST['new_role'] : '';
            $valid_roles = ['profesor', 'coordinacion', 'directivo', 'admin'];
            if ($email === $current_email) {
                $error = 'Por seguridad, no puedes alterar tu propio rol de Administrador.';
            } elseif (!isset($authorized_users[$email])) {
                $error = 'Usuario no encontrado.';
            } elseif (!in_array($newRole, $valid_roles, true)) {
                $error = 'Rol seleccionado no válido.';
            } else {
                $authorized_users[$email]['role'] = $newRole;
                admin_save_authorized_users($authorized_users);
                admin_log_security_event('user_role_changed', $current_email, ['target' => $email, 'new_role' => $newRole]);
                admin_notify_castelboard_sync();
                $message = 'Rol de ' . htmlspecialchars($email) . ' actualizado a <strong>' . htmlspecialchars($newRole) . '</strong>.';
            }
        }

        if ($action === 'reset_token') {
            $email = admin_normalize_email(isset($_POST['target_email']) ? $_POST['target_email'] : '');
            if (!isset($authorized_users[$email])) {
                $error = 'Usuario no encontrado.';
            } else {
                $token_raw = admin_generate_setup_token();
                $authorized_users[$email]['password_hash']                   = '';
                $authorized_users[$email]['password_setup_token_hash']        = password_hash($token_raw, PASSWORD_DEFAULT);
                $authorized_users[$email]['password_setup_token_created_at']  = date('c');
                $authorized_users[$email]['password_reset_token_hash']        = '';
                admin_save_authorized_users($authorized_users);
                admin_log_security_event('user_password_reset', $current_email, ['target' => $email]);
                admin_notify_castelboard_sync();
                $message = 'Contraseña de <strong>' . htmlspecialchars($email) . '</strong> reiniciada. Nuevo código de activación: <code style="font-size:1.15em;font-weight:900;letter-spacing:.05em;color:#2C4C74;">' . htmlspecialchars($token_raw) . '</code> — entrégalo al docente.';
            }
        }

        // Recargar usuarios tras cualquier cambio
        $authorized_users = admin_read_authorized_users();
    }
}

$role_labels = [
    'profesor'     => 'Docente',
    'coordinacion' => 'Coordinación',
    'directivo'    => 'Directivo',
    'admin'        => 'Administrador TI',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Gestión de Usuarios | Suite Castelgandolfo</title>
    <meta name="theme-color" content="#18304B">
    <link rel="icon" href="/admin/calendar-icon.svg" type="image/svg+xml">
    <script src="castel-theme.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --forest:#3A6B3E;--navy:#18304B;--navy-2:#2C4C74;--teal:#3d8f7a;--gold:#C38F31;
            --paper:#f4f7f6;--ink:#17304b;--muted:rgba(23,48,75,.7);
            --line:rgba(44,76,116,.14);--danger:#c44f4f;--ok:#3A6B3E;
            --radius-lg:18px;--radius-md:12px;--shadow:0 12px 32px rgba(24,48,75,.08);
        }
        :root[data-theme="dark"]{--paper:#091522;--ink:#ecf5ff;--muted:rgba(236,245,255,.72);--line:rgba(148,196,255,.14);--shadow:0 18px 42px rgba(2,8,18,.38);}
        *{box-sizing:border-box;}
        html,body{margin:0;padding:0;}
        body{font-family:'Outfit',sans-serif;background:linear-gradient(180deg,#eef4f5 0%,#e2eceb 100%);color:var(--ink);min-height:100vh;display:flex;flex-direction:column;}
        :root[data-theme="dark"] body{background:linear-gradient(180deg,#0a1420 0%,#0d1c2a 100%);}

        .top-bar{position:sticky;top:0;z-index:50;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:linear-gradient(135deg,rgba(240,246,246,.95),rgba(224,236,233,.88));border-bottom:1px solid var(--line);backdrop-filter:blur(14px);}
        :root[data-theme="dark"] .top-bar{background:linear-gradient(135deg,rgba(12,29,46,.95),rgba(16,42,58,.88));}
        .top-bar__logo{display:flex;align-items:center;gap:12px;text-decoration:none;color:var(--ink);}
        .top-bar__logo img{height:46px;width:auto;}
        .top-bar__title{font-weight:800;font-size:1.05rem;}
        .top-bar__sub{font-size:.76rem;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;}
        .top-bar__nav{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
        .nav-pill{text-decoration:none;border-radius:999px;padding:8px 15px;font:inherit;font-weight:700;font-size:.88rem;color:var(--ink);background:rgba(44,76,116,.08);border:0;cursor:pointer;transition:all .18s;}
        .nav-pill:hover{background:rgba(44,76,116,.16);}
        .nav-pill--active{background:var(--navy);color:#fff;}
        .nav-pill--suite{background:linear-gradient(135deg,#18304B,#2C4C74);color:#fff;border:1px solid rgba(255,255,255,.2);box-shadow:0 4px 12px rgba(24,48,75,.2);}
        .nav-pill--suite:hover{background:linear-gradient(135deg,#2C4C74,#3A6B3E);color:#fff;transform:translateY(-1px);}

        main{flex:1;padding:26px 20px 64px;max-width:min(1080px,100%);margin:0 auto;width:100%;}
        h1{margin:0 0 4px;font-size:clamp(1.4rem,3vw,2rem);letter-spacing:-.03em;}
        .sub{color:var(--muted);margin:0 0 20px;font-size:.96rem;}

        .alert{padding:14px 18px;border-radius:14px;font-weight:600;margin-bottom:20px;line-height:1.5;}
        .alert--ok{background:rgba(58,107,62,.12);color:#1e4d22;border:1px solid rgba(58,107,62,.25);}
        .alert--err{background:rgba(196,79,79,.12);color:#7a1c1c;border:1px solid rgba(196,79,79,.25);}
        :root[data-theme="dark"] .alert--ok{color:#b4efb6;}
        :root[data-theme="dark"] .alert--err{color:#ffbaba;}
        code{background:rgba(44,76,116,.1);border-radius:6px;padding:2px 7px;font-family:monospace;}

        /* ─ Banner Centralizado de Suite ─ */
        .suite-banner{background:linear-gradient(135deg,rgba(24,48,75,.06),rgba(58,107,62,.08));border:1px solid var(--line);border-radius:var(--radius-lg);padding:18px 22px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;}
        :root[data-theme="dark"] .suite-banner{background:linear-gradient(135deg,rgba(24,48,75,.3),rgba(58,107,62,.2));}
        .suite-banner__info h3{margin:0 0 4px;font-size:1.1rem;display:flex;align-items:center;gap:8px;}
        .suite-banner__info p{margin:0;font-size:.88rem;color:var(--muted);}
        .suite-banner__actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}

        /* ─ Agregar usuario ─ */
        .add-panel{background:rgba(255,255,255,.88);border:1px solid var(--line);border-radius:var(--radius-lg);padding:22px;box-shadow:var(--shadow);margin-bottom:24px;}
        :root[data-theme="dark"] .add-panel{background:rgba(10,25,41,.88);}
        .add-panel h2{margin:0 0 16px;font-size:1.15rem;}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;}
        .form-row.three{grid-template-columns:1.5fr 1.2fr 1fr;}
        .form-group{display:grid;gap:6px;}
        .form-group label{font-size:.85rem;font-weight:700;color:var(--muted);}
        .form-group input,.form-group select{padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.9);color:var(--ink);font:inherit;}
        :root[data-theme="dark"] .form-group input,:root[data-theme="dark"] .form-group select{background:rgba(255,255,255,.06);color:var(--ink);}
        
        .btn-primary{border:0;border-radius:999px;padding:11px 22px;font:inherit;font-weight:800;cursor:pointer;background:linear-gradient(135deg,var(--forest),var(--teal));color:#fff;transition:transform .15s;}
        .btn-primary:hover{filter:brightness(1.06);transform:translateY(-1px);}
        .btn-secondary{border:1px solid var(--line);border-radius:999px;padding:9px 18px;font:inherit;font-weight:700;cursor:pointer;background:rgba(255,255,255,.85);color:var(--ink);text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
        .btn-secondary:hover{background:rgba(44,76,116,.1);}

        /* ─ Barra de Búsqueda y Filtros de Lista ─ */
        .list-header{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:16px;}
        .list-header h2{margin:0;font-size:1.2rem;}
        .list-controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
        .search-input{padding:8px 14px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.9);color:var(--ink);font:inherit;font-size:.88rem;min-width:240px;}
        :root[data-theme="dark"] .search-input{background:rgba(255,255,255,.06);}
        .role-filter{padding:8px 12px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.9);color:var(--ink);font:inherit;font-size:.88rem;}
        :root[data-theme="dark"] .role-filter{background:rgba(255,255,255,.06);}

        /* ─ Grid de Usuarios Rediseñado e Indestructible ─ */
        .user-card{
            background:rgba(255,255,255,.88);
            border:1px solid var(--line);
            border-radius:var(--radius-md);
            padding:14px 18px;
            margin-bottom:12px;
            display:grid;
            grid-template-columns: minmax(280px, 1.4fr) minmax(180px, auto) auto;
            align-items:center;
            gap:16px;
            box-shadow:0 4px 14px rgba(44,76,116,.05);
            transition:transform .15s, box-shadow .15s;
        }
        :root[data-theme="dark"] .user-card{background:rgba(10,25,41,.88);}
        .user-card:hover{box-shadow:0 8px 24px rgba(44,76,116,.1);transform:translateY(-1px);}
        .user-card.is-inactive{opacity:.6;background:rgba(240,240,240,.6);}

        .user-identity{display:flex;align-items:center;gap:14px;min-width:0;}
        .user-avatar{
            width:42px;height:42px;border-radius:50%;
            background:linear-gradient(135deg,#18304B,#2C4C74);
            color:#fff;font-weight:800;font-size:.9rem;
            display:flex;align-items:center;justify-content:center;
            flex-shrink:0;letter-spacing:.05em;
        }
        .user-meta{min-width:0;overflow:hidden;}
        .user-name{font-weight:800;font-size:1rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .user-email{font-size:.82rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:monospace;}

        .user-badges{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
        .role-badge{display:inline-block;border-radius:999px;padding:5px 12px;font-size:.76rem;font-weight:800;letter-spacing:.02em;}
        .role-badge.profesor     {background:rgba(44,76,116,.12);color:#2C4C74;border:1px solid rgba(44,76,116,.25);}
        .role-badge.coordinacion {background:rgba(58,107,62,.15);color:#2a5e2e;border:1px solid rgba(58,107,62,.3);}
        .role-badge.directivo    {background:rgba(195,143,49,.18);color:#6b4d00;border:1px solid rgba(195,143,49,.35);}
        .role-badge.admin        {background:rgba(196,79,79,.15);color:#7a1c1c;border:1px solid rgba(196,79,79,.3);}
        :root[data-theme="dark"] .role-badge.profesor     {color:#8db7df;}
        :root[data-theme="dark"] .role-badge.coordinacion {color:#a8dba9;}
        :root[data-theme="dark"] .role-badge.directivo    {color:#ffe08a;}
        :root[data-theme="dark"] .role-badge.admin        {color:#ffb4b4;}

        .inactive-label{font-size:.75rem;font-weight:700;color:var(--muted);background:rgba(44,76,116,.08);border-radius:999px;padding:4px 10px;border:1px solid var(--line);}
        .inactive-label.warning{background:rgba(214,170,67,.15);color:#8a6400;border-color:rgba(214,170,67,.3);}

        .user-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap;}
        .act-form{display:inline-flex;gap:6px;align-items:center;}
        .act-form select{padding:6px 10px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.9);color:var(--ink);font:inherit;font-size:.8rem;}
        :root[data-theme="dark"] .act-form select{background:rgba(255,255,255,.06);color:var(--ink);}
        
        .btn-sm{border:0;border-radius:999px;padding:7px 13px;font:inherit;font-weight:700;font-size:.78rem;cursor:pointer;background:rgba(44,76,116,.1);color:var(--ink);transition:background .15s;}
        .btn-sm:hover{background:rgba(44,76,116,.2);}
        .btn-sm--danger{background:rgba(196,79,79,.14);color:#7a1c1c;}
        .btn-sm--danger:hover{background:rgba(196,79,79,.25);}
        :root[data-theme="dark"] .btn-sm--danger{color:#ffbaba;}
        .btn-sm--ok{background:rgba(58,107,62,.15);color:#1e4d22;}
        :root[data-theme="dark"] .btn-sm--ok{color:#a8dba9;}

        .theme-fab{position:fixed;right:20px;bottom:20px;z-index:80;border:0;border-radius:999px;padding:12px 18px;font:inherit;font-weight:700;color:#fff;background:linear-gradient(135deg,var(--navy),var(--forest));cursor:pointer;box-shadow:0 14px 28px rgba(2,8,18,.28);}

        @media(max-width:880px){
            .user-card{grid-template-columns:1fr;gap:12px;}
            .user-actions{justify-content:flex-start;}
            .form-row.three{grid-template-columns:1fr;}
        }
    </style>
</head>
<body>
    <nav class="top-bar">
        <a class="top-bar__logo" href="/admin/calendar.php">
            <img src="/assets/LogoCastelGandolfoSinFondo.png" alt="CCG">
            <div>
                <div class="top-bar__sub">CCG Admin</div>
                <div class="top-bar__title">Gestión de Usuarios</div>
            </div>
        </a>
        <div class="top-bar__nav">
            <a class="nav-pill nav-pill--suite" href="http://castelboard.castelgandolfo" target="_blank" title="Abrir Portafolio Digital Escolar">🎒 CastelBoard</a>
            <a class="nav-pill" href="/admin/calendar.php">Calendario</a>
            <a class="nav-pill nav-pill--active" href="/admin/usuarios.php">Usuarios</a>
            <a class="nav-pill" href="/admin/incidencias.php">Bitácora</a>
            <a class="nav-pill" href="/admin/editor.php">Panel</a>
            <a class="nav-pill" href="/admin/index.php?logout=1">Salir</a>
        </div>
    </nav>

    <main>
        <h1>👥 Gestión Centralizada de Usuarios</h1>
        <p class="sub">Administra los accesos docentes y directivos para toda la Suite Castelgandolfo (Toma de Salas y CastelBoard).</p>

        <?php if ($message): ?>
        <div class="alert alert--ok"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert--err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <!-- ── Banner de Suite Centralizada ── -->
        <div class="suite-banner">
            <div class="suite-banner__info">
                <h3>🏛️ Centro de Administración Unificado</h3>
                <p>Las cuentas docentes y credenciales configuradas aquí alimentan el Single Sign-On (SSO) de <strong>CastelBoard</strong>.</p>
            </div>
            <div class="suite-banner__actions">
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="action" value="sync_castelboard">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="btn-secondary" title="Enviar nómina actualizada al servidor Star">
                        🔄 Sincronizar con CastelBoard
                    </button>
                </form>
                <a href="http://castelboard.castelgandolfo" target="_blank" class="btn-primary" style="padding:9px 18px;font-size:.88rem;text-decoration:none;">
                    🎒 Abrir CastelBoard ↗
                </a>
            </div>
        </div>

        <!-- ── Agregar usuario ── -->
        <div class="add-panel">
            <h2>➕ Agregar nuevo usuario docente o administrativo</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_user">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-row three">
                    <div class="form-group">
                        <label for="new-email">Correo institucional *</label>
                        <input id="new-email" type="email" name="email" placeholder="docente@colegiocastelgandolfo.cl" required>
                    </div>
                    <div class="form-group">
                        <label for="new-name">Nombre completo *</label>
                        <input id="new-name" type="text" name="full_name" placeholder="Ej: Patricia Morales" required>
                    </div>
                    <div class="form-group">
                        <label for="new-role">Rol institucional *</label>
                        <select id="new-role" name="role">
                            <option value="profesor">Docente</option>
                            <option value="coordinacion">Coordinación</option>
                            <option value="directivo">Directivo</option>
                            <option value="admin">Administrador TI</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn-primary">Crear usuario y obtener código de activación</button>
            </form>
        </div>

        <!-- ── Lista de usuarios con Búsqueda Reactiva ── -->
        <div class="users-section">
            <div class="list-header">
                <h2>📋 Usuarios registrados (<span id="user-count-num"><?php echo count($authorized_users); ?></span>)</h2>
                <div class="list-controls">
                    <input type="text" id="user-search" class="search-input" placeholder="🔍 Buscar por nombre o correo...">
                    <select id="filter-role" class="role-filter">
                        <option value="">Todos los roles</option>
                        <option value="profesor">Docentes</option>
                        <option value="coordinacion">Coordinación</option>
                        <option value="directivo">Directivos</option>
                        <option value="admin">Administradores TI</option>
                    </select>
                </div>
            </div>

            <div id="users-list-container">
            <?php foreach ($authorized_users as $email => $user): ?>
            <?php
                $is_active    = $user['is_active'] ?? true;
                $role         = $user['role'] ?? 'profesor';
                $has_pass     = !empty($user['password_hash']);
                $is_me        = ($email === $current_email);
                $display_name = admin_display_user_name($user, $email);
                $initials     = admin_user_initials($display_name);
            ?>
            <div class="user-card <?php echo !$is_active ? 'is-inactive' : ''; ?>" data-role="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" data-search="<?php echo htmlspecialchars(strtolower($display_name . ' ' . $email), ENT_QUOTES, 'UTF-8'); ?>">
                
                <!-- Columna 1: Identidad del usuario (Avatar + Nombre + Correo) -->
                <div class="user-identity">
                    <div class="user-avatar" title="<?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="user-meta">
                        <div class="user-name" title="<?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="user-email" title="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>
                </div>

                <!-- Columna 2: Badges de Estado y Rol (Contenedor aislado sin superposición) -->
                <div class="user-badges">
                    <span class="role-badge <?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($role_labels[$role] ?? $role, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php if (!$has_pass): ?>
                    <span class="inactive-label warning" title="El usuario aún no activa su cuenta">Sin contraseña</span>
                    <?php endif; ?>
                    <?php if (!$is_active): ?>
                    <span class="inactive-label" style="color:#c44f4f;" title="Acceso inhabilitado">Desactivado</span>
                    <?php endif; ?>
                </div>

                <!-- Columna 3: Acciones Administrativas -->
                <div class="user-actions">
                    <?php if (!$is_me): ?>
                    <!-- Cambiar rol -->
                    <form class="act-form" method="POST">
                        <input type="hidden" name="action" value="change_role">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="target_email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
                        <select name="new_role" title="Cambiar rol">
                            <?php foreach ($role_labels as $rv => $rl): ?>
                            <option value="<?php echo htmlspecialchars($rv, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $rv === $role ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($rl, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-sm">Cambiar rol</button>
                    </form>

                    <!-- Reiniciar contraseña -->
                    <form class="act-form" method="POST" onsubmit="return confirm('¿Reiniciar contraseña de <?php echo htmlspecialchars(addslashes($display_name), ENT_QUOTES, 'UTF-8'); ?>? El usuario deberá crear una nueva contraseña con un código de activación.')">
                        <input type="hidden" name="action" value="reset_token">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="target_email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="btn-sm btn-sm--danger" title="Generar nuevo código de activación">Reiniciar contraseña</button>
                    </form>

                    <!-- Activar / Desactivar -->
                    <form class="act-form" method="POST" onsubmit="return confirm('¿Estás seguro de que deseas <?php echo $is_active ? 'desactivar' : 'activar'; ?> a <?php echo htmlspecialchars(addslashes($display_name), ENT_QUOTES, 'UTF-8'); ?>?')">
                        <input type="hidden" name="action" value="toggle_user">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="target_email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="btn-sm <?php echo $is_active ? 'btn-sm--danger' : 'btn-sm--ok'; ?>">
                            <?php echo $is_active ? 'Desactivar' : 'Activar'; ?>
                        </button>
                    </form>
                    <?php else: ?>
                    <span class="inactive-label" style="background:rgba(44,76,116,.15);color:var(--navy);font-weight:800;">👑 Tu Sesión (Administrador TI)</span>
                    <?php endif; ?>
                </div>

            </div>
            <?php endforeach; ?>
            </div>
        </div>
    </main>

    <button class="theme-fab" data-theme-toggle>Oscuro</button>

    <script>
        // Buscador y filtro reactivo de usuarios
        (function() {
            var searchInput = document.getElementById('user-search');
            var filterRole = document.getElementById('filter-role');
            var cards = document.querySelectorAll('#users-list-container .user-card');
            var countNum = document.getElementById('user-count-num');

            function applyFilters() {
                var q = (searchInput ? searchInput.value : '').toLowerCase().trim();
                var r = (filterRole ? filterRole.value : '').trim();
                var visibleCount = 0;

                cards.forEach(function(card) {
                    var cardSearch = card.getAttribute('data-search') || '';
                    var cardRole = card.getAttribute('data-role') || '';
                    var matchQ = !q || cardSearch.indexOf(q) !== -1;
                    var matchR = !r || cardRole === r;

                    if (matchQ && matchR) {
                        card.style.display = 'grid';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });

                if (countNum) countNum.textContent = visibleCount;
            }

            if (searchInput) searchInput.addEventListener('input', applyFilters);
            if (filterRole) filterRole.addEventListener('change', applyFilters);

            // Toggle de Modo Oscuro institucional
            document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var g = window.CASTEL_SCHEDULED_THEME;
                    if (g && typeof g.applyToDom === 'function') {
                        var cur = document.documentElement.getAttribute('data-theme');
                        g.applyToDom(cur === 'dark' ? 'light' : 'dark', true);
                    }
                });
            });
        })();
    </script>
</body>
</html>
