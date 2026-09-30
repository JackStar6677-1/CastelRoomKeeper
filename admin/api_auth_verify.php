<?php
/**
 * CastelRoomKeeper - API REST de Autenticación Centralizada y Sincronización SSO
 * Colegio Castelgandolfo - Ing. Pablo Elías Avendaño Miranda
 *
 * Permite que CastelBoard (Portafolio Escolar en Star Server) y otros sistemas
 * institucionales autentiquen docentes y administradores de forma centralizada.
 */

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Clave secreta canónica para comunicación inter-servidores (Star <-> Castelgandolfo)
$expected_key = 'castel-soberano-sso-key-2026-star';

// Permitir configuración externa si existe
$sso_cfg_file = __DIR__ . '/sso_config.php';
if (is_file($sso_cfg_file)) {
    $cfg = @include $sso_cfg_file;
    if (is_array($cfg) && !empty($cfg['auth_key'])) {
        $expected_key = (string) $cfg['auth_key'];
    }
}

// 1. Validar autorización de API
$received_key = isset($_SERVER['HTTP_X_CASTEL_AUTH_KEY']) ? trim((string)$_SERVER['HTTP_X_CASTEL_AUTH_KEY']) : '';

if (!$received_key || !hash_equals($expected_key, $received_key)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Acceso denegado: Clave de autenticación inter-servidores inválida o no proporcionada.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Leer payload JSON
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Payload JSON inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($data['action']) ? (string)$data['action'] : 'verify';
$authorized_users = admin_read_authorized_users();

// ACCIÓN A: VERIFICAR CREDENCIALES (Single Sign-On en tiempo real)
if ($action === 'verify') {
    $email = isset($data['email']) ? admin_normalize_email((string)$data['email']) : '';
    $password = isset($data['password']) ? (string)$data['password'] : '';

    if (!$email || $password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email y contraseña requeridos.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($authorized_users[$email])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Usuario no encontrado en la nómina institucional.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $user = $authorized_users[$email];

    // Verificar si la cuenta está activa
    if (isset($user['is_active']) && !$user['is_active']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Esta cuenta docente se encuentra desactivada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stored_hash = $user['password_hash'] ?? '';
    if (empty($stored_hash)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'El usuario no tiene una contraseña establecida. Debe usar su código de activación.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Verificar hash bcrypt canónico
    if (!password_verify($password, $stored_hash)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Contraseña incorrecta.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Credenciales correctas
    echo json_encode([
        'success' => true,
        'email' => $email,
        'full_name' => $user['full_name'] ?? '',
        'role' => $user['role'] ?? 'profesor',
        'is_active' => true,
        'password_hash' => $stored_hash,
        'server' => 'CastelRoomKeeper SSO'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ACCIÓN B: SINCRONIZACIÓN MASIVA DE NÓMINA (Sync hacia CastelBoard)
if ($action === 'sync') {
    $user_list = [];
    foreach ($authorized_users as $em => $u) {
        $user_list[] = [
            'email' => $em,
            'full_name' => $u['full_name'] ?? '',
            'role' => $u['role'] ?? 'profesor',
            'is_active' => !empty($u['is_active']),
            'password_hash' => $u['password_hash'] ?? ''
        ];
    }

    echo json_encode([
        'success' => true,
        'total' => count($user_list),
        'users' => $user_list,
        'timestamp' => date('c'),
        'server' => 'CastelRoomKeeper SSO'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ACCIÓN C: ESTADÍSTICAS Y SALUD DEL ENLACE
if ($action === 'stats') {
    $total = count($authorized_users);
    $active = 0;
    $admins = 0;
    foreach ($authorized_users as $u) {
        if (!empty($u['is_active'])) $active++;
        if (($u['role'] ?? '') === 'admin') $admins++;
    }

    echo json_encode([
        'success' => true,
        'status' => 'online',
        'suite' => 'Castel Suite Institucional',
        'total_docentes' => $total,
        'activos' => $active,
        'administradores' => $admins,
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => "Acción '$action' no reconocida."], JSON_UNESCAPED_UNICODE);
