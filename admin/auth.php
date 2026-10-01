<?php

function admin_send_security_headers()
{
    static $sent = false;
    if ($sent || headers_sent()) {
        return;
    }
    $sent = true;
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    $csp = "default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";
    header('Content-Security-Policy: ' . $csp);
}

function admin_client_ip()
{
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $value = trim((string) $_SERVER[$key]);
        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = explode(',', $value);
            $value = trim((string) $parts[0]);
        }
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }
    }
    return 'unknown';
}

function admin_bootstrap_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        admin_send_security_headers();
        return;
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('castel_admin');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/admin/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    session_start();
    admin_enforce_session_limits();
    admin_send_security_headers();
}

function admin_enforce_session_limits()
{
    if (empty($_SESSION['admin_email'])) {
        return;
    }

    $now = time();
    $idleLimit = 45 * 60;
    $absoluteLimit = 12 * 60 * 60;

    if (empty($_SESSION['admin_login_started_at'])) {
        $_SESSION['admin_login_started_at'] = $now;
    }
    if (empty($_SESSION['admin_last_seen_at'])) {
        $_SESSION['admin_last_seen_at'] = $now;
    }

    $idleAge = $now - (int) $_SESSION['admin_last_seen_at'];
    $absoluteAge = $now - (int) $_SESSION['admin_login_started_at'];
    if ($idleAge > $idleLimit || $absoluteAge > $absoluteLimit) {
        admin_logout_user();
        return;
    }

    $_SESSION['admin_last_seen_at'] = $now;
}

function admin_auth_file_path()
{
    return __DIR__ . '/../data/authorized_emails.json';
}

function admin_login_locks_path()
{
    return __DIR__ . '/../data/admin_login_locks.json';
}

function admin_security_log_path()
{
    return __DIR__ . '/../data/admin_security_events.log';
}

function admin_mail_delivery_log_path()
{
    return __DIR__ . '/../data/admin_mail_delivery.log';
}

function admin_mail_delivery_mysql_table_name()
{
    return 'ccg_admin_mail_delivery';
}

function admin_operation_log_mysql_table_name()
{
    return 'ccg_admin_operation_log';
}

/** Registra operaciones y errores sin incluir codigos, contrasenas ni secretos. */
function admin_log_operation($area, $event, $status, $context = array(), $detail = '')
{
    $detail = trim(preg_replace('/[\r\n\t]+/', ' ', (string) $detail));
    $context = is_array($context) ? $context : array();
    $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($contextJson === false) {
        $contextJson = '{}';
    }

    $record = array(
        'at' => date('c'),
        'area' => substr((string) $area, 0, 80),
        'event' => substr((string) $event, 0, 120),
        'status' => substr((string) $status, 0, 32),
        'detail' => substr($detail, 0, 800),
        'context_json' => $contextJson,
        'ip_hash' => hash('sha256', admin_client_ip()),
    );

    $conn = admin_db_connect();
    if ($conn) {
        $table = admin_operation_log_mysql_table_name();
        $schema = 'CREATE TABLE IF NOT EXISTS `' . $table . '` ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`occurred_at` VARCHAR(40) NOT NULL,'
            . '`area` VARCHAR(80) NOT NULL,'
            . '`event_name` VARCHAR(120) NOT NULL,'
            . '`status` VARCHAR(32) NOT NULL,'
            . '`detail` TEXT NOT NULL,'
            . '`context_json` LONGTEXT NOT NULL,'
            . '`ip_hash` CHAR(64) NOT NULL,'
            . 'PRIMARY KEY (`id`), KEY `idx_ccg_operation_area_event` (`area`, `event_name`, `id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        if (@mysqli_query($conn, $schema)) {
            $stmt = @mysqli_prepare($conn, 'INSERT INTO `' . $table . '` (`occurred_at`, `area`, `event_name`, `status`, `detail`, `context_json`, `ip_hash`) VALUES (?, ?, ?, ?, ?, ?, ?)');
            if ($stmt) {
                $at = $record['at']; $logArea = $record['area']; $eventName = $record['event'];
                $logStatus = $record['status']; $logDetail = $record['detail']; $logContext = $record['context_json']; $ipHash = $record['ip_hash'];
                $bound = @mysqli_stmt_bind_param($stmt, 'sssssss', $at, $logArea, $eventName, $logStatus, $logDetail, $logContext, $ipHash);
                $saved = $bound && @mysqli_stmt_execute($stmt);
                @mysqli_stmt_close($stmt);
                @mysqli_close($conn);
                if ($saved) {
                    return true;
                }
            }
        }
        @mysqli_close($conn);
    }

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents(__DIR__ . '/../data/admin_operation.log', $line . PHP_EOL, FILE_APPEND);
    return false;
}

function admin_tools_config_path()
{
    return __DIR__ . '/../data/admin_tools.json';
}

function admin_maintenance_tools_enabled()
{
    $path = admin_tools_config_path();
    if (!is_file($path)) {
        return false;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return false;
    }

    return !empty($decoded['maintenance_tools_enabled']);
}

function admin_require_maintenance_tools_enabled()
{
    admin_require_site_admin();
    if (!admin_maintenance_tools_enabled()) {
        http_response_code(404);
        echo 'Herramienta de mantenimiento desactivada en producción.';
        exit;
    }
}

function admin_db_config()
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/../wp-config.php';
    if (!is_file($path)) {
        $config = false;
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        $config = false;
        return null;
    }

    $parsed = array();
    foreach (array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST') as $key) {
        if (preg_match('/define\s*\(\s*[\'"]' . preg_quote($key, '/') . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)\s*;/', $raw, $match) !== 1) {
            $config = false;
            return null;
        }
        $parsed[$key] = $match[1];
    }

    $host = trim((string) $parsed['DB_HOST']);
    $port = 0;
    if (preg_match('/^(.+):([0-9]+)$/', $host, $match) === 1) {
        $host = $match[1];
        $port = (int) $match[2];
    }

    $config = array(
        'name' => $parsed['DB_NAME'],
        'user' => $parsed['DB_USER'],
        'password' => $parsed['DB_PASSWORD'],
        'host' => $host,
        'port' => $port,
    );
    return $config;
}

function admin_db_connect()
{
    $config = admin_db_config();
    if (!$config || !function_exists('mysqli_init')) {
        return null;
    }

    if (function_exists('mysqli_report')) {
        @mysqli_report(MYSQLI_REPORT_OFF);
    }

    try {
        $conn = @mysqli_init();
        if (!$conn) {
            return null;
        }

        @mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        $ok = @mysqli_real_connect(
            $conn,
            (string) $config['host'],
            (string) $config['user'],
            (string) $config['password'],
            (string) $config['name'],
            !empty($config['port']) ? (int) $config['port'] : 0
        );

        if (!$ok) {
            @mysqli_close($conn);
            return null;
        }

        @mysqli_set_charset($conn, 'utf8mb4');
        return $conn;
    } catch (Throwable $e) {
        return null;
    }
}

function admin_users_table_name()
{
    return 'castel_admin_users';
}

function admin_users_mysql_schema_sql()
{
    return 'CREATE TABLE IF NOT EXISTS `' . admin_users_table_name() . '` (
        `email` VARCHAR(255) NOT NULL PRIMARY KEY,
        `full_name` VARCHAR(255) NOT NULL DEFAULT \'\',
        `role` VARCHAR(40) NOT NULL DEFAULT \'profesor\',
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `password_hash` VARCHAR(255) NOT NULL DEFAULT \'\',
        `password_created_at` VARCHAR(40) NULL,
        `password_setup_token_hash` VARCHAR(255) NOT NULL DEFAULT \'\',
        `password_setup_token_created_at` VARCHAR(40) NULL,
        `password_setup_token_used_at` VARCHAR(40) NULL,
        `password_reset_token_hash` VARCHAR(255) NOT NULL DEFAULT \'\',
        `password_reset_token_created_at` VARCHAR(40) NULL,
        `password_reset_token_used_at` VARCHAR(40) NULL,
        `created_at` VARCHAR(40) NULL,
        `updated_at` VARCHAR(40) NULL,
        INDEX `idx_role` (`role`),
        INDEX `idx_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
}

function admin_users_mysql_ensure($conn)
{
    if (!$conn) {
        return false;
    }

    if (!mysqli_query($conn, admin_users_mysql_schema_sql())) {
        return false;
    }

    $columns = array(
        'password_reset_token_hash' => 'ALTER TABLE `' . admin_users_table_name() . '` ADD COLUMN `password_reset_token_hash` VARCHAR(255) NOT NULL DEFAULT \'\'',
        'password_reset_token_created_at' => 'ALTER TABLE `' . admin_users_table_name() . '` ADD COLUMN `password_reset_token_created_at` VARCHAR(40) NULL',
        'password_reset_token_used_at' => 'ALTER TABLE `' . admin_users_table_name() . '` ADD COLUMN `password_reset_token_used_at` VARCHAR(40) NULL',
        'created_at' => 'ALTER TABLE `' . admin_users_table_name() . '` ADD COLUMN `created_at` VARCHAR(40) NULL',
        'updated_at' => 'ALTER TABLE `' . admin_users_table_name() . '` ADD COLUMN `updated_at` VARCHAR(40) NULL',
    );
    foreach ($columns as $column => $sql) {
        $exists = mysqli_query($conn, 'SHOW COLUMNS FROM `' . admin_users_table_name() . '` LIKE \'' . mysqli_real_escape_string($conn, $column) . '\'');
        if ($exists && mysqli_num_rows($exists) > 0) {
            mysqli_free_result($exists);
            continue;
        }
        if ($exists) {
            mysqli_free_result($exists);
        }
        @mysqli_query($conn, $sql);
    }

    return true;
}

function admin_normalize_email($email)
{
    return strtolower(trim((string) $email));
}

function admin_normalize_role($role)
{
    $role = strtolower(trim((string) $role));
    return in_array($role, array('admin', 'directivo', 'coordinacion', 'profesor'), true) ? $role : 'profesor';
}

function admin_normalize_user_record($email, $value)
{
    $email = admin_normalize_email($email);
    $value = is_array($value) ? $value : array();
    return array(
        'email' => $email,
        'full_name' => isset($value['full_name']) ? (string) $value['full_name'] : '',
        'role' => admin_normalize_role(isset($value['role']) ? $value['role'] : 'profesor'),
        'is_active' => array_key_exists('is_active', $value) ? (bool) $value['is_active'] : true,
        'password_hash' => isset($value['password_hash']) ? (string) $value['password_hash'] : '',
        'password_created_at' => isset($value['password_created_at']) ? $value['password_created_at'] : null,
        'password_setup_token_hash' => isset($value['password_setup_token_hash']) ? (string) $value['password_setup_token_hash'] : '',
        'password_setup_token_created_at' => isset($value['password_setup_token_created_at']) ? $value['password_setup_token_created_at'] : null,
        'password_setup_token_used_at' => isset($value['password_setup_token_used_at']) ? $value['password_setup_token_used_at'] : null,
        'password_reset_token_hash' => isset($value['password_reset_token_hash']) ? (string) $value['password_reset_token_hash'] : '',
        'password_reset_token_created_at' => isset($value['password_reset_token_created_at']) ? $value['password_reset_token_created_at'] : null,
        'password_reset_token_used_at' => isset($value['password_reset_token_used_at']) ? $value['password_reset_token_used_at'] : null,
        'created_at' => isset($value['created_at']) ? $value['created_at'] : null,
        'updated_at' => isset($value['updated_at']) ? $value['updated_at'] : null,
    );
}

function admin_read_authorized_users_json()
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
            $users[$email] = admin_normalize_user_record($email, array());
            continue;
        }

        if (is_string($key) && is_array($value)) {
            $email = admin_normalize_email(isset($value['email']) ? $value['email'] : $key);
            $users[$email] = admin_normalize_user_record($email, $value);
        }
    }

    ksort($users);
    return $users;
}

function admin_users_mysql_read($conn)
{
    if (!$conn || !admin_users_mysql_ensure($conn)) {
        return null;
    }

    $result = mysqli_query($conn, 'SELECT * FROM `' . admin_users_table_name() . '` ORDER BY `email` ASC');
    if (!$result) {
        return null;
    }

    $users = array();
    while ($row = mysqli_fetch_assoc($result)) {
        $email = admin_normalize_email($row['email'] ?? '');
        if ($email === '') {
            continue;
        }
        $users[$email] = admin_normalize_user_record($email, $row);
    }
    mysqli_free_result($result);
    return $users;
}

function admin_users_mysql_save($conn, $users)
{
    if (!$conn || !admin_users_mysql_ensure($conn)) {
        return false;
    }

    if (!@mysqli_begin_transaction($conn)) {
        return false;
    }

    if (!mysqli_query($conn, 'DELETE FROM `' . admin_users_table_name() . '`')) {
        @mysqli_rollback($conn);
        return false;
    }

    $sql = 'INSERT INTO `' . admin_users_table_name() . '` (`email`, `full_name`, `role`, `is_active`, `password_hash`, `password_created_at`, `password_setup_token_hash`, `password_setup_token_created_at`, `password_setup_token_used_at`, `password_reset_token_hash`, `password_reset_token_created_at`, `password_reset_token_used_at`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        @mysqli_rollback($conn);
        return false;
    }

    foreach ($users as $email => $user) {
        $record = admin_normalize_user_record($email, $user);
        $recordEmail = $record['email'];
        $fullName = $record['full_name'];
        $role = $record['role'];
        $active = !empty($record['is_active']) ? 1 : 0;
        $passwordHash = $record['password_hash'];
        $passwordCreatedAt = $record['password_created_at'];
        $setupTokenHash = $record['password_setup_token_hash'];
        $setupTokenCreatedAt = $record['password_setup_token_created_at'];
        $setupTokenUsedAt = $record['password_setup_token_used_at'];
        $resetTokenHash = $record['password_reset_token_hash'];
        $resetTokenCreatedAt = $record['password_reset_token_created_at'];
        $resetTokenUsedAt = $record['password_reset_token_used_at'];
        $createdAt = $record['created_at'] ?: date('c');
        $updatedAt = date('c');
        mysqli_stmt_bind_param(
            $stmt,
            'sssissssssssss',
            $recordEmail,
            $fullName,
            $role,
            $active,
            $passwordHash,
            $passwordCreatedAt,
            $setupTokenHash,
            $setupTokenCreatedAt,
            $setupTokenUsedAt,
            $resetTokenHash,
            $resetTokenCreatedAt,
            $resetTokenUsedAt,
            $createdAt,
            $updatedAt
        );
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            @mysqli_rollback($conn);
            return false;
        }
    }

    mysqli_stmt_close($stmt);
    return @mysqli_commit($conn);
}

function admin_maybe_migrate_users_to_mysql($conn)
{
    $users = admin_users_mysql_read($conn);
    if (!is_array($users) || count($users) > 0) {
        return $users;
    }

    $jsonUsers = admin_read_authorized_users_json();
    if (!$jsonUsers) {
        return $users;
    }

    if (admin_users_mysql_save($conn, $jsonUsers)) {
        admin_log_security_event('users_migrated_to_mysql', '');
        return admin_users_mysql_read($conn);
    }

    return $users;
}

function admin_read_authorized_users()
{
    $conn = admin_db_connect();
    if ($conn) {
        $users = admin_maybe_migrate_users_to_mysql($conn);
        @mysqli_close($conn);
        if (is_array($users)) {
            ksort($users);
            return $users;
        }
    }

    return admin_read_authorized_users_json();
}

function admin_save_authorized_users($users)
{
    $payload = array();
    foreach ($users as $email => $user) {
        $normalized = admin_normalize_email($email);
        $payload[$normalized] = admin_normalize_user_record($normalized, $user);
    }

    ksort($payload);

    $mysql_ok = false;
    $conn = admin_db_connect();
    if ($conn) {
        $saved = admin_users_mysql_save($conn, $payload);
        @mysqli_close($conn);
        if ($saved) {
            $mysql_ok = true;
        }
    }

    // Doble persistencia garantizada: SIEMPRE guardar en el JSON local para asegurar sincronización total con SSO
    $jsonPath = admin_auth_file_path();
    $jsonData = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $json_ok = admin_atomic_file_write($jsonPath, $jsonData);

    return $mysql_ok || $json_ok;
}

function admin_atomic_file_write($filepath, $content)
{
    $dir = dirname($filepath);
    $tmp = $filepath . '.tmp.' . bin2hex(random_bytes(4));
    $written = @file_put_contents($tmp, $content);
    if ($written !== false) {
        if (@rename($tmp, $filepath)) {
            return true;
        }
        @unlink($tmp);
    }
    return (bool) @file_put_contents($filepath, $content);
}

function admin_find_user($email, $users)
{
    $email = admin_normalize_email($email);
    return isset($users[$email]) ? $users[$email] : null;
}

function admin_login_user($email)
{
    session_regenerate_id(true);
    $_SESSION['admin_login_started_at'] = time();
    $_SESSION['admin_last_seen_at'] = time();
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

function admin_user_can_manage_site($user)
{
    return in_array(admin_user_role($user), array('admin', 'directivo', 'coordinacion'), true);
}

function admin_require_site_admin()
{
    admin_require_login();
    if (!admin_user_can_manage_site(admin_current_user())) {
        header('Location: hub.php');
        exit;
    }
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

function admin_login_lock_key($email, $ip = null)
{
    $email = admin_normalize_email($email);
    $ip = $ip === null ? admin_client_ip() : (string) $ip;
    return hash('sha256', $email . '|' . $ip);
}

function admin_read_login_locks()
{
    $path = admin_login_locks_path();
    if (!is_file($path)) {
        return array();
    }
    $raw = file_get_contents($path);
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : array();
}

function admin_save_login_locks($locks)
{
    file_put_contents(
        admin_login_locks_path(),
        json_encode($locks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function admin_login_lock_state($email)
{
    $locks = admin_read_login_locks();
    $key = admin_login_lock_key($email);
    $now = time();
    $record = isset($locks[$key]) && is_array($locks[$key]) ? $locks[$key] : array();
    $until = (int) ($record['lock_until'] ?? 0);
    if ($until > $now) {
        return array('locked' => true, 'seconds_left' => $until - $now);
    }
    return array('locked' => false, 'seconds_left' => 0);
}

function admin_record_login_failure($email)
{
    $locks = admin_read_login_locks();
    $key = admin_login_lock_key($email);
    $now = time();
    $record = isset($locks[$key]) && is_array($locks[$key]) ? $locks[$key] : array();
    if (!empty($record['lock_until']) && (int) $record['lock_until'] > $now) {
        return $record;
    }

    $windowStarted = (int) ($record['window_started_at'] ?? 0);
    if ($windowStarted <= 0 || ($now - $windowStarted) > 15 * 60) {
        $record = array(
            'email_hash' => hash('sha256', admin_normalize_email($email)),
            'ip_hash' => hash('sha256', admin_client_ip()),
            'window_started_at' => $now,
            'fail_count' => 0,
            'lock_until' => 0,
        );
    }

    $record['fail_count'] = (int) ($record['fail_count'] ?? 0) + 1;
    $record['last_failed_at'] = $now;
    if ($record['fail_count'] >= 8) {
        $record['lock_until'] = $now + 15 * 60;
        $record['fail_count'] = 0;
        admin_log_security_event('login_lock', admin_normalize_email($email));
    }

    $locks[$key] = $record;
    admin_save_login_locks($locks);
    return $record;
}

function admin_clear_login_failures($email)
{
    $locks = admin_read_login_locks();
    $key = admin_login_lock_key($email);
    if (isset($locks[$key])) {
        unset($locks[$key]);
        admin_save_login_locks($locks);
    }
}

function admin_is_school_campus_ip($ip)
{
    // IP pública institucional del Colegio Castelgandolfo (Entel) y loopback local
    $schoolIps = array(
        '186.67.225.26', // Salida fibra óptica institucional
        '127.0.0.1',
        '::1',
    );
    return in_array($ip, $schoolIps, true);
}

function admin_code_rate_limit_state($email = '', $ip = null)
{
    $locks = admin_read_login_locks();
    $ip = $ip === null ? admin_client_ip() : (string) $ip;
    $email = admin_normalize_email($email);
    $now = time();

    // 1. Control por Correo: Máximo 3 solicitudes por casilla cada 15 min (evita inundar al docente)
    if ($email !== '') {
        $emailKey = 'email_code_req_' . hash('sha256', $email);
        $emailRec = isset($locks[$emailKey]) && is_array($locks[$emailKey]) ? $locks[$emailKey] : array();
        $emailUntil = (int) ($emailRec['lock_until'] ?? 0);
        if ($emailUntil > $now) {
            $mins = max(1, (int) ceil(($emailUntil - $now) / 60));
            return array(
                'locked' => true,
                'scope' => 'email',
                'seconds_left' => $emailUntil - $now,
                'minutes_left' => $mins,
                'message' => 'Se han enviado varios códigos a esta casilla recientemente. Por seguridad, espera ' . $mins . ' minutos antes de pedir otro código.',
            );
        }
    }

    // 2. Control por IP adaptativo (Campus-aware para no bloquear a los profesores en el colegio)
    $ipKey = 'ip_code_req_' . hash('sha256', $ip);
    $ipRec = isset($locks[$ipKey]) && is_array($locks[$ipKey]) ? $locks[$ipKey] : array();
    $ipUntil = (int) ($ipRec['lock_until'] ?? 0);
    if ($ipUntil > $now) {
        $mins = max(1, (int) ceil(($ipUntil - $now) / 60));
        return array(
            'locked' => true,
            'scope' => 'ip',
            'seconds_left' => $ipUntil - $now,
            'minutes_left' => $mins,
            'message' => 'Se han generado demasiadas solicitudes desde esta conexión. Por seguridad, espera ' . $mins . ' minutos antes de intentar nuevamente.',
        );
    }

    return array('locked' => false, 'scope' => 'none', 'seconds_left' => 0, 'minutes_left' => 0, 'message' => '');
}

function admin_record_code_request($email = '', $ip = null)
{
    $locks = admin_read_login_locks();
    $ip = $ip === null ? admin_client_ip() : (string) $ip;
    $email = admin_normalize_email($email);
    $now = time();

    // Registrar intento por correo
    if ($email !== '') {
        $emailKey = 'email_code_req_' . hash('sha256', $email);
        $emailRec = isset($locks[$emailKey]) && is_array($locks[$emailKey]) ? $locks[$emailKey] : array();
        $wStart = (int) ($emailRec['window_started_at'] ?? 0);
        if ($wStart <= 0 || ($now - $wStart) > 15 * 60) {
            $emailRec = array(
                'email_hash' => hash('sha256', $email),
                'window_started_at' => $now,
                'request_count' => 0,
                'lock_until' => 0,
            );
        }
        $emailRec['request_count'] = (int) ($emailRec['request_count'] ?? 0) + 1;
        $emailRec['last_request_at'] = $now;
        if ($emailRec['request_count'] >= 3) {
            $emailRec['lock_until'] = $now + 15 * 60;
            $emailRec['request_count'] = 0;
            admin_log_security_event('email_code_rate_limit', $email);
        }
        $locks[$emailKey] = $emailRec;
    }

    // Registrar intento por IP
    $ipKey = 'ip_code_req_' . hash('sha256', $ip);
    $ipRec = isset($locks[$ipKey]) && is_array($locks[$ipKey]) ? $locks[$ipKey] : array();
    $wStartIp = (int) ($ipRec['window_started_at'] ?? 0);
    if ($wStartIp <= 0 || ($now - $wStartIp) > 15 * 60) {
        $ipRec = array(
            'ip_hash' => hash('sha256', $ip),
            'window_started_at' => $now,
            'request_count' => 0,
            'lock_until' => 0,
        );
    }
    $ipRec['request_count'] = (int) ($ipRec['request_count'] ?? 0) + 1;
    $ipRec['last_request_at'] = $now;

    // Umbral adaptativo:
    // Red del colegio (186.67.225.26): hasta 50 peticiones/15min para cubrir a todo el cuerpo docente.
    // Redes externas (móvil 4G/5G, hogar): hasta 20 peticiones/15min (absorbe NAT móvil de operadores chilenos).
    $maxForIp = admin_is_school_campus_ip($ip) ? 50 : 20;
    if ($ipRec['request_count'] >= $maxForIp) {
        $ipRec['lock_until'] = $now + 15 * 60;
        $ipRec['request_count'] = 0;
        admin_log_security_event('ip_code_rate_limit', $email ?: 'rate_limited@ip');
    }
    $locks[$ipKey] = $ipRec;

    admin_save_login_locks($locks);
    return true;
}

// Aliases de retrocompatibilidad
function admin_ip_code_rate_limit_state($ip = null)
{
    return admin_code_rate_limit_state('', $ip);
}

function admin_record_ip_code_request($ip = null)
{
    return admin_record_code_request('', $ip);
}


function admin_generate_setup_token()
{
    $raw = strtoupper(bin2hex(random_bytes(6)));
    return substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
}

/**
 * Persiste el seguimiento en MySQL porque es la fuente de verdad del panel.
 */
function admin_mail_delivery_mysql_save($record)
{
    $conn = admin_db_connect();
    if (!$conn) {
        return false;
    }

    $table = admin_mail_delivery_mysql_table_name();
    $schema = 'CREATE TABLE IF NOT EXISTS `' . $table . '` ('
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . '`occurred_at` VARCHAR(40) NOT NULL,'
        . '`kind` VARCHAR(64) NOT NULL,'
        . '`reference` VARCHAR(64) NOT NULL,'
        . '`token_fingerprint` CHAR(64) NOT NULL,'
        . '`email` VARCHAR(255) NOT NULL,'
        . '`status` VARCHAR(32) NOT NULL,'
        . '`detail` TEXT NOT NULL,'
        . '`ip_hash` CHAR(64) NOT NULL,'
        . 'PRIMARY KEY (`id`),'
        . 'KEY `idx_ccg_mail_delivery_email_at` (`email`, `id`),'
        . 'KEY `idx_ccg_mail_delivery_reference` (`reference`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    if (!@mysqli_query($conn, $schema)) {
        @mysqli_close($conn);
        return false;
    }

    $sql = 'INSERT INTO `' . $table . '` (`occurred_at`, `kind`, `reference`, `token_fingerprint`, `email`, `status`, `detail`, `ip_hash`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = @mysqli_prepare($conn, $sql);
    if (!$stmt) {
        @mysqli_close($conn);
        return false;
    }

    $occurredAt = (string) $record['at'];
    $kind = (string) $record['kind'];
    $reference = (string) $record['reference'];
    $fingerprint = (string) $record['token_fingerprint'];
    $email = (string) $record['email'];
    $status = (string) $record['status'];
    $detail = (string) $record['detail'];
    $ipHash = (string) $record['ip_hash'];
    $bound = @mysqli_stmt_bind_param($stmt, 'ssssssss', $occurredAt, $kind, $reference, $fingerprint, $email, $status, $detail, $ipHash);
    $saved = $bound && @mysqli_stmt_execute($stmt);
    @mysqli_stmt_close($stmt);
    @mysqli_close($conn);
    return $saved;
}

/**
 * Devuelve el seguimiento SMTP reciente para administradores, sin exponer codigos ni secretos.
 */
function admin_recent_mail_delivery($limit = 25)
{
    $limit = max(1, min(100, (int) $limit));
    $conn = admin_db_connect();
    if (!$conn) {
        return array();
    }

    try {
        $table = admin_mail_delivery_mysql_table_name();
        $sql = 'SELECT `occurred_at`, `kind`, `reference`, `email`, `status`, `detail` FROM `'
            . $table . '` ORDER BY `id` DESC LIMIT ' . $limit;
        $result = @mysqli_query($conn, $sql);
        if (!$result) {
            @mysqli_close($conn);
            return array();
        }

        $rows = array();
        while ($row = @mysqli_fetch_assoc($result)) {
            $rows[] = array(
                'occurred_at' => (string) ($row['occurred_at'] ?? ''),
                'kind' => (string) ($row['kind'] ?? ''),
                'reference' => (string) ($row['reference'] ?? ''),
                'email' => admin_normalize_email($row['email'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'detail' => (string) ($row['detail'] ?? ''),
            );
        }
        @mysqli_free_result($result);
        @mysqli_close($conn);
        return $rows;
    } catch (Throwable $exception) {
        @mysqli_close($conn);
        return array();
    }
}

/**
 * Normaliza cualquier formato de código ingresado a su estructura canónica de 12 caracteres (XXXX-XXXX-XXXX).
 */
function admin_normalize_token_canonical($token)
{
    $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $token));
    if (strlen($clean) === 12) {
        return substr($clean, 0, 4) . '-' . substr($clean, 4, 4) . '-' . substr($clean, 8, 4);
    }
    return strtoupper(trim((string) $token));
}

/**
 * Verificación flexible de código de activación o recuperación.
 * Soporta códigos ingresados con o sin guiones, con espacios o en minúsculas,
 * evitando que los docentes sean rechazados por discrepancias de formato al copiar en celulares.
 */
function admin_verify_token_flexible($inputToken, $hash)
{
    if (empty($inputToken) || empty($hash)) {
        return false;
    }
    $raw = strtoupper(trim((string) $inputToken));
    if (password_verify($raw, (string) $hash)) {
        return true;
    }
    $canonical = admin_normalize_token_canonical($inputToken);
    if ($canonical !== $raw && password_verify($canonical, (string) $hash)) {
        return true;
    }
    $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $inputToken));
    if ($clean !== $raw && $clean !== $canonical && password_verify($clean, (string) $hash)) {
        return true;
    }
    return false;
}

/**
 * Registra cada intento de entrega sin guardar el codigo utilizable ni secretos SMTP.
 */
function admin_log_mail_delivery($kind, $user, $token, $status, $detail = '')
{
    $email = is_array($user) && !empty($user['email']) ? admin_normalize_email($user['email']) : '';
    $tokenNormalized = admin_normalize_token_canonical($token);
    $detail = preg_replace('/[\r\n\t]+/', ' ', (string) $detail);
    $detail = trim((string) $detail);
    if (function_exists('mb_substr')) {
        $detail = mb_substr($detail, 0, 500);
    } else {
        $detail = substr($detail, 0, 500);
    }

    $reference = substr(hash('sha256', (string) $kind . '|' . $email . '|' . $tokenNormalized), 0, 16);
    $record = array(
        'at' => date('c'),
        'kind' => (string) $kind,
        'reference' => $reference,
        'token_fingerprint' => hash('sha256', $tokenNormalized),
        'email' => $email,
        'status' => (string) $status,
        'detail' => $detail,
        'ip_hash' => hash('sha256', admin_client_ip()),
    );

    if (!admin_mail_delivery_mysql_save($record)) {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents(admin_mail_delivery_log_path(), $line . PHP_EOL, FILE_APPEND);
    }
    return $reference;
}

function admin_setup_token_is_valid($user, $token)
{
    if (!is_array($user) || empty($user['password_setup_token_hash'])) {
        return false;
    }
    if (!empty($user['password_setup_token_used_at'])) {
        return false;
    }
    $created = strtotime((string) ($user['password_setup_token_created_at'] ?? ''));
    if ($created && (time() - $created) > 14 * 24 * 60 * 60) {
        return false;
    }
    return admin_verify_token_flexible($token, $user['password_setup_token_hash']);
}

function admin_password_reset_token_is_valid($user, $token)
{
    if (!is_array($user) || empty($user['password_reset_token_hash'])) {
        return false;
    }
    if (!empty($user['password_reset_token_used_at'])) {
        return false;
    }
    $created = strtotime((string) ($user['password_reset_token_created_at'] ?? ''));
    if (!$created || (time() - $created) > 60 * 60) {
        return false;
    }
    return admin_verify_token_flexible($token, $user['password_reset_token_hash']);
}

function admin_send_setup_email($user, $token, &$error = null)
{
    if (!is_array($user) || empty($user['email'])) {
        $error = 'Usuario inválido.';
        return false;
    }

    require_once __DIR__ . '/mailer.php';

    $email = admin_normalize_email($user['email']);
    $name = admin_user_display_name($user);
    $subject = 'Código de activación - Suite Digital Docente | Colegio Castelgandolfo';
    $tokenFormatted = admin_normalize_token_canonical($token);

    $body = "Hola " . ($name !== '' ? $name : $email) . ",\n\n"
        . "Se ha habilitado tu acceso a la Suite Digital Docente del Colegio Castelgandolfo.\n\n"
        . "Tu clave de acceso es única y unificada para todo el ecosistema:\n"
        . "• CastelBoard (Portafolio, entregas y asistencia)\n"
        . "• EduDocente Studio IA (Pruebas, pautas Word y nóminas)\n"
        . "• Calendario de Salas (Reserva de computación)\n"
        . "• Documentos y herramientas UTP\n\n"
        . "Tu código de activación es: " . $tokenFormatted . "\n\n"
        . "Ingresa en https://www.colegiocastelgandolfo.cl/admin/ con tu correo institucional registrado, crea tu contraseña de al menos 10 caracteres e ingresa este código.\n\n"
        . "Este código vence en 14 días. (No corresponde a Webmail, Sofia ni Gmail).";

    $html = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;color:#1e293b;">'
        . '<div style="background:linear-gradient(135deg,#0f264f,#1b8252);padding:26px 28px;text-align:center;">'
        . '<h1 style="color:#ffffff;font-size:20px;margin:0;font-weight:800;letter-spacing:-0.01em;">Colegio Castelgandolfo</h1>'
        . '<p style="color:#a7f3d0;font-size:13px;margin:6px 0 0;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;">Suite Digital Docente · Activación de Cuenta</p>'
        . '</div>'
        . '<div style="padding:28px 28px 24px;">'
        . '<p style="font-size:15px;line-height:1.5;margin-top:0;">Hola <strong>' . htmlspecialchars($name !== '' ? $name : $email, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        . '<p style="font-size:14px;line-height:1.5;color:#475569;">Se ha habilitado tu acceso unificado a las plataformas docentes institucionales (<strong>CastelBoard, EduDocente IA, Calendario de Salas y herramientas UTP</strong>).</p>'
        . '<div style="background:#f8fafc;border:2px dashed #cbd5e1;border-radius:12px;padding:18px;text-align:center;margin:22px 0;">'
        . '<span style="display:block;font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;font-weight:700;margin-bottom:6px;">Tu código de activación</span>'
        . '<span style="font-size:26px;font-weight:800;letter-spacing:0.12em;color:#0f264f;font-family:monospace;">' . htmlspecialchars($tokenFormatted, ENT_QUOTES, 'UTF-8') . '</span>'
        . '</div>'
        . '<p style="font-size:13px;line-height:1.5;color:#475569;">Para completar la activación, ingresa a <a href="https://www.colegiocastelgandolfo.cl/admin/" style="color:#1b8252;font-weight:700;text-decoration:none;">www.colegiocastelgandolfo.cl/admin/</a> con tu correo, define una contraseña segura (mínimo 10 caracteres) e ingresa el código anterior.</p>'
        . '<div style="background:#f1f5f9;border-radius:8px;padding:12px 14px;font-size:12px;color:#64748b;margin-top:20px;">'
        . '⏳ El código tiene una vigencia de 14 días. Esta contraseña será válida de forma transversal en todo el ecosistema digital.'
        . '</div>'
        . '</div>'
        . '<div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:14px 28px;font-size:11px;color:#94a3b8;text-align:center;">'
        . 'Departamento de Informática y Tecnología Educativa · Colegio Castelgandolfo'
        . '</div>'
        . '</div>';

    $sent = castel_mailer_send($email, $subject, $body, $error, $html);
    admin_log_mail_delivery('setup_activation', $user, $tokenFormatted, $sent ? 'accepted' : 'failed', $sent ? 'SMTP aceptó el mensaje.' : $error);
    return $sent;
}

function admin_send_password_reset_email($user, $token, &$error = null)
{
    if (!is_array($user) || empty($user['email'])) {
        $error = 'Usuario inválido.';
        return false;
    }

    require_once __DIR__ . '/mailer.php';

    $email = admin_normalize_email($user['email']);
    $name = admin_user_display_name($user);
    $subject = 'Código para recuperar tu contraseña - Suite Digital Docente | Colegio Castelgandolfo';
    $tokenFormatted = admin_normalize_token_canonical($token);

    $body = "Hola " . ($name !== '' ? $name : $email) . ",\n\n"
        . "Recibimos una solicitud para restablecer tu contraseña unificada de la Suite Digital Docente (CastelBoard, EduDocente IA, Calendario de Salas y herramientas UTP).\n\n"
        . "Tu código de recuperación es: " . $tokenFormatted . "\n\n"
        . "Este código vence en 60 minutos. Ingresa en https://www.colegiocastelgandolfo.cl/admin/ con tu correo e ingresa este código para definir tu nueva contraseña.\n\n"
        . "Esta nueva contraseña actualizará tu acceso global en todo el ecosistema escolar.\n\n"
        . "Si no solicitaste este cambio, puedes ignorar este mensaje; tu cuenta sigue protegida.";

    $html = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;color:#1e293b;">'
        . '<div style="background:linear-gradient(135deg,#0f264f,#1b8252);padding:26px 28px;text-align:center;">'
        . '<h1 style="color:#ffffff;font-size:20px;margin:0;font-weight:800;letter-spacing:-0.01em;">Colegio Castelgandolfo</h1>'
        . '<p style="color:#a7f3d0;font-size:13px;margin:6px 0 0;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;">Suite Digital Docente · Recuperación de Contraseña</p>'
        . '</div>'
        . '<div style="padding:28px 28px 24px;">'
        . '<p style="font-size:15px;line-height:1.5;margin-top:0;">Hola <strong>' . htmlspecialchars($name !== '' ? $name : $email, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        . '<p style="font-size:14px;line-height:1.5;color:#475569;">Recibimos una solicitud para restablecer tu contraseña de la Suite Digital Docente (<strong>CastelBoard, EduDocente IA, Calendario de Salas y herramientas UTP</strong>).</p>'
        . '<div style="background:#f8fafc;border:2px dashed #cbd5e1;border-radius:12px;padding:18px;text-align:center;margin:22px 0;">'
        . '<span style="display:block;font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;font-weight:700;margin-bottom:6px;">Tu código de recuperación</span>'
        . '<span style="font-size:26px;font-weight:800;letter-spacing:0.12em;color:#0f264f;font-family:monospace;">' . htmlspecialchars($tokenFormatted, ENT_QUOTES, 'UTF-8') . '</span>'
        . '</div>'
        . '<p style="font-size:13px;line-height:1.5;color:#475569;">Ingresa a <a href="https://www.colegiocastelgandolfo.cl/admin/" style="color:#1b8252;font-weight:700;text-decoration:none;">www.colegiocastelgandolfo.cl/admin/</a> para ingresar este código y definir tu nueva contraseña.</p>'
        . '<div style="background:#fef2f2;border-left:4px solid #ef4444;border-radius:4px;padding:12px 14px;font-size:12px;color:#991b1b;margin-top:20px;">'
        . '⚠️ <strong>Importante:</strong> Este código expira en <strong>60 minutos</strong> y actualiza tu clave para todas las plataformas unificadas del colegio.'
        . '</div>'
        . '<p style="font-size:12px;color:#94a3b8;margin-top:16px;">Si tú no realizaste esta solicitud, desestima este correo; tu clave actual no ha sido modificada.</p>'
        . '</div>'
        . '<div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:14px 28px;font-size:11px;color:#94a3b8;text-align:center;">'
        . 'Departamento de Informática y Tecnología Educativa · Colegio Castelgandolfo'
        . '</div>'
        . '</div>';

    $sent = castel_mailer_send($email, $subject, $body, $error, $html);
    admin_log_mail_delivery('password_reset', $user, $tokenFormatted, $sent ? 'accepted' : 'failed', $sent ? 'SMTP aceptó el mensaje.' : $error);
    return $sent;
}

function admin_log_security_event($event, $email = '', $extra = array())
{
    $context = is_array($extra) ? $extra : array();
    $targetEmail = is_string($email) ? admin_normalize_email($email) : '';
    if (is_array($email)) {
        $context = array_merge($email, $context);
        $targetEmail = isset($context['target']) ? admin_normalize_email($context['target']) : '';
    }

    $payload = array(
        'at' => date('c'),
        'event' => (string) $event,
        'email' => $targetEmail,
        'ip_hash' => hash('sha256', admin_client_ip()),
    );
    if (!empty($context)) {
        $payload['context'] = $context;
    }

    $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents(admin_security_log_path(), $line . PHP_EOL, FILE_APPEND);
}

/**
 * Registra eventos de alto nivel de cualquier módulo del ecosistema (Calendario, Portafolio, Evaluaciones, etc.)
 */
function admin_log_system_event($module, $event, $status = 'ok', $detail = '', $context = array())
{
    admin_log_operation($module, $event, $status, $context, $detail);
}

