<?php
/**
 * Salto Seguro Single Sign-On (SSO) hacia CastelBoard y Ecosistema Digital CCG
 * Colegio Castelgandolfo - Departamento de Tecnologías de la Información
 * 
 * Permite a cualquier docente o administrador autenticado en /admin/
 * ingresar de forma transparente e instantánea a CastelBoard
 * sin solicitar credenciales nuevamente ni preguntar si es estudiante.
 */

require_once __DIR__ . '/auth.php';

admin_bootstrap_session();
admin_require_login();

$current_user = admin_current_user();
if (!$current_user) {
    header('Location: /admin/index.php');
    exit;
}

$email = strtolower(trim(isset($current_user['email']) ? $current_user['email'] : ''));
if (empty($email)) {
    header('Location: /admin/index.php');
    exit;
}

$role_raw = admin_user_role($current_user);
$cb_role = in_array($role_raw, array('admin', 'directivo', 'soporte', 'ti', 'administrador'), true) ? 'admin' : 'docente';
$name = trim(admin_user_display_name($current_user));
$ts = time();
$nonce = bin2hex(random_bytes(16));
$target = trim((string) ($_GET['target'] ?? 'castelboard'));
$is_lan = !empty($_GET['lan']) || $target === 'castelboard_lan';
$audience = $target === 'edudocente' ? 'edudocente' : 'castelboard';
if (!in_array($target, ['castelboard', 'castelboard_lan', 'edudocente'], true)) {
    http_response_code(400);
    exit('Destino SSO no reconocido.');
}
$sso_key = admin_sso_secret($audience);
if ($sso_key === '') {
    http_response_code(503);
    exit('SSO institucional no configurado.');
}

// Longitudes en bytes evitan ambigüedades entre los campos firmados.
$fields = [$email, $cb_role, $name, (string) $ts, $nonce, $audience];
$message = '';
foreach ($fields as $field) {
    $message .= strlen($field) . ':' . $field;
}
$sig = hash_hmac('sha256', $message, $sso_key);

$payload = array(
    'email' => $email,
    'role' => $cb_role,
    'name' => $name,
    'ts' => $ts,
    'nonce' => $nonce,
    'aud' => $audience,
    'ver' => 2,
    'sig' => $sig
);

$json = json_encode($payload, JSON_UNESCAPED_UNICODE);
$b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

if ($audience === 'edudocente') {
    $base_url = $is_lan ? 'http://estudio.castelgandolfo' : 'https://ccg-fisico.tail0e08b5.ts.net:8443';
} elseif ($is_lan) {
    $base_url = 'http://castelboard.castelgandolfo';
} else {
    $base_url = 'https://ccg-fisico.tail0e08b5.ts.net';
}

$destination = $base_url . '/sso?token=' . urlencode($b64);

if (function_exists('admin_log_operation')) {
    admin_log_operation('sso_jump', 'redirect_castelboard', 'ok', array(
        'email' => $email,
        'role' => $cb_role,
        'target' => $target,
        'is_lan' => $is_lan
    ), 'Redirección transparente SSO a CastelBoard');
}

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('Location: ' . $destination, true, 302);
exit;
