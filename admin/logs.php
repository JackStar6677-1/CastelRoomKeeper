<?php
require_once __DIR__ . '/auth.php';

admin_bootstrap_session();
admin_require_login();

$current_user = admin_current_user();
$current_email = $current_user ? $current_user['email'] : '';
$current_role = $current_user ? admin_user_role($current_user) : 'profesor';
$can_manage_site = admin_user_can_manage_site($current_user);
$can_manage_users = in_array($current_role, array('admin', 'directivo'), true);
$can_view_logs = in_array($current_role, array('admin', 'directivo', 'coordinacion'), true);

if (!$can_view_logs) {
    header('Location: hub.php');
    exit;
}

// Parámetros de filtrado
$filter_system = isset($_GET['system']) ? trim((string)$_GET['system']) : 'all';
$filter_status = isset($_GET['status']) ? trim((string)$_GET['status']) : 'all';
$search_query = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

// 1. Obtener Logs de Operaciones (MySQL o fallback JSON)
$operation_logs = array();
$conn = admin_db_connect();
if ($conn) {
    $table = admin_operation_log_mysql_table_name();
    $sql = "SELECT `occurred_at`, `area`, `event_name`, `status`, `detail`, `context_json` FROM `{$table}` ORDER BY `id` DESC LIMIT 150";
    $res = @mysqli_query($conn, $sql);
    if ($res) {
        while ($row = @mysqli_fetch_assoc($res)) {
            $operation_logs[] = array(
                'at' => $row['occurred_at'],
                'system' => $row['area'],
                'event' => $row['event_name'],
                'status' => $row['status'],
                'detail' => $row['detail'],
                'context' => $row['context_json']
            );
        }
        @mysqli_free_result($res);
    }
    @mysqli_close($conn);
}

if (empty($operation_logs)) {
    $op_file = __DIR__ . '/../data/admin_operation.log';
    if (is_file($op_file)) {
        $lines = array_filter(array_map('trim', file($op_file)));
        $lines = array_reverse(array_slice($lines, -150));
        foreach ($lines as $line) {
            $dec = json_decode($line, true);
            if (is_array($dec)) {
                $operation_logs[] = array(
                    'at' => $dec['at'] ?? '',
                    'system' => $dec['area'] ?? 'general',
                    'event' => $dec['event'] ?? '',
                    'status' => $dec['status'] ?? 'info',
                    'detail' => $dec['detail'] ?? '',
                    'context' => $dec['context_json'] ?? ''
                );
            }
        }
    }
}

// 2. Obtener Logs de Seguridad y Accesos
$security_logs = array();
$sec_file = admin_security_log_path();
if (is_file($sec_file)) {
    $lines = array_filter(array_map('trim', file($sec_file)));
    $lines = array_reverse(array_slice($lines, -150));
    foreach ($lines as $line) {
        $dec = json_decode($line, true);
        if (is_array($dec)) {
            $ctx = isset($dec['context']) && is_array($dec['context']) ? json_encode($dec['context'], JSON_UNESCAPED_UNICODE) : '';
            $security_logs[] = array(
                'at' => $dec['at'] ?? '',
                'system' => 'seguridad_auth',
                'event' => $dec['event'] ?? 'auth_event',
                'status' => (strpos($dec['event'] ?? '', 'fail') !== false || strpos($dec['event'] ?? '', 'lock') !== false) ? 'failed' : 'ok',
                'detail' => 'Usuario: ' . ($dec['email'] ?? 'desconocido'),
                'context' => $ctx
            );
        }
    }
}

// 3. Obtener Logs de Entrega SMTP
$mail_logs = array();
$raw_mail = admin_recent_mail_delivery(100);
if (is_array($raw_mail)) {
    foreach ($raw_mail as $m) {
        $mail_logs[] = array(
            'at' => $m['occurred_at'] ?? '',
            'system' => 'correo_smtp',
            'event' => $m['kind'] ?? 'notificacion',
            'status' => ($m['status'] ?? '') === 'accepted' ? 'ok' : 'failed',
            'detail' => 'A: ' . ($m['email'] ?? '') . ' | ' . ($m['detail'] ?? ''),
            'context' => 'Ref: ' . ($m['reference'] ?? '')
        );
    }
}

// Fusionar todos los logs y ordenar por fecha descendente
$all_logs = array_merge($operation_logs, $security_logs, $mail_logs);
usort($all_logs, function ($a, $b) {
    return strcmp($b['at'], $a['at']);
});

// Filtrado
$filtered_logs = array_filter($all_logs, function ($item) use ($filter_system, $filter_status, $search_query) {
    if ($filter_system !== 'all') {
        if ($filter_system === 'calendario' && strpos($item['system'], 'calendar') === false) return false;
        if ($filter_system === 'sso' && strpos($item['system'], 'sso') === false) return false;
        if ($filter_system === 'seguridad' && $item['system'] !== 'seguridad_auth') return false;
        if ($filter_system === 'correo' && $item['system'] !== 'correo_smtp') return false;
    }
    if ($filter_status !== 'all') {
        if ($filter_status === 'ok' && $item['status'] !== 'ok') return false;
        if ($filter_status === 'failed' && $item['status'] !== 'failed') return false;
    }
    if ($search_query !== '') {
        $haystack = strtolower($item['system'] . ' ' . $item['event'] . ' ' . $item['detail'] . ' ' . $item['context']);
        if (strpos($haystack, strtolower($search_query)) === false) return false;
    }
    return true;
});
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Bitácora y Logs del Sistema | CCG Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Outfit', system-ui, sans-serif; margin: 0; display: flex; min-height: 100vh; min-height: 100dvh; background: linear-gradient(180deg, #070b10 0%, #0c1520 100%); color: #e8f4ff; }
        .sidebar { width: min(280px, 100%); background: #0a1a2e; color: #fff; padding: 22px 18px; flex-shrink: 0; border-right: 1px solid rgba(123, 196, 255, 0.12); }
        .sidebar h2 { margin: 0 0 8px; font-size: 1.15rem; }
        .sidebar-user { font-size: 0.78rem; opacity: 0.75; margin: 0 0 16px; word-break: break-all; }
        .sidebar-divider { border: 0; border-top: 1px solid rgba(255,255,255,0.15); margin: 16px 0; }
        .nav-link { display: block; color: #fff; text-decoration: none; padding: 10px 12px; border-radius: 8px; margin-bottom: 4px; font-weight: 600; font-size: 0.92rem; }
        .nav-link:hover { background: rgba(255,255,255,0.08); }
        .nav-link.is-active { background: rgba(27, 130, 82, 0.35); }
        .nav-link--muted { margin-top: 28px; opacity: 0.55; font-weight: 500; }
        .nav-form { margin-top: 18px; }
        .nav-form button { width: 100%; border: 0; cursor: pointer; text-align: left; font: inherit; }
        .btn-success { background: #0f9d58; color: #fff; }
        .main { flex: 1; padding: clamp(20px, 4vw, 32px) clamp(16px, 3vw, 40px); max-width: min(85rem, 100%); width: 100%; }
        
        .header-section { margin-bottom: 24px; }
        .header-section h1 { margin: 0 0 6px; font-size: clamp(1.6rem, 3vw, 2.2rem); font-weight: 800; letter-spacing: -0.02em; }
        .header-section p { margin: 0; color: rgba(210, 228, 248, 0.85); font-size: 0.96rem; line-height: 1.5; }

        /* Tarjetas de Estadísticas Rápidas */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .stat-card { background: rgba(18, 28, 44, 0.7); border: 1px solid rgba(123, 196, 255, 0.16); border-radius: 14px; padding: 16px 18px; }
        .stat-card__val { font-size: 1.7rem; font-weight: 800; color: #fff; margin-bottom: 2px; }
        .stat-card__label { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; color: rgba(210, 228, 248, 0.7); font-weight: 700; }

        /* Filtros */
        .filters-panel { background: rgba(18, 28, 44, 0.85); border: 1px solid rgba(123, 196, 255, 0.18); border-radius: 16px; padding: 18px 20px; margin-bottom: 24px; display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 6px; min-width: 160px; }
        .filter-group label { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(210, 228, 248, 0.75); }
        .filter-group select, .filter-group input { padding: 9px 12px; border-radius: 10px; border: 1px solid rgba(123, 196, 255, 0.22); background: rgba(8, 16, 28, 0.85); color: #fff; font: inherit; font-size: 0.9rem; }
        .btn-filter { padding: 10px 18px; border-radius: 10px; border: 0; background: #1f63bb; color: #fff; font-weight: 700; cursor: pointer; height: 40px; }
        .btn-filter:hover { background: #2a7adb; }

        /* Tabla de Logs */
        .table-wrap { background: rgba(15, 24, 38, 0.9); border: 1px solid rgba(123, 196, 255, 0.16); border-radius: 16px; overflow-x: auto; box-shadow: 0 16px 40px rgba(0,0,0,0.35); }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem; }
        th { background: rgba(25, 40, 62, 0.8); color: #fff; padding: 14px 16px; font-weight: 700; text-transform: uppercase; font-size: 0.74rem; letter-spacing: 0.08em; border-bottom: 1px solid rgba(123, 196, 255, 0.18); }
        td { padding: 12px 16px; border-bottom: 1px solid rgba(123, 196, 255, 0.08); vertical-align: top; color: rgba(230, 240, 255, 0.9); }
        tr:hover td { background: rgba(255, 255, 255, 0.03); }
        
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 999px; font-size: 0.74rem; font-weight: 800; text-transform: uppercase; }
        .badge--ok { background: rgba(15, 157, 88, 0.2); color: #6ee7b7; border: 1px solid rgba(15, 157, 88, 0.4); }
        .badge--failed { background: rgba(220, 38, 38, 0.2); color: #fca5a5; border: 1px solid rgba(220, 38, 38, 0.4); }
        .badge--info { background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.4); }

        .sys-tag { display: inline-block; padding: 2px 7px; border-radius: 6px; background: rgba(123, 196, 255, 0.12); color: #7bc4ff; font-weight: 700; font-size: 0.76rem; }
        .time-str { white-space: nowrap; font-size: 0.8rem; color: rgba(210, 228, 248, 0.65); font-family: monospace; }
        .context-code { font-size: 0.76rem; color: rgba(210, 228, 248, 0.6); max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; font-family: monospace; margin-top: 4px; }
    </style>
    <link rel="stylesheet" href="admin-responsive.css">
</head>
<body>
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <div class="main">
        <div class="header-section">
            <span style="font-size:0.78rem;text-transform:uppercase;letter-spacing:0.12em;color:#7bc4ff;font-weight:800;">Observabilidad y Auditoría SRE</span>
            <h1>Bitácora y Logs del Sistema</h1>
            <p>Registro cronológico e inmutable de eventos en vivo: reservas del Calendario, accesos y sesiones de profesores, autenticación SSO con CastelBoard y EduDocente, y despacho de correos SMTP.</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-card__val"><?= count($filtered_logs) ?></div>
                <div class="stat-card__label">Eventos Filtrados</div>
            </div>
            <div class="stat-card">
                <div class="stat-card__val"><?= count($operation_logs) ?></div>
                <div class="stat-card__label">Operaciones & SSO</div>
            </div>
            <div class="stat-card">
                <div class="stat-card__val"><?= count($security_logs) ?></div>
                <div class="stat-card__label">Seguridad & Auth</div>
            </div>
            <div class="stat-card">
                <div class="stat-card__val"><?= count($mail_logs) ?></div>
                <div class="stat-card__label">Despachos SMTP</div>
            </div>
        </div>

        <form class="filters-panel" method="GET" action="logs.php">
            <div class="filter-group">
                <label>Sistema / Área</label>
                <select name="system">
                    <option value="all" <?= $filter_system === 'all' ? 'selected' : '' ?>>Todos los sistemas</option>
                    <option value="calendario" <?= $filter_system === 'calendario' ? 'selected' : '' ?>>📅 Calendario Salas</option>
                    <option value="sso" <?= $filter_system === 'sso' ? 'selected' : '' ?>>🎒 Portafolio / SSO Bridge</option>
                    <option value="seguridad" <?= $filter_system === 'seguridad' ? 'selected' : '' ?>>🔒 Seguridad y Sesiones</option>
                    <option value="correo" <?= $filter_system === 'correo' ? 'selected' : '' ?>>📧 Correo y Avisos SMTP</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Estado</label>
                <select name="status">
                    <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>Todos</option>
                    <option value="ok" <?= $filter_status === 'ok' ? 'selected' : '' ?>>Éxito (OK)</option>
                    <option value="failed" <?= $filter_status === 'failed' ? 'selected' : '' ?>>Fallos (Error)</option>
                </select>
            </div>

            <div class="filter-group" style="flex:1;min-width:220px;">
                <label>Búsqueda rápida</label>
                <input type="text" name="q" value="<?= htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') ?>" placeholder="Buscar por usuario, evento o detalle...">
            </div>

            <button type="submit" class="btn-filter">Filtrar bitácora</button>
            <?php if ($filter_system !== 'all' || $filter_status !== 'all' || $search_query !== ''): ?>
                <a href="logs.php" style="color:#7bc4ff;text-decoration:none;font-size:0.86rem;padding-bottom:10px;">Limpiar filtros</a>
            <?php endif; ?>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:170px;">Fecha y Hora</th>
                        <th style="width:140px;">Sistema</th>
                        <th style="width:150px;">Evento</th>
                        <th style="width:100px;">Estado</th>
                        <th>Detalle & Contexto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($filtered_logs)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center;padding:36px;color:rgba(210,228,248,0.6);">
                            No se encontraron registros que coincidan con los filtros seleccionados.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($filtered_logs as $log): ?>
                    <tr>
                        <td class="time-str">
                            <?= htmlspecialchars(date('d/m/Y H:i:s', strtotime($log['at'] ?: 'now')), ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <span class="sys-tag"><?= htmlspecialchars($log['system'], ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td style="font-weight:700;">
                            <?= htmlspecialchars($log['event'], ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <?php if ($log['status'] === 'ok'): ?>
                                <span class="badge badge--ok">✓ OK</span>
                            <?php elseif ($log['status'] === 'failed'): ?>
                                <span class="badge badge--failed">✕ Fallo</span>
                            <?php else: ?>
                                <span class="badge badge--info"><?= htmlspecialchars($log['status'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-weight:500;"><?= htmlspecialchars($log['detail'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if (!empty($log['context']) && $log['context'] !== '[]' && $log['context'] !== '{}'): ?>
                                <span class="context-code" title="<?= htmlspecialchars($log['context'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($log['context'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
