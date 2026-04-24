<?php
require_once __DIR__ . '/auth.php';

admin_bootstrap_session();

if (isset($_GET['logout'])) {
    admin_logout_user();
    header('Location: index.php');
    exit;
}

if (!empty($_SESSION['admin_email'])) {
    header('Location: editor.php');
    exit;
}

$authorized_users = admin_read_authorized_users();
$step = 'email';
$email_value = '';
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

    if ($action === 'lookup_email') {
        $email_value = admin_normalize_email(isset($_POST['email']) ? $_POST['email'] : '');
        $user = admin_find_user($email_value, $authorized_users);

        if (!$user) {
            $error = 'El correo no está autorizado.';
            $step = 'email';
        } elseif (array_key_exists('is_active', $user) && !$user['is_active']) {
            $error = 'Este correo está desactivado para el panel.';
            $step = 'email';
        } else {
            unset($_SESSION['admin_login_fails'], $_SESSION['admin_login_lock_until']);
            $_SESSION['pending_admin_email'] = $email_value;
            $step = empty($user['password_hash']) ? 'setup' : 'password';
            if ($step === 'setup') {
                $info = 'Primer ingreso detectado. Crea una contraseña personal para este correo.';
            }
        }
    } elseif ($action === 'set_password') {
        $email_value = admin_normalize_email(isset($_SESSION['pending_admin_email']) ? $_SESSION['pending_admin_email'] : '');
        $user = admin_find_user($email_value, $authorized_users);
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $confirm_password = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';

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
        } elseif (strlen($password) < 10) {
            $error = 'La contraseña debe tener al menos 10 caracteres.';
            $step = 'setup';
        } elseif (!hash_equals($password, $confirm_password)) {
            $error = 'Las contraseñas no coinciden.';
            $step = 'setup';
        } else {
            $authorized_users[$email_value]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $authorized_users[$email_value]['password_created_at'] = date('c');
            admin_save_authorized_users($authorized_users);
            admin_login_user($email_value);
            header('Location: editor.php');
            exit;
        }
    } elseif ($action === 'login_password') {
        $email_value = admin_normalize_email(isset($_SESSION['pending_admin_email']) ? $_SESSION['pending_admin_email'] : '');
        $user = admin_find_user($email_value, $authorized_users);
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

        if (!empty($_SESSION['admin_login_lock_until']) && (int) $_SESSION['admin_login_lock_until'] > time()) {
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
            $fails = (int) (isset($_SESSION['admin_login_fails']) ? $_SESSION['admin_login_fails'] : 0) + 1;
            $_SESSION['admin_login_fails'] = $fails;
            if ($fails >= 8) {
                $_SESSION['admin_login_lock_until'] = time() + 600;
                $_SESSION['admin_login_fails'] = 0;
                $error = 'Demasiados intentos fallidos. El acceso queda bloqueado 10 minutos.';
            } else {
                $error = 'La contraseña es incorrecta.';
            }
            $step = 'password';
        } else {
            unset($_SESSION['admin_login_fails'], $_SESSION['admin_login_lock_until']);
            admin_login_user($email_value);
            header('Location: editor.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Admin Login - Colegio Castelgandolfo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Outfit', sans-serif; background: radial-gradient(ellipse 100% 80% at 50% -20%, rgba(31, 99, 187, 0.35), transparent 55%), linear-gradient(180deg, #05080d 0%, #0c1520 100%); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 24px 16px; }
        .login-card { background: linear-gradient(165deg, #121c2c, #0a121c); padding: 40px 36px; border-radius: 20px; border: 1px solid rgba(123, 196, 255, 0.16); box-shadow: 0 28px 60px rgba(2, 6, 14, 0.55); width: 100%; max-width: 420px; text-align: center; color: #e8f4ff; }
        img { max-width: 150px; margin-bottom: 20px; }
        h1 { font-size: 1.45rem; color: #fff; margin: 0 0 20px; font-weight: 800; letter-spacing: -0.02em; }
        input[type="email"], input[type="password"] { width: 100%; padding: 13px 14px; margin-bottom: 18px; border: 1px solid rgba(123, 196, 255, 0.22); border-radius: 12px; box-sizing: border-box; background: rgba(4, 12, 24, 0.85); color: #f2f8ff; font: inherit; }
        input::placeholder { color: rgba(158, 192, 229, 0.55); }
        button { background: linear-gradient(135deg, #1b8252, #1c9a8a); color: white; border: none; padding: 14px 20px; border-radius: 999px; cursor: pointer; width: 100%; font-size: 1rem; font-weight: 700; }
        .error { color: #ffb4b4; margin-bottom: 15px; font-size: 0.92rem; text-align: left; }
        .info { color: #c8ffe4; background: rgba(36, 170, 112, 0.18); border: 1px solid rgba(94, 224, 168, 0.3); border-radius: 12px; padding: 12px 14px; margin-bottom: 15px; font-size: 0.9rem; text-align: left; }
        .hint { color: rgba(200, 220, 245, 0.82); font-size: 0.85rem; margin-top: -8px; margin-bottom: 16px; text-align: left; }
        .secondary-link { display: inline-block; margin-top: 12px; color: #8ec8ff; text-decoration: none; font-size: 0.9rem; font-weight: 600; }
    </style>
</head>
<body>
    <div class="login-card">
        <img src="/app/assets/LogoCastelGandolfoSinFondo.png" alt="Colegio Castelgandolfo" style="max-width:180px;width:100%;height:auto;margin-bottom:12px;">
        <h1>Admin Panel</h1>
        <?php if (isset($error)): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
        <?php if ($info): ?><div class="info"><?php echo $info; ?></div><?php endif; ?>

        <?php if ($step === 'email'): ?>
        <form method="POST">
            <input type="hidden" name="action" value="lookup_email">
            <input type="email" name="email" placeholder="Tu correo electrónico institucional" value="<?php echo htmlspecialchars($email_value); ?>" required>
            <button type="submit">Continuar</button>
        </form>
        <?php elseif ($step === 'setup'): ?>
        <form method="POST">
            <p class="hint">Correo autorizado: <strong><?php echo htmlspecialchars($email_value); ?></strong></p>
            <input type="hidden" name="action" value="set_password">
            <input type="password" name="password" placeholder="Crea una contraseña segura" required>
            <input type="password" name="confirm_password" placeholder="Repite la contraseña" required>
            <button type="submit">Crear contraseña y entrar</button>
        </form>
        <a class="secondary-link" href="index.php?logout=1">Cambiar de correo</a>
        <?php else: ?>
        <form method="POST">
            <p class="hint">Ingresa la contraseña de <strong><?php echo htmlspecialchars($email_value); ?></strong></p>
            <input type="hidden" name="action" value="login_password">
            <input type="password" name="password" placeholder="Tu contraseña" required>
            <button type="submit">Entrar</button>
        </form>
        <a class="secondary-link" href="index.php?logout=1">Usar otro correo</a>
        <?php endif; ?>
        <p style="margin-top:20px; font-size: 0.8rem; color: rgba(180,200,230,0.65);">Solo personal autorizado puede acceder.</p>
    </div>
</body>
</html>
