<?php
/**
 * CastelRoomKeeper <-> CastelBoard SSO Bridge
 * Colegio Castelgandolfo - Departamento de Tecnologías de la Información
 * Ing. Pablo Elías Avendaño Miranda
 * 
 * Permite a CastelBoard (en Star Server o local) unificar la autenticación
 * docente con la misma contraseña y cuentas de CastelRoomKeeper.
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/auth.php';

$token_header = isset($_SERVER['HTTP_X_CASTEL_AUTH_KEY']) ? $_SERVER['HTTP_X_CASTEL_AUTH_KEY'] : '';
$expected_token = 'castel-soberano-sso-key-2026-star';

if ($token_header !== $expected_token) {
    if (function_exists('admin_log_operation')) {
        admin_log_operation('sso_bridge', 'unauthorized_token', 'failed', array('ip' => admin_client_ip()), 'Intento de acceso al puente SSO con token inválido');
    }
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acceso denegado: token SSO no válido']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = [];
}

$action = isset($data['action']) ? $data['action'] : 'verify';

$users = function_exists('admin_read_authorized_users') ? admin_read_authorized_users() : null;
if (!is_array($users) || empty($users)) {
    $auth_file = __DIR__ . '/../data/authorized_emails.json';
    if (file_exists($auth_file)) {
        $users = json_decode(file_get_contents($auth_file), true);
    }
}

if (!is_array($users)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Base autorizada no disponible']);
    exit;
}

// ACCIÓN 1: Sincronización masiva de docentes hacia CastelBoard
if ($action === 'sync') {
    $export = [];
    foreach ($users as $email => $u) {
        $export[] = [
            'email' => strtolower(trim($email)),
            'full_name' => isset($u['full_name']) ? trim($u['full_name']) : '',
            'role' => isset($u['role']) ? trim($u['role']) : 'profesor',
            'is_active' => !empty($u['is_active']),
            'password_hash' => isset($u['password_hash']) ? (string)$u['password_hash'] : '',
            'password_created_at' => isset($u['password_created_at']) ? $u['password_created_at'] : null
        ];
    }
    if (function_exists('admin_log_operation')) {
        admin_log_operation('sso_bridge', 'sync_users', 'ok', array('count' => count($export)), 'Sincronización masiva de nómina docente hacia CastelBoard/EduDocente');
    }
    echo json_encode(['success' => true, 'total' => count($export), 'users' => $export], JSON_UNESCAPED_UNICODE);
    exit;
}

// ACCIÓN 2: Verificación de credenciales en tiempo real
$email = strtolower(trim(isset($data['email']) ? $data['email'] : ''));
$password = isset($data['password']) ? (string)$data['password'] : '';

if (!$email || !$password) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Faltan parámetros email o password']);
    exit;
}

if (!isset($users[$email])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Docente no registrado en la nómina oficial']);
    exit;
}

$user = $users[$email];
if (isset($user['is_active']) && !$user['is_active']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Cuenta docente inactiva en el sistema']);
    exit;
}

if (empty($user['password_hash'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'code' => 'PASSWORD_NOT_CONFIGURED',
        'error' => 'Aún no has configurado tu contraseña en el sistema. Puedes activarla ingresando tu correo en el panel oficial en /admin/.'
    ]);
    exit;
}

if (password_verify($password, $user['password_hash'])) {
    if (function_exists('admin_log_operation')) {
        admin_log_operation('sso_bridge', 'verify_success', 'ok', array('email' => $email, 'role' => $user['role'] ?? 'profesor'), 'Autenticación exitosa vía SSO Bridge (CastelBoard/EduDocente)');
    }
    echo json_encode([
        'success' => true,
        'email' => $email,
        'full_name' => isset($user['full_name']) ? $user['full_name'] : '',
        'role' => isset($user['role']) ? $user['role'] : 'profesor',
        'password_hash' => $user['password_hash']
    ], JSON_UNESCAPED_UNICODE);
    exit;
} else {
    if (function_exists('admin_log_operation')) {
        admin_log_operation('sso_bridge', 'verify_failed', 'failed', array('email' => $email), 'Fallo de autenticación por contraseña incorrecta vía SSO Bridge');
    }
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Contraseña incorrecta']);
    exit;
}
