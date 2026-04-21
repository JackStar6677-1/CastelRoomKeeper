<?php

function admin_bootstrap_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('castel_admin');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    session_start();
}

function admin_auth_file_path()
{
    return __DIR__ . '/../data/authorized_emails.json';
}

function admin_normalize_email($email)
{
    return strtolower(trim((string) $email));
}

function admin_read_authorized_users()
{
    $auth_file = admin_auth_file_path();
    if (!file_exists($auth_file)) {
        return array();
    }

    $raw = file_get_contents($auth_file);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array();
    }

    $users = array();

    foreach ($decoded as $key => $value) {
        if (is_int($key) && is_string($value)) {
            $email = admin_normalize_email($value);
            $users[$email] = array(
                'email' => $email,
                'full_name' => '',
                'role' => 'profesor',
                'is_active' => true,
                'password_hash' => '',
                'password_created_at' => null,
            );
            continue;
        }

        if (is_string($key) && is_array($value)) {
            $email = admin_normalize_email(isset($value['email']) ? $value['email'] : $key);
            $users[$email] = array(
                'email' => $email,
                'full_name' => isset($value['full_name']) ? (string) $value['full_name'] : '',
                'role' => isset($value['role']) ? (string) $value['role'] : 'profesor',
                'is_active' => array_key_exists('is_active', $value) ? (bool) $value['is_active'] : true,
                'password_hash' => isset($value['password_hash']) ? (string) $value['password_hash'] : '',
                'password_created_at' => isset($value['password_created_at']) ? $value['password_created_at'] : null,
            );
        }
    }

    ksort($users);
    return $users;
}

function admin_save_authorized_users($users)
{
    $payload = array();
    foreach ($users as $email => $user) {
        $normalized = admin_normalize_email($email);
        $payload[$normalized] = array(
            'email' => $normalized,
            'full_name' => isset($user['full_name']) ? (string) $user['full_name'] : '',
            'role' => isset($user['role']) ? (string) $user['role'] : 'profesor',
            'is_active' => array_key_exists('is_active', $user) ? (bool) $user['is_active'] : true,
            'password_hash' => isset($user['password_hash']) ? (string) $user['password_hash'] : '',
            'password_created_at' => isset($user['password_created_at']) ? $user['password_created_at'] : null,
        );
    }

    ksort($payload);

    file_put_contents(
        admin_auth_file_path(),
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function admin_find_user($email, $users)
{
    $email = admin_normalize_email($email);
    return isset($users[$email]) ? $users[$email] : null;
}

function admin_login_user($email)
{
    session_regenerate_id(true);
    $_SESSION['admin_email'] = admin_normalize_email($email);
    unset($_SESSION['pending_admin_email']);
}

function admin_logout_user()
{
    $_SESSION = array();

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

function admin_require_login()
{
    if (empty($_SESSION['admin_email'])) {
        header('Location: index.php');
        exit;
    }

    $user = admin_current_user();
    if (!$user || (array_key_exists('is_active', $user) && !$user['is_active'])) {
        admin_logout_user();
        header('Location: index.php');
        exit;
    }
}

function admin_current_user()
{
    if (empty($_SESSION['admin_email'])) {
        return null;
    }

    $users = admin_read_authorized_users();
    $email = admin_normalize_email($_SESSION['admin_email']);
    return isset($users[$email]) ? $users[$email] : null;
}

function admin_current_user_email()
{
    $user = admin_current_user();
    return $user ? $user['email'] : null;
}

function admin_user_role($user)
{
    if (!is_array($user) || empty($user['role'])) {
        return 'profesor';
    }

    return (string) $user['role'];
}

function admin_user_display_name($user)
{
    if (!is_array($user)) {
        return '';
    }

    if (!empty($user['full_name'])) {
        return (string) $user['full_name'];
    }

    if (!empty($user['email'])) {
        return (string) $user['email'];
    }

    return '';
}

function admin_user_has_calendar_override($user)
{
    return in_array(admin_user_role($user), array('admin', 'directivo', 'coordinacion'), true);
}

function admin_user_can_manage_holidays($user)
{
    return in_array(admin_user_role($user), array('admin', 'directivo', 'coordinacion'), true);
}

function admin_csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function admin_validate_csrf($token)
{
    if (empty($_SESSION['csrf_token']) || !is_string($token)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}
