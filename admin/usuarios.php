<?php
require_once __DIR__ . '/auth.php';

admin_bootstrap_session();
admin_require_login();

$current_user = admin_current_user();
$current_role = admin_user_role($current_user);
$is_full_admin = $current_role === 'admin';
$is_continuity_manager = in_array($current_role, array('admin', 'directivo'), true);

// Dirección conserva una vía de continuidad para crear un administrador, sin obtener control total de usuarios.
if (!$is_continuity_manager) {
    header('Location: hub.php');
    exit;
}

$csrf_token    = admin_csrf_token();
$current_email = admin_normalize_email($current_user['email'] ?? '');

$authorized_users = admin_read_authorized_users();
$message = '';
$error   = '';

function admin_panel_active_admin_count($users)
{
    $count = 0;
    foreach ($users as $user) {
        if (admin_user_role($user) === 'admin' && !empty($user['is_active'])) {
            $count++;
        }
    }
    return $count;
}

/* ── Procesar acciones POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_validate_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $error = 'La sesión expiró. Recarga la página.';
    } else {
        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

        if (!$is_full_admin && $action !== 'add_user') {
            $error = 'Dirección solo puede crear un nuevo administrador para mantener la continuidad del sistema.';
        }

        if ($error === '' && $action === 'add_user') {
            $email    = admin_normalize_email(isset($_POST['email'])     ? $_POST['email'] : '');
            $name     = trim((string) (isset($_POST['full_name']) ? $_POST['full_name'] : ''));
            $role     = isset($_POST['role']) ? (string) $_POST['role'] : 'profesor';
            $token_raw = admin_generate_setup_token();

            $valid_roles = ['profesor', 'coordinacion', 'directivo', 'admin'];
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'El correo no es válido.';
            } elseif (!$name) {
                $error = 'El nombre completo es obligatorio.';
            } elseif (!in_array($role, $valid_roles, true)) {
                $error = 'Rol inválido.';
            } elseif (!$is_full_admin && $role !== 'admin') {
                $error = 'Dirección solo puede crear cuentas de Administrador del sistema.';
            } elseif (isset($authorized_users[$email])) {
                $error = 'El correo ' . htmlspecialchars($email) . ' ya existe en el sistema.';
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
                if (!admin_save_authorized_users($authorized_users)) {
                    $error = 'No se pudo guardar el nuevo usuario.';
                } else {
                    $mailError = null;
                    $mailSent = admin_send_setup_email($authorized_users[$email], $token_raw, $mailError);
                    admin_log_security_event('user_added', $current_email, ['target' => $email, 'role' => $role, 'activation_email' => $mailSent ? 'accepted' : 'failed']);
                    $message = $mailSent
                        ? 'Usuario <strong>' . htmlspecialchars($email) . '</strong> agregado. El código de activación fue enviado directamente a su correo.'
                        : 'Usuario <strong>' . htmlspecialchars($email) . '</strong> agregado, pero el correo de activación no fue aceptado por SMTP. Revisa la bitácora antes de pedirle que ingrese.';
                }
            }
        }

        if ($error === '' && $action === 'toggle_user') {
            $email = admin_normalize_email(isset($_POST['target_email']) ? $_POST['target_email'] : '');
            if ($email === $current_email) {
                $error = 'No puedes desactivarte a ti mismo.';
            } elseif (!isset($authorized_users[$email])) {
                $error = 'Usuario no encontrado.';
            } else {
                $current_state = $authorized_users[$email]['is_active'] ?? true;
                if ($current_state && admin_user_role($authorized_users[$email]) === 'admin' && admin_panel_active_admin_count($authorized_users) <= 1) {
                    $error = 'No se puede desactivar al último Administrador del sistema activo.';
                } else {
                    $authorized_users[$email]['is_active'] = !$current_state;
                    admin_save_authorized_users($authorized_users);
                    $new_state = !$current_state;
                    admin_log_security_event($new_state ? 'user_activated' : 'user_deactivated', $current_email, ['target' => $email]);
                    $message = 'Usuario ' . htmlspecialchars($email) . ' ' . ($new_state ? 'activado' : 'desactivado') . '.';
                }
            }
        }

        if ($error === '' && $action === 'change_role') {
            $email   = admin_normalize_email(isset($_POST['target_email']) ? $_POST['target_email'] : '');
            $newRole = isset($_POST['new_role']) ? (string) $_POST['new_role'] : '';
            $valid_roles = ['profesor', 'coordinacion', 'directivo', 'admin'];
            if ($email === $current_email) {
                $error = 'No puedes cambiar tu propio rol.';
            } elseif (!isset($authorized_users[$email])) {
                $error = 'Usuario no encontrado.';
            } elseif (!in_array($newRole, $valid_roles, true)) {
                $error = 'Rol inválido.';
            } elseif (admin_user_role($authorized_users[$email]) === 'admin' && $newRole !== 'admin' && !empty($authorized_users[$email]['is_active']) && admin_panel_active_admin_count($authorized_users) <= 1) {
                $error = 'No se puede rebajar al último Administrador del sistema activo.';
            } else {
                $authorized_users[$email]['role'] = $newRole;
                admin_save_authorized_users($authorized_users);
                admin_log_security_event('user_role_changed', $current_email, ['target' => $email, 'new_role' => $newRole]);
                $message = 'Rol de ' . htmlspecialchars($email) . ' cambiado a <strong>' . htmlspecialchars($newRole) . '</strong>.';
            }
        }

        if ($error === '' && $action === 'reset_token') {
            $email = admin_normalize_email(isset($_POST['target_email']) ? $_POST['target_email'] : '');
            if (!isset($authorized_users[$email])) {
                $error = 'Usuario no encontrado.';
            } else {
                $token_raw = admin_generate_setup_token();
                $authorized_users[$email]['password_hash']                   = '';
                $authorized_users[$email]['password_setup_token_hash']        = password_hash($token_raw, PASSWORD_DEFAULT);
                $authorized_users[$email]['password_setup_token_created_at']  = date('c');
                $authorized_users[$email]['password_reset_token_hash']        = '';
                if (!admin_save_authorized_users($authorized_users)) {
                    $error = 'No se pudo reiniciar la contraseña.';
                } else {
                    $mailError = null;
                    $mailSent = admin_send_setup_email($authorized_users[$email], $token_raw, $mailError);
                    admin_log_security_event('user_password_reset', $current_email, ['target' => $email, 'activation_email' => $mailSent ? 'accepted' : 'failed']);
                    $message = $mailSent
                        ? 'La contraseña de <strong>' . htmlspecialchars($email) . '</strong> fue reiniciada y el nuevo código se envió a su correo.'
                        : 'La contraseña fue reiniciada, pero el correo de activación no fue aceptado por SMTP. Revisa la bitácora.';
                }
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
    'admin'        => 'Administrador del sistema',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Gestión de Usuarios | CCG Admin</title>
    <meta name="theme-color" content="#2C4C74">
    <link rel="icon" href="/admin/calendar-icon.svg" type="image/svg+xml">
    <script src="castel-theme.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --forest:#4E8452;--navy:#2C4C74;--teal:#3d8f7a;--gold:#d6aa43;
            --paper:#f0f5f1;--ink:#17304b;--muted:rgba(23,48,75,.7);
            --line:rgba(44,76,116,.14);--danger:#c44f4f;--ok:#4E8452;
            --radius-lg:20px;--radius-md:14px;--shadow:0 16px 36px rgba(44,76,116,.12);
        }
        :root[data-theme="dark"]{--paper:#081625;--ink:#ecf5ff;--muted:rgba(236,245,255,.72);--line:rgba(148,196,255,.14);--shadow:0 18px 42px rgba(2,8,18,.38);}
        *{box-sizing:border-box;}
        html,body{margin:0;padding:0;}
        body{font-family:'Outfit',sans-serif;background:linear-gradient(180deg,#eaf1f2 0%,#dfe9ea 100%);color:var(--ink);min-height:100vh;display:flex;flex-direction:column;}
        :root[data-theme="dark"] body{background:linear-gradient(180deg,#0a1420 0%,#0d1c2a 100%);}

        .top-bar{position:sticky;top:0;z-index:50;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:linear-gradient(135deg,rgba(238,245,245,.92),rgba(220,232,229,.78));border-bottom:1px solid var(--line);backdrop-filter:blur(14px);}
        :root[data-theme="dark"] .top-bar{background:linear-gradient(135deg,rgba(12,29,46,.92),rgba(16,42,58,.82));}
        .top-bar__logo{display:flex;align-items:center;gap:12px;text-decoration:none;color:var(--ink);}
        .top-bar__logo img{height:46px;width:auto;}
        .top-bar__title{font-weight:800;font-size:1.05rem;}
        .top-bar__sub{font-size:.76rem;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;}
        .top-bar__nav{display:flex;gap:8px;flex-wrap:wrap;}
        .nav-pill{text-decoration:none;border-radius:999px;padding:8px 14px;font:inherit;font-weight:700;font-size:.88rem;color:var(--ink);background:rgba(44,76,116,.07);border:0;cursor:pointer;transition:background .18s;}
        .nav-pill:hover{background:rgba(78,132,82,.14);}
        .nav-pill--active{background:linear-gradient(135deg,#4E8452,#3a6b3e);color:#fff;}

        main{flex:1;padding:22px 16px 64px;max-width:min(900px,100%);margin:0 auto;width:100%;}
        h1{margin:0 0 4px;font-size:clamp(1.4rem,3vw,2rem);letter-spacing:-.03em;}
        .sub{color:var(--muted);margin:0 0 22px;font-size:.96rem;}

        .alert{padding:13px 16px;border-radius:14px;font-weight:700;margin-bottom:18px;line-height:1.45;}
        .alert--ok{background:rgba(78,132,82,.12);color:#1e4d22;border:1px solid rgba(78,132,82,.22);}
        .alert--err{background:rgba(196,79,79,.12);color:#7a1c1c;border:1px solid rgba(196,79,79,.22);}
        :root[data-theme="dark"] .alert--ok{color:#b4efb6;}
        :root[data-theme="dark"] .alert--err{color:#ffbaba;}
        code{background:rgba(44,76,116,.1);border-radius:6px;padding:2px 7px;font-family:monospace;}

        /* ─ Agregar usuario ─ */
        .add-panel{background:rgba(255,255,255,.78);border:1px solid var(--line);border-radius:var(--radius-lg);padding:22px;box-shadow:var(--shadow);margin-bottom:24px;}
        :root[data-theme="dark"] .add-panel{background:rgba(10,25,41,.78);}
        .add-panel h2{margin:0 0 16px;font-size:1.15rem;}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;}
        .form-row.three{grid-template-columns:1.5fr 1fr 1fr;}
        .form-group{display:grid;gap:6px;}
        .form-group label{font-size:.85rem;font-weight:700;color:var(--muted);}
        .form-group input,.form-group select{padding:10px 12px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.85);color:var(--ink);font:inherit;}
        :root[data-theme="dark"] .form-group input,:root[data-theme="dark"] .form-group select{background:rgba(255,255,255,.06);color:var(--ink);}
        .btn-primary{border:0;border-radius:999px;padding:11px 22px;font:inherit;font-weight:800;cursor:pointer;background:linear-gradient(135deg,#4E8452,#3d8f7a);color:#fff;}
        .btn-primary:hover{filter:brightness(1.04);}

        /* ─ Tabla usuarios ─ */
        .users-section h2{margin:0 0 14px;font-size:1.15rem;}
        .user-card{background:rgba(255,255,255,.78);border:1px solid var(--line);border-radius:var(--radius-md);padding:14px 16px;margin-bottom:10px;display:flex;flex-wrap:wrap;align-items:center;gap:12px;box-shadow:0 8px 20px rgba(44,76,116,.07);}
        :root[data-theme="dark"] .user-card{background:rgba(10,25,41,.78);}
        .user-card.is-inactive{opacity:.55;}
        .user-info{flex:1;min-width:0;}
        .user-name{font-weight:800;font-size:.98rem;}
        .user-email{font-size:.82rem;color:var(--muted);}
        .role-badge{display:inline-block;border-radius:999px;padding:4px 11px;font-size:.74rem;font-weight:700;}
        .role-badge.profesor     {background:rgba(44,76,116,.1);color:#2C4C74;}
        .role-badge.coordinacion {background:rgba(78,132,82,.15);color:#2a5e2e;}
        .role-badge.directivo    {background:rgba(214,170,67,.18);color:#6b4d00;}
        .role-badge.admin        {background:rgba(196,79,79,.14);color:#7a1c1c;}
        :root[data-theme="dark"] .role-badge.profesor     {color:#8db7df;}
        :root[data-theme="dark"] .role-badge.coordinacion {color:#a8dba9;}
        :root[data-theme="dark"] .role-badge.directivo    {color:#ffe08a;}
        :root[data-theme="dark"] .role-badge.admin        {color:#ffb4b4;}

        .user-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
        .act-form{display:inline-flex;gap:6px;align-items:center;}
        .act-form select{padding:6px 10px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.8);color:var(--ink);font:inherit;font-size:.8rem;}
        :root[data-theme="dark"] .act-form select{background:rgba(255,255,255,.06);color:var(--ink);}
        .btn-sm{border:0;border-radius:999px;padding:7px 13px;font:inherit;font-weight:700;font-size:.78rem;cursor:pointer;background:rgba(44,76,116,.1);color:var(--ink);}
        .btn-sm:hover{background:rgba(44,76,116,.18);}
        .btn-sm--danger{background:rgba(196,79,79,.12);color:#7a1c1c;}
        .btn-sm--danger:hover{background:rgba(196,79,79,.2);}
        :root[data-theme="dark"] .btn-sm--danger{color:#ffbaba;}
        .btn-sm--ok{background:rgba(78,132,82,.14);color:#1e4d22;}
        :root[data-theme="dark"] .btn-sm--ok{color:#a8dba9;}

        .inactive-label{font-size:.76rem;font-weight:700;color:var(--muted);background:rgba(44,76,116,.08);border-radius:999px;padding:4px 10px;}

        .theme-fab{position:fixed;right:16px;bottom:16px;z-index:80;border:0;border-radius:999px;padding:12px 16px;font:inherit;font-weight:700;color:#fff;background:linear-gradient(135deg,rgba(44,76,116,.95),rgba(78,132,82,.9));cursor:pointer;box-shadow:0 14px 28px rgba(2,8,18,.28);}

        @media(max-width:680px){
            .top-bar{padding:10px 12px;}
            .top-bar__nav{overflow-x:auto;white-space:nowrap;width:100%;padding-bottom:4px;-webkit-overflow-scrolling:touch;}
            .form-row,.form-row.three{grid-template-columns:1fr;}
            .form-group input,.form-group select{font-size:16px;min-height:44px;}
            .btn-primary{width:100%;min-height:44px;}
            .user-card{flex-direction:column;align-items:stretch;}
            .user-actions{flex-direction:column;align-items:stretch;}
            .act-form{width:100%;justify-content:space-between;}
            .act-form select{min-height:40px;font-size:15px;}
            .btn-sm{min-height:40px;text-align:center;}
        }
    </style>
</head>
<body>
    <nav class="top-bar">
        <a class="top-bar__logo" href="/admin/hub.php" title="Volver al Hub Central">
            <img src="/assets/LogoCastelGandolfoSinFondo.png" alt="CCG">
            <div>
                <div class="top-bar__sub">CCG Admin</div>
                <div class="top-bar__title">Gestión de Usuarios</div>
            </div>
        </a>
        <div class="top-bar__nav">
            <a class="nav-pill" href="/admin/hub.php" style="background:rgba(123,196,255,0.22);color:#fff;">🏛️ Hub</a>
            <a class="nav-pill" href="/admin/calendar.php">📅 Calendario</a>
            <a class="nav-pill nav-pill--active" href="/admin/usuarios.php">👥 Usuarios</a>
            <a class="nav-pill" href="/admin/logs.php">📜 Logs</a>
            <?php if ($is_full_admin): ?>
            <a class="nav-pill" href="/admin/documentos.php">📁 Documentos</a>
            <?php endif; ?>
            <button class="nav-pill" data-theme-toggle>Oscuro</button>
            <a class="nav-pill" href="/admin/index.php?logout=1" style="color:#ffc9c9;">Salir</a>
        </div>
    </nav>

    <main>
        <h1>👥 Gestión de Usuarios</h1>
        <p class="sub"><?php echo $is_full_admin ? 'Administra accesos, roles y recuperación de cuentas.' : 'Puedes crear un Administrador del sistema para asegurar la continuidad institucional.'; ?></p>

        <?php if ($message): ?>
        <div class="alert alert--ok"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert--err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <!-- ── Agregar usuario ── -->
        <div class="add-panel">
            <h2>➕ Agregar nuevo usuario</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_user">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-row three">
                    <div class="form-group">
                        <label for="new-email">Correo del docente</label>
                        <input id="new-email" type="email" name="email" placeholder="docente@gmail.com o docente@colegiocastelgandolfo.cl" required>
                    </div>
                    <div class="form-group">
                        <label for="new-name">Nombre completo</label>
                        <input id="new-name" type="text" name="full_name" placeholder="Nombre Apellido" required>
                    </div>
                    <div class="form-group">
                        <label for="new-role">Rol</label>
                        <?php if ($is_full_admin): ?>
                        <select id="new-role" name="role">
                            <option value="profesor">Docente</option>
                            <option value="coordinacion">Coordinación</option>
                            <option value="directivo">Directivo</option>
                            <option value="admin">Administrador del sistema</option>
                        </select>
                        <?php else: ?>
                        <input id="new-role" type="text" value="Administrador del sistema" readonly>
                        <input type="hidden" name="role" value="admin">
                        <?php endif; ?>
                    </div>
                </div>
                <button type="submit" class="btn-primary">Crear usuario y obtener código de activación</button>
            </form>
        </div>

        <!-- ── Lista de usuarios ── -->
        <div class="users-section">
            <h2>📋 Usuarios registrados (<?php echo count($authorized_users); ?>)</h2>
            <?php foreach ($authorized_users as $email => $user): ?>
            <?php
                $is_active  = $user['is_active'] ?? true;
                $role       = $user['role'] ?? 'profesor';
                $has_pass   = !empty($user['password_hash']);
                $is_me      = ($email === $current_email);
            ?>
            <div class="user-card <?php echo !$is_active ? 'is-inactive' : ''; ?>">
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($user['full_name'] ?? $email, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <span class="role-badge <?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($role_labels[$role] ?? $role, ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php if (!$has_pass): ?>
                <span class="inactive-label">Sin contraseña</span>
                <?php endif; ?>
                <?php if (!$is_active): ?>
                <span class="inactive-label">Desactivado</span>
                <?php endif; ?>

                <?php if ($is_full_admin && !$is_me): ?>
                <div class="user-actions">
                    <!-- Cambiar rol -->
                    <form class="act-form" method="POST">
                        <input type="hidden" name="action" value="change_role">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="target_email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
                        <select name="new_role">
                            <?php foreach ($role_labels as $rv => $rl): ?>
                            <option value="<?php echo htmlspecialchars($rv, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $rv === $role ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($rl, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-sm">Cambiar rol</button>
                    </form>

                    <!-- Reiniciar contraseña -->
                    <form class="act-form" method="POST" onsubmit="return confirm('¿Reiniciar contraseña de <?php echo htmlspecialchars(addslashes($email), ENT_QUOTES, 'UTF-8'); ?>? El usuario deberá crear una nueva.')">
                        <input type="hidden" name="action" value="reset_token">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="target_email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="btn-sm btn-sm--danger">Reiniciar contraseña</button>
                    </form>

                    <!-- Activar / Desactivar -->
                    <form class="act-form" method="POST" onsubmit="return confirm('¿<?php echo $is_active ? 'Desactivar' : 'Activar'; ?> a <?php echo htmlspecialchars(addslashes($email), ENT_QUOTES, 'UTF-8'); ?>?')">
                        <input type="hidden" name="action" value="toggle_user">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="target_email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="btn-sm <?php echo $is_active ? 'btn-sm--danger' : 'btn-sm--ok'; ?>">
                            <?php echo $is_active ? 'Desactivar' : 'Activar'; ?>
                        </button>
                    </form>
                </div>
                <?php else: ?>
                <span class="inactive-label">Tú</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </main>

    <button class="theme-fab" data-theme-toggle>Oscuro</button>
    <script>
        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var g = window.CASTEL_SCHEDULED_THEME;
                if (g && typeof g.applyToDom === 'function') {
                    var cur = document.documentElement.getAttribute('data-theme');
                    g.applyToDom(cur === 'dark' ? 'light' : 'dark', true);
                }
            });
        });
    </script>
</body>
</html>
