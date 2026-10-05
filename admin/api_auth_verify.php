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

$request_method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($request_method !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}
$content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($content_length < 0 || $content_length > 4096) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'Solicitud demasiado grande']);
    exit;
}
$token_header = isset($_SERVER['HTTP_X_CASTEL_AUTH_KEY']) ? $_SERVER['HTTP_X_CASTEL_AUTH_KEY'] : '';
$expected_token = admin_sso_secret('directory_sync');

if ($expected_token === '' || !hash_equals($expected_token, (string) $token_header)) {
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

$action = isset($data['action']) ? $data['action'] : '';
if ($action !== 'sync') {
    http_response_code(410);
    echo json_encode(['success' => false, 'error' => 'La verificación de contraseñas por API fue retirada']);
    exit;
}

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
            'is_active' => !empty($u['is_active'])
        ];
    }
    if (function_exists('admin_log_operation')) {
        admin_log_operation('sso_bridge', 'sync_users', 'ok', array('count' => count($export)), 'Sincronización masiva de nómina docente hacia CastelBoard/EduDocente');
    }
    echo json_encode(['success' => true, 'total' => count($export), 'users' => $export], JSON_UNESCAPED_UNICODE);
    exit;
}
