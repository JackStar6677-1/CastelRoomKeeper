<?php
require_once __DIR__ . '/auth.php';

admin_bootstrap_session();

if (isset($_GET['logout'])) {
    $out_email = admin_normalize_email(isset($_SESSION['admin_email']) ? $_SESSION['admin_email'] : '');
    if ($out_email !== '') {
        admin_log_operation('calendar_auth', 'logout', 'ok', array(
            'email' => $out_email,
            'ip' => admin_client_ip(),
        ), 'Cierre de sesión de usuario');
    }
    admin_logout_user();
    header('Location: index.php');
    exit;
}

if (!empty($_GET['force_login']) && !empty($_SESSION['admin_email'])) {
    admin_logout_user();
    header('Location: index.php?pwa=1');
    exit;
}

if (!empty($_SESSION['admin_email'])) {
    header('Location: hub.php');
    exit;
}

$authorized_users = admin_read_authorized_users();
$step = 'email';
$email_value = '';
$setup_token_value = '';
$info = '';
$error = '';

if (!empty($_SESSION['pending_admin_email'])) {
    $pending_email = admin_normalize_email($_SESSION['pending_admin_email']);
    $pending_user = admin_find_user($pending_email, $authorized_users);
    if ($pending_user) {
        $email_value = $pending_email;
        $step = empty($pending_user['password_hash']) ? 'setup' : 'password';
    } else {
        unset($_SESSION['pending_admin_email']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if (!admin_validate_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $error = 'La sesión expiró. Recarga la página e inténtalo otra vez.';
        $step = 'email';
    } elseif ($action === 'lookup_email') {
        $email_value = admin_normalize_email(isset($_POST['email']) ? $_POST['email'] : '');
        $user = admin_find_user($email_value, $authorized_users);

        if (!filter_var($email_value, FILTER_VALIDATE_EMAIL)) {
            $error = 'Ingresa un correo electrónico válido.';
            $step = 'email';
        } elseif ($user && array_key_exists('is_active', $user) && !$user['is_active']) {
            $error = 'Este correo está desactivado para el panel.';
            $step = 'email';
        } elseif (!$user && !preg_match('/@colegiocastelgandolfo\.cl$/i', $email_value)) {
            $error = 'Por seguridad, el auto-registro con correos externos está cerrado. Si eres docente, ingresa con tu correo institucional (@colegiocastelgandolfo.cl) o contacta a soporte para habilitar tu cuenta.';
            $step = 'email';
        } else {
            $code_rate = admin_code_rate_limit_state($email_value);
            if ((!$user || empty($user['password_hash'])) && !empty($code_rate['locked'])) {
                $error = !empty($code_rate['message']) ? $code_rate['message'] : ('Has solicitado varios códigos recientemente. Por seguridad, espera ' . (int) $code_rate['minutes_left'] . ' minutos antes de intentar de nuevo.');
                $step = 'email';
            } else {
                if (!$user) {
                    $user = admin_normalize_user_record($email_value, array(
                        'full_name' => '',
                        'role' => 'profesor',
                        'is_active' => true,
                        'created_at' => date('c'),
                        'updated_at' => date('c'),
                    ));
                    $authorized_users[$email_value] = $user;
                }

                $_SESSION['pending_admin_email'] = $email_value;
                $step = empty($user['password_hash']) ? 'setup' : 'password';
                if ($step === 'setup') {
                    $setup_token = admin_generate_setup_token();
                    $authorized_users[$email_value]['password_setup_token_hash'] = password_hash($setup_token, PASSWORD_DEFAULT);
                    $authorized_users[$email_value]['password_setup_token_created_at'] = date('c');
                    $authorized_users[$email_value]['password_setup_token_used_at'] = null;
                    $authorized_users[$email_value]['updated_at'] = date('c');

                    if (!admin_save_authorized_users($authorized_users)) {
                        $error = 'No pudimos preparar tu activación. Inténtalo nuevamente.';
                        $step = 'email';
                        unset($_SESSION['pending_admin_email']);
                    } else {
                        $mail_error = '';
                        if (admin_send_setup_email($authorized_users[$email_value], $setup_token, $mail_error)) {
                            admin_record_code_request($email_value);
                            $info = 'Te enviamos un código de activación al correo registrado. Revisa tu bandeja de entrada y spam.';
                        } else {
                            $error = 'No pudimos enviar el código de activación. Inténtalo nuevamente en unos minutos.';
                            $step = 'email';
                            unset($_SESSION['pending_admin_email']);
                        }
                    }
                }
            }
        }
    } elseif ($action === 'set_password') {
        $email_value = admin_normalize_email(!empty($_POST['email']) ? $_POST['email'] : (!empty($_SESSION['pending_admin_email']) ? $_SESSION['pending_admin_email'] : ''));
        $user = admin_find_user($email_value, $authorized_users);
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $confirm_password = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';
        $setup_token = isset($_POST['setup_token']) ? (string) $_POST['setup_token'] : '';
        $setup_token_value = $setup_token;

        if (!$user) {
            $error = 'Tu sesión de acceso expiró. Vuelve a ingresar tu correo.';
            $step = 'email';
            unset($_SESSION['pending_admin_email']);
        } elseif (array_key_exists('is_active', $user) && !$user['is_active']) {
            $error = 'Este correo está desactivado para el panel.';
            $step = 'email';
            unset($_SESSION['pending_admin_email']);
        } elseif (!empty($user['password_hash'])) {
            $error = 'Este correo ya tiene contraseña. Inicia sesión normalmente.';
            $step = 'password';
        } elseif (!admin_setup_token_is_valid($user, $setup_token)) {
            admin_record_login_failure($email_value);
            $error = 'El código de activación no es válido o ya expiró. Vuelve a ingresar tu correo para recibir uno nuevo.';
            $step = 'setup';
        } elseif (strlen($password) < 10) {
            $error = 'La contraseña debe tener al menos 10 caracteres.';
            $step = 'setup';
        } elseif (!hash_equals($password, $confirm_password)) {
            $error = 'Las contraseñas no coinciden.';
            $step = 'setup';
        } else {
            $authorized_users[$email_value]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $authorized_users[$email_value]['password_created_at'] = date('c');
            $authorized_users[$email_value]['password_setup_token_used_at'] = date('c');
            $authorized_users[$email_value]['password_setup_token_hash'] = '';
            admin_save_authorized_users($authorized_users);
            admin_clear_login_failures($email_value);
            admin_log_security_event('password_setup', $email_value);
            admin_login_user($email_value);
            header('Location: hub.php');
            exit;
        }
    } elseif ($action === 'login_password') {
        $email_value = admin_normalize_email(!empty($_POST['email']) ? $_POST['email'] : (!empty($_SESSION['pending_admin_email']) ? $_SESSION['pending_admin_email'] : ''));
        $user = admin_find_user($email_value, $authorized_users);
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $lock = admin_login_lock_state($email_value);

        if (!empty($lock['locked'])) {
            $error = 'Acceso temporalmente restringido por varios intentos fallidos. Espera unos minutos e inténtalo de nuevo.';
            $step = 'password';
        } elseif (!$user) {
            $error = 'Tu sesión de acceso expiró. Vuelve a ingresar tu correo.';
            $step = 'email';
            unset($_SESSION['pending_admin_email']);
        } elseif (array_key_exists('is_active', $user) && !$user['is_active']) {
            $error = 'Este correo está desactivado para el panel.';
            $step = 'email';
            unset($_SESSION['pending_admin_email']);
        } elseif (empty($user['password_hash'])) {
            $error = 'Este correo aún no tiene contraseña configurada. Debes crearla primero.';
            $step = 'setup';
        } elseif (!password_verify($password, $user['password_hash'])) {
            $record = admin_record_login_failure($email_value);
            if (!empty($record['lock_until']) && (int) $record['lock_until'] > time()) {
                $error = 'Demasiados intentos fallidos. El acceso queda bloqueado 15 minutos.';
            } else {
                $error = 'La contraseña es incorrecta.';
            }
            $step = 'password';
        } else {
            admin_clear_login_failures($email_value);
            admin_log_security_event('login_success', $email_value);
            admin_login_user($email_value);
            header('Location: hub.php');
            exit;
        }
    } elseif ($action === 'request_password_reset') {
        $email_value = admin_normalize_email(!empty($_POST['email']) ? $_POST['email'] : (!empty($_SESSION['pending_admin_email']) ? $_SESSION['pending_admin_email'] : ''));
        $user = admin_find_user($email_value, $authorized_users);
        $lock = admin_login_lock_state($email_value);
        $code_rate = admin_code_rate_limit_state($email_value);

        if (!empty($code_rate['locked'])) {
            $error = !empty($code_rate['message']) ? $code_rate['message'] : ('Has solicitado varios códigos recientemente. Por seguridad, espera ' . (int) $code_rate['minutes_left'] . ' minutos antes de intentar de nuevo.');
            $step = 'password';
        } elseif (!empty($lock['locked'])) {
            $error = 'Acceso temporalmente restringido por varios intentos. Espera unos minutos e inténtalo de nuevo.';
            $step = 'password';
        } elseif (!$user || (array_key_exists('is_active', $user) && !$user['is_active'])) {
            $error = 'No se pudo iniciar la recuperación para ese correo.';
            $step = 'email';
            unset($_SESSION['pending_admin_email']);
        } elseif (empty($user['password_hash'])) {
            $error = 'Este correo aún no tiene contraseña. Solicita a administración un código de activación inicial.';
            $step = 'setup';
        } else {
            $token = admin_generate_setup_token();
            $authorized_users[$email_value]['password_reset_token_hash'] = password_hash($token, PASSWORD_DEFAULT);
            $authorized_users[$email_value]['password_reset_token_created_at'] = date('c');
            $authorized_users[$email_value]['password_reset_token_used_at'] = null;
            admin_save_authorized_users($authorized_users);
            $mail_error = null;
            if (admin_send_password_reset_email($authorized_users[$email_value], $token, $mail_error)) {
                admin_record_code_request($email_value);
                admin_log_security_event('password_reset_requested', $email_value);
                $_SESSION['pending_admin_email'] = $email_value;
                $info = 'Te enviamos un código de recuperación al correo registrado. Revisa tu bandeja de entrada.';
                $step = 'reset';
            } else {
                admin_log_security_event('password_reset_mail_failed', $email_value);
                $error = 'No se pudo enviar el correo de recuperación. Detalle: ' . ($mail_error ?: 'sin detalle');
                $step = 'password';
            }
        }
    } elseif ($action === 'reset_password') {
        $email_value = admin_normalize_email(!empty($_POST['email']) ? $_POST['email'] : (!empty($_SESSION['pending_admin_email']) ? $_SESSION['pending_admin_email'] : ''));
        $user = admin_find_user($email_value, $authorized_users);
        $reset_token = isset($_POST['reset_token']) ? (string) $_POST['reset_token'] : '';
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $confirm_password = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';

        if (!$user) {
            $error = 'Tu sesión de recuperación expiró. Vuelve a ingresar tu correo.';
            $step = 'email';
            unset($_SESSION['pending_admin_email']);
        } elseif (!admin_password_reset_token_is_valid($user, $reset_token)) {
            admin_record_login_failure($email_value);
            $error = 'El código de recuperación no es válido o ya expiró.';
            $step = 'reset';
        } elseif (strlen($password) < 10) {
            $error = 'La contraseña debe tener al menos 10 caracteres.';
            $step = 'reset';
        } elseif (!hash_equals($password, $confirm_password)) {
            $error = 'Las contraseñas no coinciden.';
            $step = 'reset';
        } else {
            $authorized_users[$email_value]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $authorized_users[$email_value]['password_created_at'] = date('c');
            $authorized_users[$email_value]['password_reset_token_used_at'] = date('c');
            $authorized_users[$email_value]['password_reset_token_hash'] = '';
            admin_save_authorized_users($authorized_users);
            admin_clear_login_failures($email_value);
            admin_log_security_event('password_reset_completed', $email_value);
            admin_login_user($email_value);
            header('Location: hub.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Admin Login - Colegio Castelgandolfo</title>
    <meta name="theme-color" content="#2C4C74">
    <meta name="application-name" content="Calendario CCG">
    <meta name="apple-mobile-web-app-title" content="Calendario CCG">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="/admin/manifest.webmanifest">
    <link rel="icon" href="/admin/calendar-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/castel-app-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Outfit', sans-serif;
            background:
                radial-gradient(circle at 12% 18%, rgba(123, 196, 255, 0.25), transparent 28%),
                radial-gradient(circle at 88% 82%, rgba(214, 170, 67, 0.22), transparent 30%),
                linear-gradient(135deg, rgba(15, 38, 79, 0.90) 0%, rgba(29, 59, 112, 0.76) 50%, rgba(15, 38, 79, 0.94) 100%),
                url('/wp-content/uploads/2024/10/Colegio-logo-1.jpg') center center / cover no-repeat fixed;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 32px 16px;
            color: #1a2d45;
            box-sizing: border-box;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            display: none !important;
            content: none !important;
        }

        .login-container {
            display: flex;
            flex-direction: row;
            align-items: stretch;
            justify-content: center;
            gap: 28px;
            width: 100%;
            max-width: 980px;
            margin: auto;
            position: relative;
            z-index: 1;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.96);
            padding: 38px 34px;
            border-radius: 22px;
            border: 1px solid rgba(255, 255, 255, 0.7);
            box-shadow: 0 20px 50px rgba(8, 18, 28, 0.45);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            width: 100%;
            max-width: 440px;
            text-align: center;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .info-card {
            background: rgba(255, 255, 255, 0.96);
            padding: 34px 30px;
            border-radius: 22px;
            border: 1px solid rgba(255, 255, 255, 0.7);
            box-shadow: 0 20px 50px rgba(8, 18, 28, 0.45);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            width: 100%;
            max-width: 490px;
            text-align: left;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        @media (max-width: 860px) {
            .login-container {
                flex-direction: column;
                align-items: center;
                gap: 22px;
                max-width: 450px;
            }
            .info-card {
                max-width: 100%;
            }
        }
        @media (max-width: 480px) {
            body {
                padding: 16px 12px;
            }
            .login-card {
                padding: 24px 18px;
                border-radius: 18px;
            }
            .info-card {
                padding: 22px 16px;
                border-radius: 18px;
            }
            button {
                min-height: 48px;
                font-size: 1rem;
            }
        }
        .info-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #eef7f0;
            color: #1b8252;
            border: 1px solid #c8e7d2;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 12px;
        }
        .info-title {
            font-size: 1.15rem;
            font-weight: 800;
            color: #0f264f;
            margin: 0 0 14px;
            line-height: 1.35;
        }
        .info-section {
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid #edf2f7;
        }
        .info-section:last-of-type {
            border-bottom: none;
            margin-bottom: 6px;
            padding-bottom: 0;
        }
        .info-subtitle {
            font-size: 0.86rem;
            font-weight: 700;
            color: #1e3a52;
            margin: 0 0 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .info-text {
            font-size: 0.81rem;
            line-height: 1.5;
            color: #4a5d73;
            margin: 0;
        }
        .info-text strong {
            color: #142a44;
        }
        .info-highlight {
            background: #f0fdf4;
            border-left: 3px solid #16a34a;
            padding: 8px 10px;
            border-radius: 0 8px 8px 0;
            margin: 6px 0 0;
        }
        .info-support {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 0.80rem;
            line-height: 1.45;
            color: #334155;
            margin-top: 10px;
        }
        .info-support a {
            color: #1f63bb;
            font-weight: 700;
            text-decoration: none;
        }
        .info-support a:hover {
            text-decoration: underline;
        }

        /* Acordeón para avisos anteriores */
        .old-notices {
            margin-top: 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 6px 14px 12px;
            transition: all 0.2s ease;
        }
        .old-notices summary {
            font-size: 0.82rem;
            font-weight: 700;
            color: #1e3a52;
            cursor: pointer;
            padding: 6px 0;
            user-select: none;
            outline: none;
        }
        .old-notices summary:hover {
            color: #1f63bb;
        }
        .old-notices[open] {
            background: #ffffff;
            border-color: #cbd5e1;
            box-shadow: 0 4px 14px rgba(0,0,0,0.04);
        }

        img { max-width: 150px; margin-bottom: 20px; }
        h1 { font-size: 1.45rem; color: #0f264f; margin: 0 0 16px; font-weight: 800; letter-spacing: -0.02em; }
        input[type="email"], input[type="password"], input[type="text"] {
            width: 100%;
            padding: 13px 14px;
            margin-bottom: 18px;
            border: 1px solid #c5d4e3;
            border-radius: 12px;
            box-sizing: border-box;
            background: #fbfdff;
            color: #142a44;
            font: inherit;
            font-size: 16px;
            min-height: 46px;
        }
        input::placeholder { color: #7a8fa8; }
        input:focus { outline: 2px solid rgba(31, 99, 187, 0.35); outline-offset: 1px; border-color: #7aa3d6; }
        button {
            background: linear-gradient(135deg, #1b8252, #1c9a8a);
            color: white;
            border: none;
            padding: 14px 20px;
            border-radius: 999px;
            cursor: pointer;
            width: 100%;
            font-size: 1rem;
            font-weight: 700;
        }
        button:hover { filter: brightness(1.03); }
        .error {
            color: #8b1c1c;
            background: #fdecec;
            border: 1px solid #f0b4b4;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 15px;
            font-size: 0.92rem;
            text-align: left;
        }
        .info {
            color: #14532d;
            background: #ecfdf3;
            border: 1px solid #a7f3d0;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 15px;
            font-size: 0.9rem;
            text-align: left;
        }
        .hint { color: #3d5166; font-size: 0.85rem; margin-top: -8px; margin-bottom: 16px; text-align: left; }
        .secondary-link { display: inline-block; margin-top: 12px; color: #1f63bb; text-decoration: none; font-size: 0.9rem; font-weight: 600; }
        .secondary-link:hover { text-decoration: underline; }
        .panel-password-note {
            margin: 0 0 16px;
            padding: 12px 14px;
            border-radius: 12px;
            text-align: left;
            font-size: 0.83rem;
            line-height: 1.45;
            color: #1e3a52;
            background: #f0f7ff;
            border: 1px solid #c5daf0;
        }
        .panel-password-note strong { color: #0f264f; }
        .footer-note {
            margin-top: 18px;
            font-size: 0.77rem;
            line-height: 1.45;
            color: #5a6e82;
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <img src="/assets/LogoCastelGandolfoSinFondo.png" alt="Colegio Castelgandolfo" style="max-width:170px;width:100%;height:auto;margin:0 auto 10px;">
            <span style="font-size:0.76rem;font-weight:800;letter-spacing:0.14em;text-transform:uppercase;color:#1b8252;margin-bottom:4px;display:block;">Acceso Docente y Administrativo</span>
            <h1>Ecosistema Castel</h1>

            <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($info): ?><div class="info"><?php echo htmlspecialchars($info, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

            <?php if ($step === 'email'): ?>
            <p class="panel-password-note">Ingresa tu <strong>correo institucional (@colegiocastelgandolfo.cl)</strong> o tu correo docente verificado para entrar al ecosistema.</p>
            <p class="panel-password-note" style="font-size:0.79rem;background:#fbfcfe;border-color:#e2ecf5;margin-bottom:18px;">Desde aquí accederás de forma centralizada a <strong>CastelBoard, EduDocente IA, Calendario de Salas y herramientas UTP</strong>.</p>
            <form method="POST">
                <input type="hidden" name="action" value="lookup_email">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="email" name="email" placeholder="ejemplo@colegiocastelgandolfo.cl" value="<?php echo htmlspecialchars($email_value); ?>" required>
                <button type="submit">Continuar al Ecosistema</button>
            </form>
            <?php elseif ($step === 'setup'): ?>
            <p class="panel-password-note">Crea tu contraseña <strong>para la Suite Digital Docente</strong>. Esta contraseña unificada te permitirá acceder a CastelBoard, EduDocente IA, Calendario de Salas y herramientas UTP.</p>
            <form method="POST">
                <p class="hint">Código enviado a: <strong><?php echo htmlspecialchars($email_value); ?></strong></p>
                <input type="hidden" name="action" value="set_password">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email_value, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="text" name="setup_token" placeholder="Código de activación (ej. ABCD-EF12-3456)" autocomplete="one-time-code" value="<?php echo htmlspecialchars($setup_token_value, ENT_QUOTES, 'UTF-8'); ?>" required>
                <input type="password" name="password" placeholder="Crea una contraseña segura (mín. 10 car.)" required>
                <input type="password" name="confirm_password" placeholder="Repite la contraseña" required>
                <button type="submit">Crear contraseña y entrar</button>
            </form>
            <a class="secondary-link" href="index.php?logout=1">Cambiar de correo</a>
            <?php elseif ($step === 'reset'): ?>
            <p class="panel-password-note">Ingresa el código que llegó a tu correo y define tu nueva contraseña <strong>para la Suite Digital Docente</strong>. Actualizará tu acceso en todas las plataformas escolares.</p>
            <form method="POST">
                <p class="hint">Recuperación para: <strong><?php echo htmlspecialchars($email_value); ?></strong></p>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email_value, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="text" name="reset_token" placeholder="Código recibido por correo (ej. ABCD-EF12-3456)" autocomplete="one-time-code" required>
                <input type="password" name="password" placeholder="Nueva contraseña de la Suite" required>
                <input type="password" name="confirm_password" placeholder="Repite la nueva contraseña" required>
                <button type="submit">Actualizar contraseña y entrar</button>
            </form>
            <a class="secondary-link" href="index.php?logout=1">Usar otro correo</a>
            <?php else: ?>
            <p class="panel-password-note">Esta es la contraseña unificada de tu cuenta docente en <strong>/admin/</strong>. Al ingresar accederás al selector de aplicaciones escolares (CastelBoard, EduDocente IA, Calendario de Salas).</p>
            <form method="POST">
                <p class="hint">Ingresa la contraseña de <strong><?php echo htmlspecialchars($email_value); ?></strong></p>
                <input type="hidden" name="action" value="login_password">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email_value, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="password" name="password" placeholder="Contraseña de la Suite Escolar" required>
                <button type="submit">Entrar al Ecosistema</button>
            </form>
            <form method="POST" style="margin-top:10px">
                <input type="hidden" name="action" value="request_password_reset">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email_value, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" style="background:#64748b">Olvidé mi contraseña</button>
            </form>
            <a class="secondary-link" href="index.php?logout=1">Usar otro correo</a>
            <?php endif; ?>
            <p class="footer-note">Acceso exclusivo para docentes, directivos, UTP y personal del Colegio Castelgandolfo. <strong>Estudiantes:</strong> Ingresan a sus casilleros por la plataforma independiente con RUT y PIN.</p>
        </div>

        <div class="info-card">
            <div>
                <!-- Aviso Nuevo de Lanzamiento del Ecosistema -->
                <div class="info-badge" style="background:#eef2ff;color:#3730a3;border-color:#c7d2fe;">✨ #Lanzamiento 01/10 · Ecosistema Unificado</div>
                <h2 class="info-title">Portal Único Docente y Suite Castel</h2>

                <div class="info-section">
                    <div class="info-subtitle">🏛️ Acceso Centralizado para Profesores, UTP y Directivos</div>
                    <p class="info-text">Desde hoy este acceso en <code>/admin/</code> reúne todos los servicios pedagógicos del colegio. Al iniciar sesión serás guiado a la <strong>página de elección del ecosistema</strong> para entrar con un clic a cualquiera de las plataformas.</p>
                </div>

                <div class="info-section">
                    <div class="info-subtitle">🎒 Nuevas Plataformas Disponibles</div>
                    <p class="info-text">
                        • <strong>CastelBoard:</strong> Portafolio de aula, casillero de entregas (hasta 100 MB), timbre automático escolar y notas al 60% hacia Sofia School.<br>
                        • <strong>EduDocente Studio:</strong> Generación de pruebas y pautas en Word (.docx) con membrete oficial e IA gratuita.<br>
                        • <strong>Calendario de Salas:</strong> Reserva de bloques para Sala Básica y Sala Media.
                    </p>
                </div>

                <div class="info-section">
                    <div class="info-subtitle">🧑‍🎓 Estudiantes: Acceso Separado</div>
                    <div class="info-highlight" style="background:#f8fafc;border-left-color:#3b82f6;">
                        <p class="info-text" style="color:#1e3a8a;"><strong>Plataforma de Alumnos Independiente:</strong> Los estudiantes no acceden por este panel. Ellos entregan sus proyectos en su casillero digital directo ingresando únicamente con su <strong>RUT y PIN de 4 dígitos</strong>.</p>
                    </div>
                </div>

                <!-- Extendible / Acordeón de Avisos Anteriores -->
                <details class="old-notices">
                    <summary>📜 Ver avisos anteriores e historial de cambios (1)</summary>
                    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e2e8f0;">
                        <div class="info-badge" style="margin-bottom:8px;">🔒 #Cambio 25/09 · Seguridad Preventiva</div>
                        <h3 style="font-size:0.96rem;margin:0 0 8px;color:#0f264f;">Actualización sobre el acceso al calendario</h3>

                        <div class="info-section" style="padding-bottom:6px;margin-bottom:6px;">
                            <div class="info-subtitle" style="font-size:0.80rem;">🏛️ ¿Por qué este cambio?</div>
                            <p class="info-text" style="font-size:0.78rem;">Para asegurar que exclusivamente docentes autorizados gestionen salas, el registro requiere correo institucional @colegiocastelgandolfo.cl.</p>
                        </div>

                        <div class="info-section" style="padding-bottom:6px;margin-bottom:6px;">
                            <div class="info-subtitle" style="font-size:0.80rem;">✉️ Correo Institucional & Webmail</div>
                            <p class="info-text" style="font-size:0.78rem;">El código de confirmación se despacha a tu Webmail escolar o a tu Gmail vinculado (vía POP3).</p>
                        </div>

                        <div class="info-section" style="border:none;margin-bottom:0;padding-bottom:0;">
                            <div class="info-subtitle" style="font-size:0.80rem;">✅ Cuentas @gmail.com ya verificadas</div>
                            <p class="info-text" style="font-size:0.78rem;color:#166534;">Si te habías registrado previamente con Gmail, tu cuenta sigue activa y puedes entrar normalmente.</p>
                        </div>
                    </div>
                </details>
            </div>

            <div class="info-support">
                <strong>¿Dudas, olvidaste tu clave o necesitas habilitar una cátedra?</strong><br>
                Escribe directamente a <a href="mailto:pavendano@colegiocastelgandolfo.cl">pavendano@colegiocastelgandolfo.cl</a> o acércate al Laboratorio de Informática para asistencia presencial inmediata.
            </div>
        </div>
    </div>
    <script src="/admin/pwa.js" defer></script>
</body>
</html>
