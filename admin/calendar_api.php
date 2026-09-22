<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/calendar_store.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/calendar_alerts.php';

if (!defined('CASTEL_CALENDAR_MAIL_WORKER')) {
    admin_bootstrap_session();
    admin_require_login();
    header('Content-Type: application/json; charset=UTF-8');
}

// Deja rastro de fallos de ejecucion sin filtrar rutas internas ni datos sensibles.
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    admin_log_operation('calendar_runtime', 'php_error', 'failed', array(
        'severity' => (int) $severity,
        'file' => basename((string) $file),
        'line' => (int) $line,
    ), (string) $message);
    return false;
});
register_shutdown_function(function () {
    $last = error_get_last();
    if (!$last || !in_array((int) $last['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        return;
    }
    admin_log_operation('calendar_runtime', 'fatal_error', 'failed', array(
        'file' => basename((string) $last['file']),
        'line' => (int) $last['line'],
    ), (string) $last['message']);
});

function calendar_api_response($payload, $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function calendar_api_input()
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return array();
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function calendar_api_trimmed($value, $maxLength)
{
    $text = trim((string) $value);
    if ($maxLength <= 0 || $text === '') {
        return $text;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength);
    }
    return substr($text, 0, $maxLength);
}

function calendar_api_send_mail($to, $subject, $bodyPlain, $bodyHtml = null)
{
    $to = admin_normalize_email($to);
    if ($to === '') {
        return false;
    }

    return calendar_api_enqueue_mail($to, $subject, $bodyPlain, $bodyHtml);
}

function calendar_api_mail_queue_table()
{
    return 'ccg_calendar_mail_queue';
}

function calendar_api_mail_queue_control_table()
{
    return 'ccg_calendar_mail_queue_control';
}

function calendar_api_ensure_mail_queue($conn)
{
    $table = calendar_api_mail_queue_table();
    $sql = 'CREATE TABLE IF NOT EXISTS `' . $table . '` ('
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . '`recipient` VARCHAR(255) NOT NULL,'
        . '`subject` VARCHAR(255) NOT NULL,'
        . '`body_plain` LONGTEXT NOT NULL,'
        . '`body_html` LONGTEXT NULL,'
        . '`status` VARCHAR(20) NOT NULL DEFAULT \'queued\','
        . '`attempts` INT NOT NULL DEFAULT 0,'
        . '`last_error` TEXT NULL,'
        . '`created_at` VARCHAR(40) NOT NULL,'
        . '`available_at` VARCHAR(40) NULL,'
        . '`locked_at` VARCHAR(40) NULL,'
        . '`lock_token` CHAR(64) NULL,'
        . '`sent_at` VARCHAR(40) NULL,'
        . 'PRIMARY KEY (`id`), KEY `idx_ccg_calendar_mail_status` (`status`, `available_at`, `id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    if (!@mysqli_query($conn, $sql)) {
        return false;
    }

    $columns = array(
        'available_at' => 'ALTER TABLE `' . $table . '` ADD COLUMN `available_at` VARCHAR(40) NULL AFTER `created_at`',
        'locked_at' => 'ALTER TABLE `' . $table . '` ADD COLUMN `locked_at` VARCHAR(40) NULL AFTER `available_at`',
        'lock_token' => 'ALTER TABLE `' . $table . '` ADD COLUMN `lock_token` CHAR(64) NULL AFTER `locked_at`',
    );
    foreach ($columns as $column => $alter) {
        $check = @mysqli_query($conn, 'SHOW COLUMNS FROM `' . $table . '` LIKE \'' . $column . '\'');
        $exists = $check && mysqli_num_rows($check) > 0;
        if ($check) {
            mysqli_free_result($check);
        }
        if (!$exists && !@mysqli_query($conn, $alter)) {
            return false;
        }
    }

    $controlTable = calendar_api_mail_queue_control_table();
    $controlSql = 'CREATE TABLE IF NOT EXISTS `' . $controlTable . '` ('
        . '`id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,'
        . '`last_dispatch_at` VARCHAR(40) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    if (!@mysqli_query($conn, $controlSql)) {
        return false;
    }
    return (bool) @mysqli_query($conn, 'INSERT IGNORE INTO `' . $controlTable . '` (`id`) VALUES (1)');
}

function calendar_api_enqueue_mail($to, $subject, $bodyPlain, $bodyHtml = null)
{
    $conn = admin_db_connect();
    if (!$conn || !calendar_api_ensure_mail_queue($conn)) {
        if ($conn) @mysqli_close($conn);
        admin_log_operation('calendar_mail', 'enqueue', 'failed', array('recipient' => $to), 'No se pudo preparar la cola de correo.');
        return false;
    }

    $table = calendar_api_mail_queue_table();
    $status = 'queued';
    $createdAt = date('c');
    $html = $bodyHtml === null ? '' : (string) $bodyHtml;
    $stmt = @mysqli_prepare($conn, 'INSERT INTO `' . $table . '` (`recipient`, `subject`, `body_plain`, `body_html`, `status`, `created_at`, `available_at`) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        @mysqli_close($conn);
        admin_log_operation('calendar_mail', 'enqueue', 'failed', array('recipient' => $to), 'No se pudo preparar el envío de correo.');
        return false;
    }
    @mysqli_stmt_bind_param($stmt, 'sssssss', $to, $subject, $bodyPlain, $html, $status, $createdAt, $createdAt);
    $saved = @mysqli_stmt_execute($stmt);
    $jobId = $saved ? (int) mysqli_insert_id($conn) : 0;
    @mysqli_stmt_close($stmt);
    @mysqli_close($conn);
    admin_log_operation('calendar_mail', 'enqueue', $saved ? 'queued' : 'failed', array('job_id' => $jobId, 'recipient' => $to), $subject);
    return $saved;
}

function calendar_api_mail_retry_delay_seconds($attempts)
{
    $delays = array(60, 300, 900, 3600);
    $index = max(0, min(count($delays) - 1, (int) $attempts - 1));
    return $delays[$index];
}

function calendar_api_log_mail_queue_failure($event, $detail, $context = array())
{
    admin_log_operation('calendar_mail_queue', $event, 'failed', is_array($context) ? $context : array(), substr((string) $detail, 0, 800));
}

function calendar_api_process_mail_queue($limit = 1)
{
    $result = array('processed' => 0, 'failed' => 0, 'skipped' => '');
    $conn = admin_db_connect();
    if (!$conn || !calendar_api_ensure_mail_queue($conn)) {
        if ($conn) @mysqli_close($conn);
        $result['failed'] = 1;
        calendar_api_log_mail_queue_failure('prepare_queue', 'No se pudo conectar o preparar la cola de correo.');
        return $result;
    }

    $lockName = 'ccg_calendar_mail_queue_worker';
    $lockResult = @mysqli_query($conn, 'SELECT GET_LOCK(\'' . $lockName . '\', 0) AS acquired');
    $lockRow = $lockResult ? mysqli_fetch_assoc($lockResult) : null;
    if ($lockResult) {
        mysqli_free_result($lockResult);
    }
    if ((int) ($lockRow['acquired'] ?? 0) !== 1) {
        @mysqli_close($conn);
        $result['skipped'] = 'worker_busy';
        return $result;
    }

    $table = calendar_api_mail_queue_table();
    $controlTable = calendar_api_mail_queue_control_table();
    $now = date('c');
    $staleBefore = date('c', time() - 180);
    $job = null;
    $token = hash('sha256', random_bytes(32));

    try {
        // Un worker que murió no puede dejar una fila bloqueada para siempre.
        @mysqli_query($conn, 'UPDATE `' . $table . '` SET `status` = \'queued\', `locked_at` = NULL, `lock_token` = NULL, `available_at` = \'' . $now . '\', `last_error` = \'Recuperado tras un envío interrumpido.\' WHERE `status` = \'sending\' AND (`locked_at` IS NULL OR `locked_at` < \'' . $staleBefore . '\')');
        $recovered = mysqli_affected_rows($conn);
        if ($recovered > 0) {
            admin_log_operation('calendar_mail_queue', 'recover_stale', 'recovered', array('jobs' => $recovered), 'Trabajos de correo recuperados tras un envío interrumpido.');
        }

        if (!@mysqli_begin_transaction($conn)) {
            $result['failed'] = 1;
            calendar_api_log_mail_queue_failure('begin_transaction', 'No se pudo iniciar la transacción de la cola.');
            return $result;
        }
        $control = @mysqli_query($conn, 'SELECT `last_dispatch_at` FROM `' . $controlTable . '` WHERE `id` = 1 FOR UPDATE');
        if (!$control) {
            @mysqli_rollback($conn);
            $result['failed'] = 1;
            calendar_api_log_mail_queue_failure('lock_control', 'No se pudo bloquear el control de ritmo de la cola.');
            return $result;
        }
        $controlRow = $control ? mysqli_fetch_assoc($control) : null;
        if ($control) {
            mysqli_free_result($control);
        }
        $lastDispatch = (string) ($controlRow['last_dispatch_at'] ?? '');
        if ($lastDispatch !== '' && strtotime($lastDispatch) > time() - 10) {
            @mysqli_commit($conn);
            $result['skipped'] = 'rate_limited';
            return $result;
        }

        $select = @mysqli_prepare($conn, 'SELECT `id`, `recipient`, `subject`, `body_plain`, `body_html`, `attempts` FROM `' . $table . '` WHERE `status` = \'queued\' AND (`available_at` IS NULL OR `available_at` <= ?) AND `attempts` < 5 ORDER BY `id` ASC LIMIT 1 FOR UPDATE');
        if (!$select) {
            @mysqli_rollback($conn);
            $result['failed'] = 1;
            calendar_api_log_mail_queue_failure('select_job', 'No se pudo seleccionar el siguiente correo disponible.');
            return $result;
        }
        @mysqli_stmt_bind_param($select, 's', $now);
        @mysqli_stmt_execute($select);
        @mysqli_stmt_store_result($select);
        if (mysqli_stmt_num_rows($select) === 1) {
            $jobId = 0; $recipient = ''; $subject = ''; $bodyPlain = ''; $bodyHtml = ''; $attemptCount = 0;
            @mysqli_stmt_bind_result($select, $jobId, $recipient, $subject, $bodyPlain, $bodyHtml, $attemptCount);
            if (@mysqli_stmt_fetch($select)) {
                $job = array(
                    'id' => $jobId,
                    'recipient' => $recipient,
                    'subject' => $subject,
                    'body_plain' => $bodyPlain,
                    'body_html' => $bodyHtml,
                    'attempts' => $attemptCount,
                );
            }
        }
        @mysqli_stmt_close($select);
        if (!$job) {
            @mysqli_commit($conn);
            $result['skipped'] = 'empty';
            return $result;
        }

        $id = (int) $job['id'];
        $claim = @mysqli_prepare($conn, 'UPDATE `' . $table . '` SET `status` = \'sending\', `attempts` = `attempts` + 1, `locked_at` = ?, `lock_token` = ? WHERE `id` = ? AND `status` = \'queued\'');
        if (!$claim) {
            @mysqli_rollback($conn);
            $result['failed'] = 1;
            calendar_api_log_mail_queue_failure('claim_job', 'No se pudo preparar el bloqueo del correo.', array('job_id' => $id));
            return $result;
        }
        @mysqli_stmt_bind_param($claim, 'ssi', $now, $token, $id);
        @mysqli_stmt_execute($claim);
        $claimed = mysqli_stmt_affected_rows($claim) === 1;
        @mysqli_stmt_close($claim);
        if (!$claimed) {
            @mysqli_rollback($conn);
            $result['skipped'] = 'claim_lost';
            calendar_api_log_mail_queue_failure('claim_lost', 'El correo cambió antes de poder reclamarlo.', array('job_id' => $id));
            return $result;
        }
        if (!@mysqli_query($conn, 'UPDATE `' . $controlTable . '` SET `last_dispatch_at` = \'' . $now . '\' WHERE `id` = 1') || !@mysqli_commit($conn)) {
            @mysqli_rollback($conn);
            $result['failed'] = 1;
            calendar_api_log_mail_queue_failure('commit_claim', 'No se pudo confirmar el bloqueo del correo.', array('job_id' => $id));
            return $result;
        }

        $mailError = null;
        try {
            $sent = castel_mailer_send((string) $job['recipient'], (string) $job['subject'], (string) $job['body_plain'], $mailError, (string) $job['body_html']);
        } catch (Throwable $exception) {
            $sent = false;
            $mailError = 'Excepción SMTP: ' . $exception->getMessage();
        }
        $attempts = (int) $job['attempts'] + 1;
        $status = $sent ? 'sent' : ($attempts >= 5 ? 'failed' : 'queued');
        $availableAt = $sent || $status === 'failed' ? null : date('c', time() + calendar_api_mail_retry_delay_seconds($attempts));
        $detail = $sent ? '' : substr((string) $mailError, 0, 800);
        $sentAt = $sent ? date('c') : null;
        $finish = @mysqli_prepare($conn, 'UPDATE `' . $table . '` SET `status` = ?, `last_error` = ?, `available_at` = ?, `locked_at` = NULL, `lock_token` = NULL, `sent_at` = ? WHERE `id` = ? AND `status` = \'sending\' AND `lock_token` = ?');
        if ($finish) {
            @mysqli_stmt_bind_param($finish, 'ssssis', $status, $detail, $availableAt, $sentAt, $id, $token);
            @mysqli_stmt_execute($finish);
            $finished = mysqli_stmt_affected_rows($finish) === 1;
            @mysqli_stmt_close($finish);
            if (!$finished) {
                calendar_api_log_mail_queue_failure('finish_job', 'No se pudo registrar el resultado del envío.', array('job_id' => $id));
            }
        } else {
            calendar_api_log_mail_queue_failure('finish_job', 'No se pudo preparar el registro del resultado del envío.', array('job_id' => $id));
        }
        admin_log_operation('calendar_mail', 'deliver', $sent ? 'sent' : ($status === 'failed' ? 'failed_final' : 'retry_scheduled'), array('job_id' => $id, 'recipient' => (string) $job['recipient'], 'attempts' => $attempts), $sent ? (string) $job['subject'] : $detail);
        $result['processed'] = 1;
        $result['failed'] = $sent ? 0 : 1;
        return $result;
    } finally {
        @mysqli_query($conn, 'SELECT RELEASE_LOCK(\'' . $lockName . '\')');
        @mysqli_close($conn);
    }
}

function calendar_api_mail_queue_status_for_recipient($recipient)
{
    $recipient = admin_normalize_email($recipient);
    if ($recipient === '') {
        return array('ok' => false, 'message' => 'Correo inválido.', 'jobs' => array());
    }
    $conn = admin_db_connect();
    if (!$conn || !calendar_api_ensure_mail_queue($conn)) {
        if ($conn) @mysqli_close($conn);
        calendar_api_log_mail_queue_failure('inspect_queue', 'No se pudo consultar el estado de la cola.', array('recipient' => $recipient));
        return array('ok' => false, 'message' => 'No se pudo consultar la cola.', 'jobs' => array());
    }

    $table = calendar_api_mail_queue_table();
    $stmt = @mysqli_prepare($conn, 'SELECT `id`, `status`, `attempts`, `created_at`, `available_at`, `locked_at`, `sent_at`, `last_error` FROM `' . $table . '` WHERE `recipient` = ? ORDER BY `id` DESC LIMIT 10');
    if (!$stmt) {
        @mysqli_close($conn);
        calendar_api_log_mail_queue_failure('inspect_queue', 'No se pudo preparar la consulta de estado.', array('recipient' => $recipient));
        return array('ok' => false, 'message' => 'No se pudo consultar la cola.', 'jobs' => array());
    }
    @mysqli_stmt_bind_param($stmt, 's', $recipient);
    @mysqli_stmt_execute($stmt);
    @mysqli_stmt_store_result($stmt);
    $jobs = array();
    @mysqli_stmt_bind_result($stmt, $id, $status, $attempts, $createdAt, $availableAt, $lockedAt, $sentAt, $lastError);
    while (@mysqli_stmt_fetch($stmt)) {
        $jobs[] = array(
            'id' => (int) $id,
            'status' => (string) $status,
            'attempts' => (int) $attempts,
            'created_at' => (string) $createdAt,
            'available_at' => (string) $availableAt,
            'locked_at' => (string) $lockedAt,
            'sent_at' => (string) $sentAt,
            'last_error' => substr((string) $lastError, 0, 300),
        );
    }
    @mysqli_stmt_close($stmt);
    @mysqli_close($conn);
    return array('ok' => true, 'recipient' => $recipient, 'jobs' => $jobs);
}

function calendar_api_log_block_result($event, $result, $room, $date, $slotId, $user)
{
    admin_log_operation('calendar_block', $event, !empty($result['ok']) ? 'ok' : 'failed', array(
        'room' => $room,
        'date' => $date,
        'slot_id' => $slotId,
        'user' => admin_normalize_email($user['email'] ?? ''),
        'code' => $result['code'] ?? '',
    ), (string) ($result['message'] ?? ''));
}

function calendar_api_mail_esc($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function calendar_api_mail_calendar_url()
{
    return 'https://www.colegiocastelgandolfo.cl/admin/calendar.php';
}

function calendar_api_slot_display_for_mail($slotId)
{
    $slotId = (string) $slotId;
    if ($slotId === '') {
        return '';
    }
    $meta = calendar_block_meta($slotId);
    if (is_array($meta) && !empty($meta['nombre'])) {
        return (string) $meta['nombre'] . ' (' . $slotId . ')';
    }
    return $slotId;
}

/**
 * @param array<int, string> $introParagraphs
 * @param array<int, array{k:string,v:string}> $rows
 * @return array{plain:string,html:string}
 */
function calendar_api_notification_bodies($headline, $addressee, array $introParagraphs, array $rows, $ctaLabel = 'Abrir calendario')
{
    $url = calendar_api_mail_calendar_url();
    $name = trim((string) $addressee);
    $plainSalutation = $name !== '' ? $name : 'estimada/o';
    $lines = array();
    $lines[] = 'Hola ' . $plainSalutation . ',';
    $lines[] = '';
    foreach ($introParagraphs as $p) {
        if ((string) $p !== '') {
            $lines[] = (string) $p;
            $lines[] = '';
        }
    }
    foreach ($rows as $row) {
        $k = isset($row['k']) ? (string) $row['k'] : '';
        $v = isset($row['v']) ? (string) $row['v'] : '';
        $lines[] = $k . ': ' . $v;
    }
    $lines[] = '';
    $lines[] = $ctaLabel . ':';
    $lines[] = $url;
    $lines[] = '';
    $lines[] = 'Mensaje automático del panel privado del Colegio Castelgandolfo.';
    $plain = implode("\n", $lines);

    $htmlName = calendar_api_mail_esc($name !== '' ? $name : 'estimada/o');
    $introHtml = '';
    foreach ($introParagraphs as $p) {
        if ((string) $p !== '') {
            $introHtml .= '<p style="margin:0 0 12px;color:#1e293b;font-size:15px;line-height:1.55;">' . calendar_api_mail_esc($p) . '</p>';
        }
    }
    $rowsHtml = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0 4px;border-collapse:separate;border-spacing:0;background:#f1f5f9;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0">';
    foreach ($rows as $row) {
        $k = calendar_api_mail_esc(isset($row['k']) ? $row['k'] : '');
        $v = calendar_api_mail_esc(isset($row['v']) ? $row['v'] : '');
        $rowsHtml .= '<tr>'
            . '<td valign="top" style="padding:11px 16px;font-weight:700;color:#0f264f;font-size:14px;width:40%;border-bottom:1px solid #e2e8f0;background:rgba(255,255,255,0.7);">' . $k . '</td>'
            . '<td valign="top" style="padding:11px 16px;color:#334155;font-size:14px;line-height:1.45;border-bottom:1px solid #e2e8f0;">' . $v . '</td>'
            . '</tr>';
    }
    $rowsHtml .= '</table>';

    $ctaEsc = calendar_api_mail_esc($ctaLabel);
    $urlEsc = calendar_api_mail_esc($url);
    $headEsc = calendar_api_mail_esc($headline);

    $inner =
        '<p style="margin:0 0 14px;color:#1e293b;font-size:16px;line-height:1.45;">Hola <strong>' . $htmlName . '</strong>,</p>'
        . $introHtml
        . $rowsHtml
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 8px;"><tr>'
        . '<td style="border-radius:999px;background:linear-gradient(135deg,#4E8452,#3a6b3e);">'
        . '<a href="' . $urlEsc . '" style="display:inline-block;padding:14px 28px;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;border-radius:999px;">' . $ctaEsc . '</a>'
        . '</td></tr></table>'
        . '<p style="margin:14px 0 0;font-size:13px;line-height:1.5;color:#64748b;">Si el botón no se muestra, copia este enlace en el navegador:<br>'
        . '<a href="' . $urlEsc . '" style="color:#1f63bb;word-break:break-all;">' . $urlEsc . '</a></p>';

    $logo = 'https://www.colegiocastelgandolfo.cl/app/assets/LogoCastelGandolfoSinFondo.png';
    $html = '<!DOCTYPE html><html lang="es"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#e8edf4;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#e8edf4;padding:20px 10px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #d9e2ec;box-shadow:0 16px 42px rgba(15,38,79,0.12);">'
        . '<tr><td style="padding:20px 24px 16px;background:linear-gradient(125deg,#2C4C74 0%,#355a82 48%,#4E8452 100%);">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td style="width:56px;vertical-align:middle;"><img src="' . calendar_api_mail_esc($logo) . '" alt="Colegio Castelgandolfo" width="52" height="52" style="display:block;border-radius:12px;background:rgba(255,255,255,0.14);"></td>'
        . '<td style="vertical-align:middle;padding-left:14px;">'
        . '<p style="margin:0 0 4px;font-size:11px;letter-spacing:0.16em;text-transform:uppercase;color:rgba(255,255,255,0.82);font-weight:700;">Colegio Castelgandolfo</p>'
        . '<p style="margin:0;font-size:18px;line-height:1.25;font-weight:800;color:#ffffff;">' . $headEsc . '</p>'
        . '</td></tr></table>'
        . '</td></tr>'
        . '<tr><td style="padding:24px 24px 8px;">' . $inner . '</td></tr>'
        . '<tr><td style="padding:16px 24px 20px;background:#f1f5f9;border-top:1px solid #e2e8f0;font-size:12px;line-height:1.5;color:#64748b;">'
        . 'Mensaje automático del <strong>panel privado</strong> (calendario de sala de computación). '
        . 'Las respuestas suelen usar la casilla institucional configurada en <strong>Reply-To</strong> (por ejemplo avisos@colegiocastelgandolfo.cl).'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';

    return array('plain' => $plain, 'html' => $html);
}

function calendar_api_room_label($room)
{
    return $room === 'media' ? 'Sala Media' : 'Sala Básica';
}

function calendar_api_status_label($status)
{
    switch ($status) {
        case 'reservada':
            return 'Reservada';
        case 'mantenimiento':
            return 'Mantención';
        case 'bloqueada':
            return 'Bloqueada';
        case 'liberar':
            return 'Liberar';
        default:
            return 'Disponible';
    }
}

function calendar_api_send_reservation_notice($targetEmail, $targetName, $actorName, $reservation, $messageTitle, $messageIntro)
{
    $date = isset($reservation['date']) ? $reservation['date'] : '';
    $room = calendar_api_room_label(isset($reservation['room']) ? $reservation['room'] : 'basica');
    $status = calendar_api_status_label(isset($reservation['status']) ? $reservation['status'] : 'disponible');
    $responsable = isset($reservation['responsable_label']) && $reservation['responsable_label'] !== '' ? $reservation['responsable_label'] : 'Sin detalle';
    $notes = isset($reservation['notes']) && $reservation['notes'] !== '' ? $reservation['notes'] : 'Sin observaciones.';

    $greet = $targetName !== '' ? $targetName : $targetEmail;
    $rows = array(
        array('k' => 'Fecha', 'v' => $date),
        array('k' => 'Sala', 'v' => $room),
        array('k' => 'Estado', 'v' => $status),
        array('k' => 'Responsable / curso', 'v' => $responsable),
        array('k' => 'Observaciones', 'v' => $notes),
        array('k' => 'Registrado por', 'v' => $actorName),
    );
    $bodies = calendar_api_notification_bodies($messageTitle, $greet, array($messageIntro), $rows, 'Abrir calendario privado');

    return calendar_api_send_mail($targetEmail, $messageTitle, $bodies['plain'], $bodies['html']);
}

function calendar_api_send_change_request_notice($ownerEmail, $ownerName, $request)
{
    $subject = 'Solicitud de cambio en calendario de sala de computación';
    $greet = $ownerName !== '' ? $ownerName : $ownerEmail;
    $intro = array('Un docente solicitó modificar una reserva que hoy está a tu nombre en el calendario de la sala de computación.');
    $rows = array(
        array('k' => 'Fecha', 'v' => (string) ($request['date'] ?? '')),
        array('k' => 'Sala', 'v' => calendar_api_room_label($request['room'] ?? 'basica')),
        array('k' => 'Solicitante', 'v' => (string) ($request['requested_by_name'] ?? $request['requested_by_email'] ?? '')),
        array('k' => 'Estado solicitado', 'v' => calendar_api_status_label($request['requested_status'] ?? 'reservada')),
        array('k' => 'Responsable propuesto', 'v' => (($request['requested_responsable_label'] ?? '') !== '' ? (string) $request['requested_responsable_label'] : 'Sin detalle')),
        array('k' => 'Observaciones propuestas', 'v' => (($request['requested_notes'] ?? '') !== '' ? (string) $request['requested_notes'] : 'Sin observaciones.')),
        array('k' => 'Motivo', 'v' => (($request['reason'] ?? '') !== '' ? (string) $request['reason'] : 'Sin motivo indicado.')),
    );
    $bodies = calendar_api_notification_bodies('Solicitud de cambio · calendario', $greet, $intro, $rows, 'Revisar y responder en el panel');

    return calendar_api_send_mail($ownerEmail, $subject, $bodies['plain'], $bodies['html']);
}

function calendar_api_send_request_result_notice($request, $decision)
{
    $approvedBy = $request['approved_by_name'] ?? $request['approved_by_email'] ?? 'Equipo del colegio';
    $subject = $decision === 'approve'
        ? 'Solicitud aprobada en calendario de sala de computación'
        : 'Solicitud rechazada en calendario de sala de computación';
    $headline = $decision === 'approve' ? 'Solicitud aprobada' : 'Solicitud rechazada';
    $greet = ($request['requested_by_name'] ?? '') !== '' ? (string) $request['requested_by_name'] : (string) ($request['requested_by_email'] ?? '');
    $intro = array(
        $decision === 'approve'
            ? 'Tu solicitud de cambio en el calendario de la sala de computación fue aprobada.'
            : 'Tu solicitud de cambio en el calendario de la sala de computación fue rechazada.',
    );
    $rows = array(
        array('k' => 'Fecha', 'v' => (string) ($request['date'] ?? '')),
        array('k' => 'Sala', 'v' => calendar_api_room_label($request['room'] ?? 'basica')),
        array('k' => 'Estado solicitado', 'v' => calendar_api_status_label($request['requested_status'] ?? 'reservada')),
        array('k' => 'Responsable propuesto', 'v' => (($request['requested_responsable_label'] ?? '') !== '' ? (string) $request['requested_responsable_label'] : 'Sin detalle')),
        array('k' => 'Respondió', 'v' => (string) $approvedBy),
    );
    $bodies = calendar_api_notification_bodies($headline, $greet, $intro, $rows, 'Ver calendario actualizado');

    return calendar_api_send_mail($request['requested_by_email'] ?? '', $subject, $bodies['plain'], $bodies['html']);
}

function calendar_api_send_block_reservation_notice($targetEmail, $targetName, $actorName, $reservation, $messageTitle, $messageIntro)
{
    $date = isset($reservation['date']) ? $reservation['date'] : '';
    $room = calendar_api_room_label(isset($reservation['room']) ? $reservation['room'] : 'basica');
    $slot = isset($reservation['slot_id']) ? (string) $reservation['slot_id'] : '';
    $slotLabel = calendar_api_slot_display_for_mail($slot);
    $status = calendar_api_status_label(isset($reservation['status']) ? $reservation['status'] : 'disponible');
    $asignatura = isset($reservation['asignatura']) && $reservation['asignatura'] !== '' ? $reservation['asignatura'] : 'Sin detalle';
    $curso = calendar_api_course_label($reservation, 'curso', 'curso_letra', 'Sin curso');
    $docente = isset($reservation['docente']) && $reservation['docente'] !== '' ? $reservation['docente'] : 'Sin detalle';
    $notes = isset($reservation['notes']) && $reservation['notes'] !== '' ? $reservation['notes'] : 'Sin observaciones.';

    $greet = $targetName !== '' ? $targetName : $targetEmail;
    $rows = array(
        array('k' => 'Fecha', 'v' => $date),
        array('k' => 'Sala', 'v' => $room),
        array('k' => 'Bloque horario', 'v' => $slotLabel),
        array('k' => 'Estado', 'v' => $status),
        array('k' => 'Asignatura', 'v' => $asignatura),
        array('k' => 'Curso', 'v' => $curso),
        array('k' => 'Docente responsable', 'v' => $docente),
        array('k' => 'Observaciones', 'v' => $notes),
        array('k' => 'Registrado por', 'v' => $actorName),
    );
    $bodies = calendar_api_notification_bodies('Reserva de bloque · sala de computación', $greet, array($messageIntro), $rows, 'Abrir calendario privado');

    return calendar_api_send_mail($targetEmail, $messageTitle, $bodies['plain'], $bodies['html']);
}

function calendar_api_course_label($item, $courseKey, $letterKey, $fallback)
{
    $course = trim((string) ($item[$courseKey] ?? ''));
    $letter = trim((string) ($item[$letterKey] ?? ''));
    if ($course === '' && $letter === '') {
        return $fallback;
    }
    return trim($course . ($letter !== '' ? ' ' . $letter : ''));
}

function calendar_api_send_block_change_request_notice($ownerEmail, $ownerName, $request)
{
    $subject = 'Solicitud sobre un bloque de la sala de computación';
    $greet = $ownerName !== '' ? $ownerName : $ownerEmail;
    $slotId = (string) ($request['slot_id'] ?? '');
    $intro = array('Un colega solicitó modificar un bloque de la sala de computación que hoy está asociado a ti.');
    $rows = array(
        array('k' => 'Fecha', 'v' => (string) ($request['date'] ?? '')),
        array('k' => 'Sala', 'v' => calendar_api_room_label($request['room'] ?? 'basica')),
        array('k' => 'Bloque horario', 'v' => calendar_api_slot_display_for_mail($slotId)),
        array('k' => 'Solicitante', 'v' => (string) ($request['requested_by_name'] ?? $request['requested_by_email'] ?? '')),
        array('k' => 'Estado solicitado', 'v' => calendar_api_status_label($request['requested_status'] ?? 'reservada')),
        array('k' => 'Asignatura propuesta', 'v' => (($request['requested_asignatura'] ?? '') !== '' ? (string) $request['requested_asignatura'] : 'Sin detalle')),
        array('k' => 'Curso propuesto', 'v' => calendar_api_course_label($request, 'requested_curso', 'requested_curso_letra', 'Sin detalle')),
        array('k' => 'Docente propuesto', 'v' => (($request['requested_docente'] ?? '') !== '' ? (string) $request['requested_docente'] : 'Sin detalle')),
        array('k' => 'Observaciones propuestas', 'v' => (($request['requested_notes'] ?? '') !== '' ? (string) $request['requested_notes'] : 'Sin observaciones.')),
        array('k' => 'Motivo', 'v' => (($request['reason'] ?? '') !== '' ? (string) $request['reason'] : 'Sin motivo indicado.')),
    );
    $bodies = calendar_api_notification_bodies('Nueva solicitud sobre tu bloque', $greet, $intro, $rows, 'Aprobar o rechazar en el panel');

    return calendar_api_send_mail($ownerEmail, $subject, $bodies['plain'], $bodies['html']);
}

function calendar_api_send_block_request_result_notice($request, $decision)
{
    $approvedBy = $request['approved_by_name'] ?? $request['approved_by_email'] ?? 'Equipo del colegio';
    $subject = $decision === 'approve'
        ? 'Solicitud aprobada (bloque de sala de computación)'
        : 'Solicitud rechazada (bloque de sala de computación)';
    $headline = $decision === 'approve' ? 'Solicitud aprobada · bloque' : 'Solicitud rechazada · bloque';
    $greet = ($request['requested_by_name'] ?? '') !== '' ? (string) $request['requested_by_name'] : (string) ($request['requested_by_email'] ?? '');
    $intro = array(
        $decision === 'approve'
            ? 'Tu solicitud sobre un bloque de la sala de computación fue aprobada.'
            : 'Tu solicitud sobre un bloque de la sala de computación fue rechazada.',
    );
    $slotId = (string) ($request['slot_id'] ?? '');
    $rows = array(
        array('k' => 'Fecha', 'v' => (string) ($request['date'] ?? '')),
        array('k' => 'Sala', 'v' => calendar_api_room_label($request['room'] ?? 'basica')),
        array('k' => 'Bloque horario', 'v' => calendar_api_slot_display_for_mail($slotId)),
        array('k' => 'Estado solicitado', 'v' => calendar_api_status_label($request['requested_status'] ?? 'reservada')),
        array('k' => 'Respondió', 'v' => (string) $approvedBy),
    );
    $bodies = calendar_api_notification_bodies($headline, $greet, $intro, $rows, 'Abrir calendario');

    return calendar_api_send_mail($request['requested_by_email'] ?? '', $subject, $bodies['plain'], $bodies['html']);
}

function calendar_api_send_incidence_notice($record)
{
    // Destino leído desde mail_config.php (excluido del repo por .gitignore)
    $mailCfg = (is_file(__DIR__ . '/mail_config.php')) ? require __DIR__ . '/mail_config.php' : array();
    $target  = (is_array($mailCfg) && !empty($mailCfg['incidence_notify'])) ? $mailCfg['incidence_notify']
             : ((is_array($mailCfg) && !empty($mailCfg['reply_to'])) ? $mailCfg['reply_to'] : 'avisos@colegiocastelgandolfo.cl');
    $slotId = (string) ($record['slot_id'] ?? '');
    $headline = 'Nueva incidencia reportada · sala de computación';
    $subject = 'Incidencia reportada en sala de computación';
    $intro = array(
        'Se registró una nueva incidencia desde el calendario privado. El reporte quedó guardado en el sistema y se envía este aviso para revisión técnica.',
    );
    $rows = array(
        array('k' => 'Fecha', 'v' => (string) ($record['date'] ?? '')),
        array('k' => 'Sala', 'v' => calendar_api_room_label($record['room'] ?? 'basica')),
        array('k' => 'Bloque horario', 'v' => calendar_api_slot_display_for_mail($slotId)),
        array('k' => 'Tipo de problema', 'v' => (string) ($record['categoria'] ?? 'Otro')),
        array('k' => 'Prioridad', 'v' => (string) ($record['prioridad'] ?? 'Media')),
        array('k' => 'Puesto o equipo', 'v' => (($record['puesto'] ?? '') !== '' ? (string) $record['puesto'] : 'Sin indicar')),
        array('k' => 'Continuidad de clase', 'v' => (($record['continuidad'] ?? '') !== '' ? (string) $record['continuidad'] : 'Sin indicar')),
        array('k' => 'Detalle', 'v' => (string) ($record['detalle'] ?? '')),
        array('k' => 'Acción realizada o sugerida', 'v' => (($record['accion'] ?? '') !== '' ? (string) $record['accion'] : 'Sin indicar')),
        array('k' => 'Reportado por', 'v' => (string) (($record['reported_by_name'] ?? '') !== '' ? $record['reported_by_name'] : ($record['reported_by_email'] ?? ''))),
    );
    $bodies = calendar_api_notification_bodies($headline, 'Pablo', $intro, $rows, 'Abrir calendario privado');

    return calendar_api_send_mail($target, $subject, $bodies['plain'], $bodies['html']);
}

function calendar_api_wants_send_email($input)
{
    return !array_key_exists('send_email', $input) || !empty($input['send_email']);
}

function calendar_api_current_user()
{
    $user = admin_current_user();
    if (!$user) {
        calendar_api_response(array('ok' => false, 'message' => 'Sesión inválida.'), 401);
    }
    if (!empty($user['is_active']) || !array_key_exists('is_active', $user)) {
        return $user;
    }
    calendar_api_response(array('ok' => false, 'message' => 'Esta cuenta está desactivada.'), 403);
}

function calendar_api_user_payload($user)
{
    return array(
        'email' => $user['email'],
        'name' => admin_user_display_name($user),
        'role' => admin_user_role($user),
        'can_override' => calendar_user_can_override($user),
        'can_manage_maintenance' => admin_user_can_manage_site($user),
        'can_manage_holidays' => calendar_user_can_manage_holidays($user),
    );
}

/** Devuelve las cuentas activas que coordinación puede seleccionar como responsable. */
function calendar_api_responsible_emails($user)
{
    $currentEmail = admin_normalize_email($user['email'] ?? '');
    if (!calendar_user_can_override($user)) {
        return $currentEmail !== '' ? array($currentEmail) : array();
    }

    $emails = array();
    foreach (admin_read_authorized_users() as $email => $candidate) {
        $candidate = is_array($candidate) ? $candidate : array();
        if (array_key_exists('is_active', $candidate) && empty($candidate['is_active'])) {
            continue;
        }
        $normalized = admin_normalize_email($candidate['email'] ?? $email);
        if ($normalized !== '') {
            $emails[$normalized] = $normalized;
        }
    }
    if ($currentEmail !== '') {
        $emails[$currentEmail] = $currentEmail;
    }
    natcasesort($emails);
    return array_values($emails);
}

function calendar_api_user_name_for_email($email, $fallback = '')
{
    $email = admin_normalize_email($email);
    foreach (admin_read_authorized_users() as $candidateEmail => $candidate) {
        $candidate = is_array($candidate) ? $candidate : array();
        if (admin_normalize_email($candidate['email'] ?? $candidateEmail) !== $email) {
            continue;
        }
        $name = trim((string) ($candidate['full_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        break;
    }
    return $fallback !== '' ? (string) $fallback : $email;
}

function calendar_api_pending_requests($store, $user, $year, $room, $semester)
{
    $email = admin_normalize_email($user['email']);
    $canOverride = calendar_user_can_override($user);
    $items = array();

    foreach ($store['change_requests'] as $request) {
        if (!is_array($request)) {
            continue;
        }
        if (($request['approval_status'] ?? '') !== 'pendiente') {
            continue;
        }
        if (($request['room'] ?? '') !== $room) {
            continue;
        }
        if (!calendar_date_in_semester($request['date'] ?? '', $year, $semester)) {
            continue;
        }
        if (!$canOverride && $email !== ($request['owner_email'] ?? '') && $email !== ($request['requested_by_email'] ?? '')) {
            continue;
        }
        $items[] = $request;
    }

    usort($items, function ($left, $right) {
        return strcmp($left['date'] . ($left['created_at'] ?? ''), $right['date'] . ($right['created_at'] ?? ''));
    });

    return $items;
}

function calendar_api_slot_config()
{
    $configPath = __DIR__ . '/config_time_slots.json';
    if (is_file($configPath)) {
        $raw = file_get_contents($configPath);
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return array(
        'bloques_horarios' => calendar_block_catalog(),
        'cursos' => array(),
        'curso_letras' => array('A', 'B'),
        'docente_default' => 'Pablo Elías Avendaño Miranda',
        'jornada_ti' => array(
            'dias_habiles' => array('hora_salida' => '17:30', 'dias' => array(1, 2, 3, 4)),
            'viernes' => array('hora_salida' => '16:35', 'dias' => array(5)),
            'mensaje' => 'Fuera de Horario Soporte',
        ),
    );
}

function calendar_api_notice_path()
{
    return __DIR__ . '/../data/calendar_notices.json';
}

function calendar_api_calendar_notices()
{
    $path = calendar_api_notice_path();
    if (!is_file($path)) {
        return array();
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return array();
    }

    $notices = array();
    foreach ($decoded as $notice) {
        if (!is_array($notice)) {
            continue;
        }
        $times = array();
        foreach (($notice['weekly_times'] ?? array()) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $weekday = (int) ($row['weekday'] ?? 0);
            $time = trim((string) ($row['time'] ?? ''));
            if ($weekday < 1 || $weekday > 5 || $time === '') {
                continue;
            }
            $times[] = array(
                'weekday' => $weekday,
                'weekday_label' => trim((string) ($row['weekday_label'] ?? '')),
                'time' => $time,
                'slot_hint' => trim((string) ($row['slot_hint'] ?? '')),
            );
        }
        if (!$times) {
            continue;
        }
        $notices[] = array(
            'id' => trim((string) ($notice['id'] ?? '')),
            'title' => trim((string) ($notice['title'] ?? 'Aviso')),
            'subtitle' => trim((string) ($notice['subtitle'] ?? '')),
            'audience' => trim((string) ($notice['audience'] ?? '')),
            'room_note' => trim((string) ($notice['room_note'] ?? '')),
            'weekly_times' => $times,
        );
    }

    return $notices;
}

function calendar_api_recurring_commitments_path()
{
    return __DIR__ . '/../data/calendar_recurring_commitments.json';
}

function calendar_api_recurring_commitments()
{
    $path = calendar_api_recurring_commitments_path();
    if (!is_file($path)) {
        return array();
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return array();
    }

    $commitments = array();
    foreach ($decoded as $commitment) {
        if (!is_array($commitment)) {
            continue;
        }
        $startDate = trim((string) ($commitment['start_date'] ?? ''));
        $endDate = trim((string) ($commitment['end_date'] ?? ''));
        if (!calendar_is_valid_date_key($startDate) || !calendar_is_valid_date_key($endDate) || $startDate > $endDate) {
            continue;
        }

        $rules = array();
        foreach (($commitment['weekly_slots'] ?? array()) as $rule) {
            $weekday = (int) ($rule['weekday'] ?? 0);
            $slotId = calendar_normalize_slot_id((string) ($rule['slot_id'] ?? ''));
            if ($weekday < 1 || $weekday > 5 || $slotId === '' || !calendar_block_meta($slotId)) {
                continue;
            }
            $rules[] = array('weekday' => $weekday, 'slot_id' => $slotId);
        }
        if (!$rules) {
            continue;
        }

        $rooms = array();
        foreach (($commitment['rooms'] ?? array()) as $room) {
            $normalizedRoom = calendar_normalize_room($room);
            if (!in_array($normalizedRoom, $rooms, true)) {
                $rooms[] = $normalizedRoom;
            }
        }
        if (!$rooms) {
            continue;
        }

        $commitments[] = array(
            'id' => trim((string) ($commitment['id'] ?? 'institutional-commitment')),
            'title' => trim((string) ($commitment['title'] ?? 'Compromiso institucional')),
            'subtitle' => trim((string) ($commitment['subtitle'] ?? '')),
            'room_note' => trim((string) ($commitment['room_note'] ?? '')),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'rooms' => $rooms,
            'weekly_slots' => $rules,
        );
    }

    return $commitments;
}

function calendar_api_commitments_for_month($year, $month, $room)
{
    $first = sprintf('%04d-%02d-01', (int) $year, (int) $month);
    $last = date('Y-m-t', strtotime($first));
    $byDate = array();

    foreach (calendar_api_recurring_commitments() as $commitment) {
        if (!in_array($room, $commitment['rooms'], true)) {
            continue;
        }
        for ($date = $first; $date <= $last; $date = date('Y-m-d', strtotime($date . ' +1 day'))) {
            if ($date < $commitment['start_date'] || $date > $commitment['end_date']) {
                continue;
            }
            $weekday = (int) date('N', strtotime($date));
            foreach ($commitment['weekly_slots'] as $rule) {
                if ($weekday !== (int) $rule['weekday']) {
                    continue;
                }
                $slotId = $rule['slot_id'];
                if (!isset($byDate[$date])) {
                    $byDate[$date] = array();
                }
                $byDate[$date][$slotId] = array(
                    'id' => $commitment['id'] . ':' . $date . ':' . $slotId,
                    'date' => $date,
                    'slot_id' => $slotId,
                    'status' => 'reservada',
                    'owner_name' => 'Reserva institucional',
                    'asignatura' => $commitment['title'],
                    'curso' => 'IV medios',
                    'curso_letra' => '',
                    'docente' => $commitment['subtitle'],
                    'notes' => $commitment['room_note'],
                    'is_institutional_commitment' => true,
                );
            }
        }
    }

    return $byDate;
}

function calendar_api_commitment_for_slot($date, $slotId, $room)
{
    if (!calendar_is_valid_date_key($date) || $slotId === '') {
        return null;
    }
    foreach (calendar_api_recurring_commitments() as $commitment) {
        if (!in_array($room, $commitment['rooms'], true)) {
            continue;
        }
        if ($date < $commitment['start_date'] || $date > $commitment['end_date']) {
            continue;
        }
        $weekday = (int) date('N', strtotime($date));
        foreach ($commitment['weekly_slots'] as $rule) {
            if ($weekday === (int) $rule['weekday'] && $slotId === $rule['slot_id']) {
                return $commitment;
            }
        }
    }
    return null;
}

/** Devuelve el último cambio de bloques de una sala para informar la vista mensual. */
function calendar_api_latest_block_update($store, $room)
{
    $prefix = calendar_normalize_room($room) . ':';
    $auditLog = isset($store['audit_log']) && is_array($store['audit_log']) ? $store['audit_log'] : array();

    for ($index = count($auditLog) - 1; $index >= 0; $index--) {
        $entry = $auditLog[$index];
        if (!is_array($entry) || !in_array((string) ($entry['action_type'] ?? ''), array('create_block', 'update_block', 'delete_block'), true)) {
            continue;
        }

        $key = (string) ($entry['reservation_key'] ?? '');
        if (strpos($key, $prefix) !== 0) {
            continue;
        }

        $parts = explode(':', $key);
        $slotId = calendar_normalize_slot_id($parts[2] ?? '');
        $date = (string) ($parts[1] ?? '');
        if ($slotId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            continue;
        }

        return array(
            'updated_at' => (string) ($entry['created_at'] ?? ''),
            'date' => $date,
            'slot_id' => $slotId,
            'slot_label' => calendar_api_slot_display_for_mail($slotId),
            'action' => (string) ($entry['action_type'] ?? ''),
        );
    }

    return null;
}

function calendar_api_in_month($date, $year, $month)
{
    if (!calendar_is_valid_date_key($date)) {
        return false;
    }
    $prefix = sprintf('%04d-%02d-', (int) $year, (int) $month);
    return strpos($date, $prefix) === 0;
}

function calendar_api_roster_for_course($store, $course)
{
    $course = trim((string) $course);
    if ($course === '') {
        return array();
    }
    if (!isset($store['course_rosters']) || !is_array($store['course_rosters'])) {
        return array();
    }
    $roster = $store['course_rosters'][$course] ?? array();
    if (!is_array($roster)) {
        return array();
    }
    return array_values(array_filter(array_map('trim', $roster), function ($name) {
        return $name !== '';
    }));
}

if (defined('CASTEL_CALENDAR_MAIL_WORKER')) {
    return;
}

$user = calendar_api_current_user();
$method = strtoupper($_SERVER['REQUEST_METHOD']);
$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
$input = $method === 'POST' ? calendar_api_input() : $_GET;

if ($method === 'POST' && $action === 'process_mail_queue') {
    if (!admin_validate_csrf($input['csrf_token'] ?? null)) {
        calendar_api_response(array('ok' => false, 'message' => 'Token CSRF inválido.'), 403);
    }
    calendar_api_response(array('ok' => true, 'queue' => calendar_api_process_mail_queue(1)));
}

if ($method === 'GET' && $action === 'mail_queue_status') {
    $recipient = admin_normalize_email($_GET['recipient'] ?? '');
    $currentEmail = admin_normalize_email($user['email'] ?? '');

    // Professors can audit only their own delivery attempts; privileged roles can audit any recipient.
    if (!calendar_user_can_override($user) && ($recipient === '' || $recipient !== $currentEmail)) {
        calendar_api_response(array('ok' => false, 'message' => 'Sin permiso para consultar la cola.'), 403);
    }
    calendar_api_response(calendar_api_mail_queue_status_for_recipient($recipient));
}

if ($method === 'GET' && $action === 'load_blocks') {
    $year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
    $month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
    $room = calendar_normalize_room(isset($_GET['room']) ? $_GET['room'] : 'basica');
    if ($month < 1 || $month > 12) {
        calendar_api_response(array('ok' => false, 'message' => 'Mes inválido.'), 422);
    }

    $store = calendar_store_read_all();
    $reservas = array();
    $dayBadges = array();
    foreach ($store['block_reservations'] as $reservation) {
        if (!is_array($reservation)) {
            continue;
        }
        if (($reservation['room'] ?? '') !== $room) {
            continue;
        }
        $date = (string) ($reservation['date'] ?? '');
        if (!calendar_api_in_month($date, $year, $month)) {
            continue;
        }
        $slotId = calendar_normalize_slot_id((string) ($reservation['slot_id'] ?? ''));
        if ($slotId === '') {
            continue;
        }
        if (!isset($reservas[$date]) || !is_array($reservas[$date])) {
            $reservas[$date] = array();
        }
        $reservas[$date][$slotId] = $reservation;
        $slotMeta = calendar_block_meta($slotId);
        $status = calendar_normalize_status((string) ($reservation['status'] ?? 'disponible'));
        if ($slotMeta && empty($slotMeta['es_bloqueado']) && $status !== 'disponible') {
            $dayBadges[$date] = ($dayBadges[$date] ?? 0) + 1;
        }
    }

    $customHolidaysInMonth = array();
    $customHolidaysForYear = array();
    $yearKey = (string) $year;
    if (isset($store['custom_holidays'][$yearKey]) && is_array($store['custom_holidays'][$yearKey])) {
        foreach ($store['custom_holidays'][$yearKey] as $holidayDate => $holiday) {
            $label = '';
            if (is_array($holiday)) {
                $label = trim((string) ($holiday['label'] ?? ''));
            } elseif (is_string($holiday)) {
                $label = trim($holiday);
            }
            if ($label === '') {
                continue;
            }
            $dateKey = (string) $holidayDate;
            $customHolidaysForYear[$dateKey] = $label;
            if (calendar_api_in_month($dateKey, $year, $month)) {
                $customHolidaysInMonth[$dateKey] = $label;
            }
        }
    }

    $slotConfig = calendar_api_slot_config();
    $commitments = calendar_api_commitments_for_month($year, $month, $room);
    calendar_api_response(array(
        'ok' => true,
        'csrf_token' => admin_csrf_token(),
        'user' => calendar_api_user_payload($user),
        'responsible_emails' => calendar_api_responsible_emails($user),
        'year' => $year,
        'month' => $month,
        'room' => $room,
        'slots' => $slotConfig['bloques_horarios'] ?? calendar_block_catalog(),
        'cursos' => $slotConfig['cursos'] ?? array(),
        'curso_letras' => $slotConfig['curso_letras'] ?? array('A', 'B'),
        'docente_default' => $slotConfig['docente_default'] ?? 'Pablo Elías Avendaño Miranda',
        'jornada_ti' => $slotConfig['jornada_ti'] ?? array(),
        'status_colors' => $slotConfig['status_colors'] ?? array(),
        'reservas' => $reservas,
        'day_badges' => $dayBadges,
        'latest_block_update' => calendar_api_latest_block_update($store, $room),
        'pending_requests' => calendar_get_block_pending_requests($store, $user, $year, $room, $month),
        'custom_holidays_in_month' => $customHolidaysInMonth,
        'custom_holidays_for_year' => calendar_user_can_manage_holidays($user) ? $customHolidaysForYear : array(),
        'calendar_notices' => calendar_api_calendar_notices(),
        'institutional_commitments' => $commitments,
    ));
}

if ($method === 'GET' && $action === 'seat_map') {
    $room = calendar_normalize_room(isset($_GET['room']) ? $_GET['room'] : 'basica');
    $date = isset($_GET['date']) ? (string) $_GET['date'] : '';
    $slotId = calendar_normalize_slot_id(isset($_GET['slot_id']) ? $_GET['slot_id'] : '');
    if (!calendar_is_valid_date_key($date) || $slotId === '') {
        calendar_api_response(array('ok' => false, 'message' => 'Parámetros inválidos.'), 422);
    }

    $store = calendar_store_read_all();
    $reservation = calendar_get_block($store, $room, $date, $slotId);
    if (!$reservation) {
        calendar_api_response(array('ok' => false, 'message' => 'No hay reserva activa en ese bloque.'), 404);
    }

    $course = trim((string) ($reservation['curso'] ?? ''));
    $courseLetter = trim((string) ($reservation['curso_letra'] ?? ''));
    $courseWithLetter = trim($course . ($courseLetter !== '' ? ' ' . $courseLetter : ''));
    $roster = calendar_api_roster_for_course($store, $courseWithLetter);
    if (!$roster && $course !== '') {
        $roster = calendar_api_roster_for_course($store, $course);
    }
    $seats = array();
    for ($seat = 1; $seat <= 40; $seat++) {
        $seats[] = array(
            'puesto' => $seat,
            'alumno' => $roster[$seat - 1] ?? '',
        );
    }

    calendar_api_response(array(
        'ok' => true,
        'reservation' => $reservation,
        'seats' => $seats,
    ));
}

if ($method === 'GET' && $action === 'load') {
    $year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
    $room = calendar_normalize_room(isset($_GET['room']) ? $_GET['room'] : 'basica');
    $semester = calendar_normalize_semester(isset($_GET['semester']) ? $_GET['semester'] : 's1');
    $store = calendar_store_read_all();
    $reservations = array();

    foreach ($store['reservations'] as $reservation) {
        if (!is_array($reservation)) {
            continue;
        }
        if (($reservation['room'] ?? '') !== $room) {
            continue;
        }
        if (!calendar_date_in_semester($reservation['date'] ?? '', $year, $semester)) {
            continue;
        }
        $reservations[$reservation['date']] = $reservation;
    }

    $customHolidays = isset($store['custom_holidays'][(string) $year]) && is_array($store['custom_holidays'][(string) $year])
        ? $store['custom_holidays'][(string) $year]
        : array();

    calendar_api_response(array(
        'ok' => true,
        'user' => calendar_api_user_payload($user),
        'csrf_token' => admin_csrf_token(),
        'year' => $year,
        'room' => $room,
        'semester' => $semester,
        'reservations' => $reservations,
        'custom_holidays' => $customHolidays,
        'pending_requests' => calendar_api_pending_requests($store, $user, $year, $room, $semester),
    ));
}

if ($method === 'GET' && $action === 'export') {
    $year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
    $room = calendar_normalize_room(isset($_GET['room']) ? $_GET['room'] : 'basica');
    $semester = calendar_normalize_semester(isset($_GET['semester']) ? $_GET['semester'] : 's1');
    $store = calendar_store_read_all();
    calendar_api_response(array(
        'ok' => true,
        'payload' => calendar_store_export_period($store, $year, $room, $semester),
    ));
}

if ($method !== 'POST') {
    calendar_api_response(array('ok' => false, 'message' => 'Acción no permitida.'), 405);
}

if (!admin_validate_csrf(isset($input['csrf_token']) ? $input['csrf_token'] : null)) {
    calendar_api_response(array('ok' => false, 'message' => 'La sesión expiró. Recarga la página.'), 419);
}

if ($method === 'GET' && $action === 'notifications') {
    $email = admin_normalize_email($user['email'] ?? '');
    $store = calendar_store_read_all();
    calendar_api_response(array(
        'ok' => true,
        'notifications' => calendar_notifications_for_user($store, $email, 40),
        'unread' => calendar_unread_count($store, $email),
        'vapid_public_key' => calendar_push_vapid_public_key(),
    ));
}

if ($method === 'POST' && $action === 'notifications_read') {
    $email = admin_normalize_email($user['email'] ?? '');
    $ids = isset($input['ids']) && is_array($input['ids']) ? $input['ids'] : null;
    list(, , $result) = calendar_store_mutate(function (&$store) use ($email, $ids) {
        calendar_mark_notifications_read($store, $email, $ids);
        return array('ok' => true, 'unread' => calendar_unread_count($store, $email));
    });
    calendar_api_response($result);
}

if ($method === 'POST' && $action === 'save_push_subscription') {
    $email = admin_normalize_email($user['email'] ?? '');
    $sub = isset($input['subscription']) && is_array($input['subscription']) ? $input['subscription'] : null;
    if (!$sub || empty($sub['endpoint'])) {
        calendar_api_response(array('ok' => false, 'message' => 'Suscripción inválida.'), 422);
    }
    list(, , $result) = calendar_store_mutate(function (&$store) use ($email, $sub) {
        return array('ok' => calendar_save_push_subscription($store, $email, $sub));
    });
    calendar_api_response($result);
}

if ($action === 'save_block') {
    $room = calendar_normalize_room(isset($input['room']) ? $input['room'] : 'basica');
    $date = isset($input['date']) ? (string) $input['date'] : '';
    $slotId = calendar_normalize_slot_id(isset($input['slot_id']) ? $input['slot_id'] : '');
    $status = calendar_normalize_status(isset($input['status']) ? $input['status'] : 'reservada');
    $asignatura = calendar_api_trimmed($input['asignatura'] ?? '', 120);
    $curso = calendar_api_trimmed($input['curso'] ?? '', 80);
    $cursoLetra = calendar_api_trimmed($input['curso_letra'] ?? '', 10);
    $docente = admin_normalize_email(calendar_api_trimmed($input['docente'] ?? '', 120));
    $notes = calendar_api_trimmed($input['notes'] ?? '', 1200);
    $version = isset($input['version']) ? (int) $input['version'] : 0;

    if (!calendar_is_valid_date_key($date) || $slotId === '') {
        calendar_api_response(array('ok' => false, 'message' => 'Fecha o bloque inválido.'), 422);
    }

    $slotMeta = calendar_block_meta($slotId);
    if (!$slotMeta || !empty($slotMeta['es_bloqueado'])) {
        calendar_api_response(array('ok' => false, 'message' => 'Ese bloque no admite reservas.'), 422);
    }
    if (calendar_api_commitment_for_slot($date, $slotId, $room)) {
        calendar_api_response(array('ok' => false, 'message' => 'Este bloque está reservado para un compromiso institucional.'), 409);
    }

    // Anticipación mínima: no se pueden crear reservas de último minuto.
    // Solo aplica a reservas nuevas (ver rama de creación en el closure); editar o
    // liberar una reserva existente sigue permitido. El personal con override queda exento.
    $minLeadMinutes = calendar_reservation_min_lead_minutes();
    $minutesToStart = calendar_minutes_until_block_start($date, isset($slotMeta['hora_inicio']) ? $slotMeta['hora_inicio'] : '');
    $tooCloseToStart = (
        !calendar_user_can_override($user)
        && $status !== 'disponible'
        && $minutesToStart !== null
        && $minutesToStart < $minLeadMinutes
    );

    if ($status !== 'reservada' && $status !== 'mantenimiento' && $status !== 'disponible') {
        $status = 'reservada';
    }
    if ($status === 'mantenimiento' && !admin_user_can_manage_site($user)) {
        calendar_api_response(array('ok' => false, 'message' => 'Solo personal con permisos de administración puede programar mantenimiento.'), 403);
    }

    $actorEmail = admin_normalize_email($user['email'] ?? '');
    $actorName = admin_user_display_name($user);
    $ownerEmail = $actorEmail;
    $ownerName = $actorName;
    if (!calendar_user_can_override($user)) {
        $docente = $actorEmail;
    } else {
        $responsibleEmails = calendar_api_responsible_emails($user);
        if ($docente === '') {
            $docente = $actorEmail;
        }
        if (!in_array($docente, $responsibleEmails, true)) {
            calendar_api_response(array('ok' => false, 'message' => 'Selecciona un correo autorizado como responsable.'), 422);
        }
        $ownerEmail = $docente;
        $ownerName = calendar_api_user_name_for_email($docente, $docente);
    }

    if ($status === 'reservada') {
        $requiredFields = array(
            'actividad' => $asignatura,
            'curso' => $curso,
            'letra' => $cursoLetra,
            'correo del docente responsable' => $docente,
        );
        $missingFields = array_keys(array_filter($requiredFields, function ($value) {
            return trim((string) $value) === '';
        }));
        if ($missingFields) {
            $result = array(
                'ok' => false,
                'code' => 'missing_required_reservation_fields',
                'message' => 'Completa ' . implode(', ', $missingFields) . ' antes de guardar la reserva.',
            );
            calendar_api_log_block_result('save_validation_failed', $result, $room, $date, $slotId, $user);
            calendar_api_response($result, 422);
        }
    }
    if ($status === 'mantenimiento' && ($asignatura === '' || $docente === '')) {
        $result = array(
            'ok' => false,
            'code' => 'missing_required_maintenance_fields',
            'message' => 'Completa la actividad de mantenimiento y el correo responsable antes de guardar.',
        );
        calendar_api_log_block_result('maintenance_validation_failed', $result, $room, $date, $slotId, $user);
        calendar_api_response($result, 422);
    }

    // "Disponible" no representa una reserva persistente: siempre libera el bloque completo.
    // Esto también tolera clientes antiguos que arrastraban datos ocultos al presionar Liberar.
    $isClear = $status === 'disponible';
    if ($isClear) {
        $asignatura = '';
        $curso = '';
        $cursoLetra = '';
        $docente = '';
        $notes = '';
    }

    list(, , $result) = calendar_store_mutate(function (&$store) use ($user, $room, $date, $slotId, $status, $asignatura, $curso, $cursoLetra, $docente, $notes, $version, $isClear, $ownerEmail, $ownerName, $tooCloseToStart, $minLeadMinutes, $minutesToStart, $slotMeta) {
        $email = admin_normalize_email($user['email']);
        $name = admin_user_display_name($user);
        $blockKey = calendar_block_key($room, $date, $slotId);
        $existing = calendar_get_block($store, $room, $date, $slotId);

        if ($existing) {
            $ownerEmail = admin_normalize_email((string) ($existing['owner_email'] ?? ''));
            if (!calendar_user_can_override($user) && $ownerEmail !== $email) {
                return array(
                    'ok' => false,
                    'code' => 'owner_locked',
                    'message' => 'Bloque reservado por otro usuario. Debes solicitar aprobación.',
                    'reservation' => $existing,
                );
            }
            if ($version > 0 && (int) ($existing['version'] ?? 0) !== $version) {
                return array(
                    'ok' => false,
                    'code' => 'version_conflict',
                    'message' => 'Este bloque cambió mientras lo editabas.',
                    'reservation' => $existing,
                );
            }
            if ($isClear) {
                calendar_remove_block($store, $room, $date, $slotId);
                calendar_append_audit($store, 'delete_block', $email, $blockKey, $existing, null);
                calendar_add_notification(
                    $store,
                    $existing['owner_email'] ?? '',
                    'liberacion',
                    'Bloque liberado',
                    'Se liberó un bloque que tenías reservado el ' . $date . ' en la sala de computación.',
                    array(calendar_api_room_label($room))
                );
                return array('ok' => true, 'message' => 'Bloque liberado.', 'deleted_block' => $existing);
            }
            $updated = $existing;
            $updated['status'] = $status;
            $updated['asignatura'] = $asignatura;
            $updated['curso'] = $curso;
            $updated['curso_letra'] = $cursoLetra;
            $updated['docente'] = $docente;
            $updated['notes'] = $notes;
            $updated['updated_at'] = date('c');
            $updated['updated_by'] = $email;
            $updated['updated_by_name'] = $name;
            $updated['version'] = (int) ($existing['version'] ?? 0) + 1;
            calendar_set_block($store, $room, $date, $slotId, $updated);
            calendar_append_audit($store, 'update_block', $email, $blockKey, $existing, $updated);
            return array('ok' => true, 'message' => 'Bloque actualizado.', 'reservation' => $updated);
        }

        if ($isClear) {
            return array('ok' => true, 'message' => 'Sin cambios.');
        }

        // Reserva nueva: exigir anticipación mínima. Editar una reserva existente (arriba)
        // no pasa por aquí, así que corregir datos cerca de la hora sigue permitido.
        if ($tooCloseToStart) {
            return array(
                'ok' => false,
                'code' => 'too_close_to_start',
                'message' => $minutesToStart >= 0
                    ? 'No puedes reservar este bloque: empieza en menos de ' . $minLeadMinutes . ' minutos. Las reservas deben hacerse con al menos ' . $minLeadMinutes . ' minutos de anticipación.'
                    : 'No puedes reservar este bloque: ya comenzó. Las reservas deben hacerse con al menos ' . $minLeadMinutes . ' minutos de anticipación.',
                'min_lead_minutes' => $minLeadMinutes,
                'minutes_to_start' => $minutesToStart,
                'block_label' => isset($slotMeta['nombre']) ? (string) $slotMeta['nombre'] : $slotId,
                'block_start' => isset($slotMeta['hora_inicio']) ? (string) $slotMeta['hora_inicio'] : '',
                'block_end' => isset($slotMeta['hora_fin']) ? (string) $slotMeta['hora_fin'] : '',
            );
        }

        $store['meta']['last_block_id'] = (int) ($store['meta']['last_block_id'] ?? 0) + 1;
        $created = array(
            'id' => $store['meta']['last_block_id'],
            'slot_id' => $slotId,
            'date' => $date,
            'room' => $room,
            'status' => $status,
            'owner_email' => $ownerEmail,
            'owner_name' => $ownerName,
            'asignatura' => $asignatura,
            'curso' => $curso,
            'curso_letra' => $cursoLetra,
            'docente' => $docente,
            'notes' => $notes,
            'version' => 1,
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'updated_by' => $email,
            'updated_by_name' => $name,
        );
        calendar_set_block($store, $room, $date, $slotId, $created);
        calendar_append_audit($store, 'create_block', $email, $blockKey, null, $created);
        calendar_add_notification(
            $store,
            $created['owner_email'],
            'reserva',
            'Bloque reservado',
            'Se registró una reserva a tu nombre el ' . $date . ' en la sala de computación.',
            array(calendar_api_room_label($room), trim(($created['curso'] ?? '') . ' ' . ($created['curso_letra'] ?? '')), $created['asignatura'] ?? '')
        );
        return array('ok' => true, 'message' => 'Bloque reservado.', 'reservation' => $created);
    });

    calendar_api_log_block_result($isClear ? 'clear' : 'save', $result, $room, $date, $slotId, $user);

    $wantsMail = calendar_api_wants_send_email($input);
    $result['send_email_requested'] = $wantsMail;
    if (!empty($result['ok']) && $wantsMail) {
        $mailSent = false;
        if (!empty($result['deleted_block'])) {
            $ex = $result['deleted_block'];
            $to = admin_normalize_email((string) ($ex['owner_email'] ?? ''));
            if ($to !== '') {
                $greet = (isset($ex['owner_name']) && $ex['owner_name'] !== '') ? (string) $ex['owner_name'] : $to;
                $slotId = (string) ($ex['slot_id'] ?? '');
                $bodies = calendar_api_notification_bodies(
                    'Bloque liberado · sala de computación',
                    $greet,
                    array('Se liberó un bloque de la sala de computación que estaba registrado a tu nombre.'),
                    array(
                        array('k' => 'Fecha', 'v' => (string) ($ex['date'] ?? '')),
                        array('k' => 'Sala', 'v' => calendar_api_room_label($ex['room'] ?? 'basica')),
                        array('k' => 'Bloque horario', 'v' => calendar_api_slot_display_for_mail($slotId)),
                        array('k' => 'Liberado por', 'v' => $actorName),
                    ),
                    'Abrir calendario'
                );
                $mailSent = calendar_api_send_mail($to, 'Bloque liberado en sala de computación', $bodies['plain'], $bodies['html']);
                if ($mailSent) {
                    $result['mail_notice'] = 'Correo tipo "bloque liberado" enviado al docente que tenía la reserva.';
                }
            }
        } elseif (!empty($result['reservation'])) {
            $r = $result['reservation'];
            $to = admin_normalize_email($r['owner_email'] ?? ($r['docente'] ?? $actorEmail));
            if ($to !== '') {
                $targetName = calendar_api_user_name_for_email($to, (string) ($r['owner_name'] ?? $to));
                $intro = (strpos((string) ($result['message'] ?? ''), 'actualizado') !== false)
                    ? 'Se actualizó tu reserva de bloque en la sala de computación.'
                    : 'Se registró tu reserva de bloque en la sala de computación.';
                $mailSent = calendar_api_send_block_reservation_notice(
                    $to,
                    $targetName,
                    $actorName,
                    $r,
                    'Reserva de bloque — sala de computación',
                    $intro
                );
                if ($mailSent) {
                    $result['mail_notice'] = (strpos((string) ($result['message'] ?? ''), 'actualizado') !== false)
                        ? 'Correo de confirmación "reserva actualizada" enviado a tu casilla.'
                        : 'Correo de confirmación "bloque reservado" enviado a tu casilla.';
                }
            }
        }
        $result['mail_sent'] = $mailSent;
    } else {
        $result['mail_sent'] = false;
    }

    calendar_api_response($result, !empty($result['ok']) ? 200 : 409);
}

if ($action === 'request_block_change') {
    $room = calendar_normalize_room(isset($input['room']) ? $input['room'] : 'basica');
    $date = isset($input['date']) ? (string) $input['date'] : '';
    $slotId = calendar_normalize_slot_id(isset($input['slot_id']) ? $input['slot_id'] : '');
    $requestedStatus = calendar_normalize_status(isset($input['status']) ? $input['status'] : 'reservada');
    if (!in_array($requestedStatus, array('reservada', 'mantenimiento', 'disponible'), true)) {
        $requestedStatus = 'reservada';
    }
    $asignatura = calendar_api_trimmed($input['asignatura'] ?? '', 120);
    $curso = calendar_api_trimmed($input['curso'] ?? '', 80);
    $cursoLetra = calendar_api_trimmed($input['curso_letra'] ?? '', 10);
    $docente = calendar_api_trimmed($input['docente'] ?? '', 120);
    $notes = calendar_api_trimmed($input['notes'] ?? '', 1200);
    $reason = calendar_api_trimmed($input['reason'] ?? '', 800);

    if (!calendar_is_valid_date_key($date) || $slotId === '' || $reason === '') {
        calendar_api_response(array('ok' => false, 'message' => 'Completa fecha, bloque y motivo.'), 422);
    }
    if (calendar_api_commitment_for_slot($date, $slotId, $room)) {
        calendar_api_response(array('ok' => false, 'message' => 'Este bloque está reservado para un compromiso institucional.'), 409);
    }

    list(, , $result) = calendar_store_mutate(function (&$store) use ($user, $room, $date, $slotId, $requestedStatus, $asignatura, $curso, $cursoLetra, $docente, $notes, $reason) {
        $existing = calendar_get_block($store, $room, $date, $slotId);
        if (!$existing) {
            return array('ok' => false, 'message' => 'Ya no existe una reserva en ese bloque.');
        }
        $email = admin_normalize_email($user['email']);
        $ownerEmail = admin_normalize_email((string) ($existing['owner_email'] ?? ''));
        if ($ownerEmail === $email) {
            return array('ok' => false, 'message' => 'No necesitas solicitar aprobación para tu propia reserva.');
        }

        foreach ($store['block_change_requests'] as $request) {
            if (!is_array($request)) {
                continue;
            }
            if (($request['room'] ?? '') === $room
                && ($request['date'] ?? '') === $date
                && ($request['slot_id'] ?? '') === $slotId
                && ($request['requested_by_email'] ?? '') === $email
                && ($request['approval_status'] ?? '') === 'pendiente') {
                return array('ok' => false, 'message' => 'Ya tienes una solicitud pendiente para este bloque.');
            }
        }

        $store['meta']['last_block_change_request_id'] = (int) ($store['meta']['last_block_change_request_id'] ?? 0) + 1;
        $request = array(
            'id' => $store['meta']['last_block_change_request_id'],
            'room' => $room,
            'date' => $date,
            'slot_id' => $slotId,
            'reservation_id' => $existing['id'] ?? null,
            'owner_email' => $ownerEmail,
            'owner_name' => $existing['owner_name'] ?? $ownerEmail,
            'requested_by_email' => $email,
            'requested_by_name' => admin_user_display_name($user),
            'requested_status' => $requestedStatus,
            'requested_asignatura' => $asignatura,
            'requested_curso' => $curso,
            'requested_curso_letra' => $cursoLetra,
            'requested_docente' => $docente,
            'requested_notes' => $notes,
            'reason' => $reason,
            'approval_status' => 'pendiente',
            'created_at' => date('c'),
        );
        $store['block_change_requests'][] = $request;
        calendar_append_audit($store, 'request_block_change', $email, calendar_block_key($room, $date, $slotId), $existing, $request);
        calendar_add_notification(
            $store,
            $ownerEmail,
            'solicitud',
            'Te solicitaron un bloque',
            ($request['requested_by_name'] ?? 'Un colega') . ' solicitó modificar tu reserva del ' . $date . '. Revisa y responde en el calendario.',
            array(calendar_api_room_label($room))
        );
        return array('ok' => true, 'message' => 'Solicitud de aprobación enviada.', 'request' => $request);
    });

    $wantsMail = calendar_api_wants_send_email($input);
    $result['send_email_requested'] = $wantsMail;
    if (!empty($result['ok']) && $wantsMail && !empty($result['request'])) {
        $req = $result['request'];
        $ownerEmail = admin_normalize_email((string) ($req['owner_email'] ?? ''));
        if ($ownerEmail !== '') {
            $sent = calendar_api_send_block_change_request_notice(
                $ownerEmail,
                (string) ($req['owner_name'] ?? $ownerEmail),
                $req
            );
            $result['mail_sent'] = $sent;
            if ($sent) {
                $result['mail_notice'] = 'Correo tipo "solicitud de cambio de bloque" enviado al propietario de la reserva.';
            }
        } else {
            $result['mail_sent'] = false;
        }
    } else {
        $result['mail_sent'] = false;
    }

    calendar_api_response($result, !empty($result['ok']) ? 200 : 409);
}

if ($action === 'respond_block_request') {
    $requestId = isset($input['request_id']) ? (int) $input['request_id'] : 0;
    $decision = strtolower(trim((string) ($input['decision'] ?? '')));
    if (!in_array($decision, array('approve', 'reject'), true)) {
        calendar_api_response(array('ok' => false, 'message' => 'Decisión inválida.'), 422);
    }

    list(, , $result) = calendar_store_mutate(function (&$store) use ($user, $requestId, $decision) {
        $email = admin_normalize_email($user['email']);
        $name = admin_user_display_name($user);
        $canOverride = calendar_user_can_override($user);
        foreach ($store['block_change_requests'] as $index => $request) {
            if (!is_array($request) || (int) ($request['id'] ?? 0) !== $requestId) {
                continue;
            }
            if (($request['approval_status'] ?? '') !== 'pendiente') {
                return array('ok' => false, 'message' => 'La solicitud ya fue resuelta.');
            }
            $ownerEmail = admin_normalize_email((string) ($request['owner_email'] ?? ''));
            if (!$canOverride && $ownerEmail !== $email) {
                return array('ok' => false, 'message' => 'No tienes permiso para responder esta solicitud.');
            }

            $request['approval_status'] = $decision === 'approve' ? 'aprobada' : 'rechazada';
            $request['approved_by_email'] = $email;
            $request['approved_by_name'] = $name;
            $request['approved_at'] = date('c');
            $store['block_change_requests'][$index] = $request;

            calendar_add_notification(
                $store,
                $request['requested_by_email'] ?? '',
                $decision === 'approve' ? 'solicitud_aprobada' : 'solicitud_rechazada',
                $decision === 'approve' ? 'Solicitud aprobada' : 'Solicitud rechazada',
                'Tu solicitud sobre el bloque del ' . ($request['date'] ?? '') . ' fue ' . ($decision === 'approve' ? 'aprobada' : 'rechazada') . ' por ' . $name . '.',
                array(calendar_api_room_label($request['room'] ?? 'basica'))
            );

            $slotId = calendar_normalize_slot_id((string) ($request['slot_id'] ?? ''));
            $reservation = $slotId !== '' ? calendar_get_block($store, $request['room'], $request['date'], $slotId) : null;
            if ($decision === 'approve' && !$reservation) {
                return array('ok' => false, 'message' => 'La reserva original ya no existe. No se pudo aprobar la solicitud.');
            }
            if ($decision === 'approve' && $reservation) {
                $oldPayload = $reservation;
                $reservation['status'] = calendar_normalize_status($request['requested_status'] ?? 'reservada');
                $reservation['asignatura'] = (string) ($request['requested_asignatura'] ?? $reservation['asignatura']);
                $reservation['curso'] = (string) ($request['requested_curso'] ?? $reservation['curso']);
                $reservation['curso_letra'] = (string) ($request['requested_curso_letra'] ?? ($reservation['curso_letra'] ?? ''));
                $reservation['docente'] = (string) ($request['requested_docente'] ?? $reservation['docente']);
                $reservation['notes'] = (string) ($request['requested_notes'] ?? $reservation['notes']);
                $reservation['owner_email'] = (string) ($request['requested_by_email'] ?? $reservation['owner_email']);
                $reservation['owner_name'] = (string) ($request['requested_by_name'] ?? $reservation['owner_name']);
                $reservation['updated_at'] = date('c');
                $reservation['updated_by'] = $email;
                $reservation['updated_by_name'] = $name;
                $reservation['version'] = (int) ($reservation['version'] ?? 0) + 1;
                calendar_set_block($store, $request['room'], $request['date'], $slotId, $reservation);
                calendar_append_audit($store, 'approve_block_change', $email, calendar_block_key($request['room'], $request['date'], $slotId), $oldPayload, $reservation);
            } else {
                calendar_append_audit($store, 'reject_block_change', $email, calendar_block_key($request['room'], $request['date'], (string) ($request['slot_id'] ?? '')), null, $request);
            }
            return array(
                'ok' => true,
                'message' => $decision === 'approve' ? 'Solicitud aprobada.' : 'Solicitud rechazada.',
                'request' => $request,
                'decision' => $decision,
            );
        }
        return array('ok' => false, 'message' => 'No se encontró la solicitud.');
    });

    $wantsMail = calendar_api_wants_send_email($input);
    $result['send_email_requested'] = $wantsMail;
    if (!empty($result['ok']) && $wantsMail && !empty($result['request'])) {
        $sent = calendar_api_send_block_request_result_notice($result['request'], (string) ($result['decision'] ?? ''));
        $result['mail_sent'] = $sent;
        if ($sent) {
            $dec = (string) ($result['decision'] ?? '');
            $result['mail_notice'] = $dec === 'approve'
                ? 'Correo tipo "solicitud aprobada" enviado al docente que solicitó el cambio.'
                : 'Correo tipo "solicitud rechazada" enviado al docente que solicitó el cambio.';
        }
    } else {
        $result['mail_sent'] = false;
    }

    calendar_api_response($result, !empty($result['ok']) ? 200 : 404);
}

if ($action === 'report_incidence') {
    $room = calendar_normalize_room(isset($input['room']) ? $input['room'] : 'basica');
    $date = isset($input['date']) ? (string) $input['date'] : '';
    $slotId = calendar_normalize_slot_id(isset($input['slot_id']) ? $input['slot_id'] : '');
    $categoria = calendar_api_trimmed($input['categoria'] ?? 'Otro', 80);
    $prioridad = calendar_api_trimmed($input['prioridad'] ?? 'Media', 40);
    $puesto = calendar_api_trimmed($input['puesto'] ?? '', 120);
    $continuidad = calendar_api_trimmed($input['continuidad'] ?? '', 120);
    $detalle = calendar_api_trimmed($input['detalle'] ?? '', 1200);
    $accion = calendar_api_trimmed($input['accion'] ?? '', 1200);
    if (!calendar_is_valid_date_key($date) || $slotId === '' || $detalle === '') {
        calendar_api_response(array('ok' => false, 'message' => 'Completa bloque y detalle de la incidencia.'), 422);
    }

    list(, , $result) = calendar_store_mutate(function (&$store) use ($user, $room, $date, $slotId, $categoria, $prioridad, $puesto, $continuidad, $detalle, $accion) {
        $store['meta']['last_incidence_id'] = (int) ($store['meta']['last_incidence_id'] ?? 0) + 1;
        $record = array(
            'id' => $store['meta']['last_incidence_id'],
            'room' => $room,
            'date' => $date,
            'slot_id' => $slotId,
            'categoria' => $categoria !== '' ? $categoria : 'Otro',
            'prioridad' => $prioridad !== '' ? $prioridad : 'Media',
            'puesto' => $puesto,
            'continuidad' => $continuidad,
            'detalle' => $detalle,
            'accion' => $accion,
            'status' => 'pendiente',
            'reported_by_email' => admin_normalize_email($user['email']),
            'reported_by_name' => admin_user_display_name($user),
            'created_at' => date('c'),
        );
        $store['incidences'][] = $record;
        calendar_append_audit($store, 'report_incidence', $record['reported_by_email'], calendar_block_key($room, $date, $slotId), null, $record);
        return array('ok' => true, 'message' => 'Incidencia registrada correctamente.', 'incidence' => $record);
    });

    if (!empty($result['ok']) && !empty($result['incidence'])) {
        $sent = calendar_api_send_incidence_notice($result['incidence']);
        $result['send_email_requested'] = true;
        $result['mail_sent'] = $sent;
        $result['mail_notice'] = $sent
            ? 'Correo de incidencia enviado al responsable de soporte TI.'
            : 'No se pudo enviar el correo de incidencia (revisa configuración SMTP).';
    }
    calendar_api_response($result, !empty($result['ok']) ? 200 : 422);
}

if ($action === 'save_reservation') {
    $room = calendar_normalize_room(isset($input['room']) ? $input['room'] : 'basica');
    $date = isset($input['date']) ? (string) $input['date'] : '';
    $status = calendar_normalize_status(isset($input['status']) ? $input['status'] : 'disponible');
    $responsable = trim((string) ($input['responsable_label'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $version = isset($input['version']) ? (int) $input['version'] : 0;
    $sendEmail = !empty($input['send_email']);

    if (!calendar_is_valid_date_key($date)) {
        calendar_api_response(array('ok' => false, 'message' => 'La fecha es inválida.'), 422);
    }

    list(, , $result) = calendar_store_mutate(function (&$store) use ($user, $room, $date, $status, $responsable, $notes, $version, $sendEmail) {
        $email = admin_normalize_email($user['email']);
        $name = admin_user_display_name($user);
        $existing = calendar_get_reservation($store, $room, $date);
        $reservationKey = calendar_reservation_key($room, $date);
        $emptyIntent = $status === 'disponible' && $responsable === '' && $notes === '';

        if (!$existing && $emptyIntent) {
            return array('ok' => true, 'reservation' => null, 'message' => 'Sin cambios.');
        }

        if ($existing) {
            $ownerEmail = admin_normalize_email($existing['owner_email'] ?? '');
            $canOverride = calendar_user_can_override($user);

            if (!$canOverride && $ownerEmail !== $email) {
                return array(
                    'ok' => false,
                    'code' => 'owner_locked',
                    'message' => 'Este día ya fue reservado por otro docente. Debes solicitar un cambio.',
                    'reservation' => $existing,
                );
            }

            if ($version > 0 && (int) ($existing['version'] ?? 0) !== $version) {
                return array(
                    'ok' => false,
                    'code' => 'version_conflict',
                    'message' => 'El registro cambió mientras lo estabas editando. Recarga para ver la última versión.',
                    'reservation' => $existing,
                );
            }

            if ($emptyIntent) {
                calendar_remove_reservation($store, $room, $date);
                calendar_append_audit($store, 'delete', $email, $reservationKey, $existing, null);
                $mailSent = false;
                if ($sendEmail) {
                    $mailSent = calendar_api_send_reservation_notice(
                        $email,
                        $name,
                        $name,
                        $existing,
                        'Reserva liberada en calendario de sala de computación',
                        'Tu reserva fue liberada del calendario privado.'
                    );
                }
                return array('ok' => true, 'reservation' => null, 'message' => 'Reserva liberada.', 'mail_sent' => $mailSent);
            }

            $updated = $existing;
            $updated['status'] = $status;
            $updated['responsable_label'] = $responsable;
            $updated['notes'] = $notes;
            $updated['updated_at'] = date('c');
            $updated['updated_by'] = $email;
            $updated['updated_by_name'] = $name;
            $updated['version'] = (int) ($existing['version'] ?? 0) + 1;

            if (calendar_user_can_override($user) && $ownerEmail !== $email) {
                $updated['last_forced_override_by'] = $email;
                $updated['last_forced_override_at'] = date('c');
                calendar_append_audit($store, 'force_override', $email, $reservationKey, $existing, $updated);
            } else {
                calendar_append_audit($store, 'update', $email, $reservationKey, $existing, $updated);
            }

            calendar_set_reservation($store, $room, $date, $updated);
            $mailSent = false;
            if ($sendEmail) {
                $mailSent = calendar_api_send_reservation_notice(
                    $email,
                    $name,
                    $name,
                    $updated,
                    'Reserva actualizada en calendario de sala de computación',
                    'Tu reserva fue actualizada en el calendario privado.'
                );
                if (calendar_user_can_override($user) && $ownerEmail !== $email) {
                    calendar_api_send_reservation_notice(
                        $ownerEmail,
                        $existing['owner_name'] ?? $ownerEmail,
                        $name,
                        $updated,
                        'Tu reserva fue ajustada por un responsable del panel',
                        'Una reserva que estaba a tu nombre fue ajustada desde el panel administrativo.'
                    );
                }
            }
            return array('ok' => true, 'reservation' => $updated, 'message' => 'Reserva actualizada.', 'mail_sent' => $mailSent);
        }

        $store['meta']['last_reservation_id'] = (int) ($store['meta']['last_reservation_id'] ?? 0) + 1;
        $created = array(
            'id' => $store['meta']['last_reservation_id'],
            'date' => $date,
            'room' => $room,
            'status' => $status,
            'owner_email' => $email,
            'owner_name' => $name,
            'responsable_label' => $responsable,
            'notes' => $notes,
            'version' => 1,
            'is_locked' => true,
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'created_by' => $email,
            'updated_by' => $email,
            'updated_by_name' => $name,
        );
        calendar_set_reservation($store, $room, $date, $created);
        calendar_append_audit($store, 'create', $email, $reservationKey, null, $created);
        $mailSent = false;
        if ($sendEmail) {
            $mailSent = calendar_api_send_reservation_notice(
                $email,
                $name,
                $name,
                $created,
                'Reserva creada en calendario de sala de computación',
                'Se registró una nueva reserva en el calendario privado.'
            );
        }
        return array('ok' => true, 'reservation' => $created, 'message' => 'Reserva guardada.', 'mail_sent' => $mailSent);
    });

    calendar_api_response($result, !empty($result['ok']) ? 200 : 409);
}

if ($action === 'request_change') {
    $room = calendar_normalize_room(isset($input['room']) ? $input['room'] : 'basica');
    $date = isset($input['date']) ? (string) $input['date'] : '';
    $status = calendar_normalize_status(isset($input['status']) ? $input['status'] : 'reservada');
    $responsable = trim((string) ($input['responsable_label'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $reason = trim((string) ($input['reason'] ?? ''));
    $sendEmail = !empty($input['send_email']);

    if (!calendar_is_valid_date_key($date)) {
        calendar_api_response(array('ok' => false, 'message' => 'La fecha es inválida.'), 422);
    }

    list(, , $result) = calendar_store_mutate(function (&$store) use ($user, $room, $date, $status, $responsable, $notes, $reason, $sendEmail) {
        $email = admin_normalize_email($user['email']);
        $existing = calendar_get_reservation($store, $room, $date);
        if (!$existing) {
            return array('ok' => false, 'message' => 'Ya no existe una reserva para este día.');
        }

        $ownerEmail = admin_normalize_email($existing['owner_email'] ?? '');
        if ($ownerEmail === $email) {
            return array('ok' => false, 'message' => 'No necesitas solicitar cambio sobre tu propia reserva.');
        }

        foreach ($store['change_requests'] as $request) {
            if (!is_array($request)) {
                continue;
            }
            if (($request['room'] ?? '') === $room
                && ($request['date'] ?? '') === $date
                && ($request['requested_by_email'] ?? '') === $email
                && ($request['approval_status'] ?? '') === 'pendiente') {
                return array('ok' => false, 'message' => 'Ya tienes una solicitud pendiente para este día.');
            }
        }

        $store['meta']['last_change_request_id'] = (int) ($store['meta']['last_change_request_id'] ?? 0) + 1;
        $request = array(
            'id' => $store['meta']['last_change_request_id'],
            'room' => $room,
            'date' => $date,
            'reservation_id' => $existing['id'] ?? null,
            'owner_email' => $ownerEmail,
            'owner_name' => $existing['owner_name'] ?? $ownerEmail,
            'requested_by_email' => $email,
            'requested_by_name' => admin_user_display_name($user),
            'requested_status' => $status,
            'requested_responsable_label' => $responsable,
            'requested_notes' => $notes,
            'reason' => $reason,
            'approval_status' => 'pendiente',
            'created_at' => date('c'),
        );
        $store['change_requests'][] = $request;
        calendar_append_audit($store, 'request_change', $email, calendar_reservation_key($room, $date), $existing, $request);
        $mailSent = false;
        if ($sendEmail) {
            $mailSent = calendar_api_send_change_request_notice($ownerEmail, $existing['owner_name'] ?? $ownerEmail, $request);
        }
        return array('ok' => true, 'request' => $request, 'message' => 'Solicitud enviada al propietario de la reserva.', 'mail_sent' => $mailSent);
    });

    calendar_api_response($result, !empty($result['ok']) ? 200 : 409);
}

if ($action === 'respond_request') {
    $requestId = isset($input['request_id']) ? (int) $input['request_id'] : 0;
    $decision = strtolower(trim((string) ($input['decision'] ?? '')));
    $sendEmail = !empty($input['send_email']);
    if (!in_array($decision, array('approve', 'reject'), true)) {
        calendar_api_response(array('ok' => false, 'message' => 'Decisión inválida.'), 422);
    }

    list(, , $result) = calendar_store_mutate(function (&$store) use ($user, $requestId, $decision, $sendEmail) {
        $email = admin_normalize_email($user['email']);
        $canOverride = calendar_user_can_override($user);

        foreach ($store['change_requests'] as $index => $request) {
            if (!is_array($request) || (int) ($request['id'] ?? 0) !== $requestId) {
                continue;
            }

            if (($request['approval_status'] ?? '') !== 'pendiente') {
                return array('ok' => false, 'message' => 'La solicitud ya fue resuelta.');
            }

            $ownerEmail = admin_normalize_email($request['owner_email'] ?? '');
            if (!$canOverride && $ownerEmail !== $email) {
                return array('ok' => false, 'message' => 'No tienes permiso para responder esta solicitud.');
            }

            $request['approval_status'] = $decision === 'approve' ? 'aprobada' : 'rechazada';
            $request['approved_by_email'] = $email;
            $request['approved_by_name'] = admin_user_display_name($user);
            $request['approved_at'] = date('c');
            $store['change_requests'][$index] = $request;

            $reservation = calendar_get_reservation($store, $request['room'], $request['date']);
            if ($decision === 'approve' && $reservation) {
                $oldPayload = $reservation;
                if (($request['requested_status'] ?? '') === 'liberar') {
                    calendar_remove_reservation($store, $request['room'], $request['date']);
                    calendar_append_audit($store, 'approve_change', $email, calendar_reservation_key($request['room'], $request['date']), $oldPayload, null);
                } else {
                    $reservation['status'] = calendar_normalize_status($request['requested_status'] ?? 'reservada');
                    $reservation['responsable_label'] = (string) ($request['requested_responsable_label'] ?? '');
                    $reservation['notes'] = (string) ($request['requested_notes'] ?? '');
                    $reservation['owner_email'] = (string) ($request['requested_by_email'] ?? $reservation['owner_email']);
                    $reservation['owner_name'] = (string) ($request['requested_by_name'] ?? $reservation['owner_name']);
                    $reservation['updated_at'] = date('c');
                    $reservation['updated_by'] = $email;
                    $reservation['updated_by_name'] = admin_user_display_name($user);
                    $reservation['version'] = (int) ($reservation['version'] ?? 0) + 1;
                    calendar_set_reservation($store, $request['room'], $request['date'], $reservation);
                    calendar_append_audit($store, 'approve_change', $email, calendar_reservation_key($request['room'], $request['date']), $oldPayload, $reservation);
                }
            } else {
                calendar_append_audit($store, 'reject_change', $email, calendar_reservation_key($request['room'], $request['date']), null, $request);
            }

            $mailSent = false;
            if ($sendEmail) {
                $mailSent = calendar_api_send_request_result_notice($request, $decision);
            }

            return array('ok' => true, 'request' => $request, 'message' => $decision === 'approve' ? 'Solicitud aprobada.' : 'Solicitud rechazada.', 'mail_sent' => $mailSent);
        }

        return array('ok' => false, 'message' => 'No se encontró la solicitud.');
    });

    calendar_api_response($result, !empty($result['ok']) ? 200 : 404);
}

if ($action === 'save_holiday') {
    if (!calendar_user_can_manage_holidays($user)) {
        calendar_api_response(array('ok' => false, 'message' => 'Solo coordinación, directivos o admin pueden modificar días especiales.'), 403);
    }

    $date = isset($input['date']) ? (string) $input['date'] : '';
    $label = trim((string) ($input['label'] ?? ''));
    if (!calendar_is_valid_date_key($date) || $label === '') {
        calendar_api_response(array('ok' => false, 'message' => 'Completa la fecha y el motivo.'), 422);
    }

    $year = substr($date, 0, 4);
    list(, $store, $result) = calendar_store_mutate(function (&$store) use ($user, $date, $label, $year) {
        if (!isset($store['custom_holidays'][(string) $year]) || !is_array($store['custom_holidays'][(string) $year])) {
            $store['custom_holidays'][(string) $year] = array();
        }
        $store['custom_holidays'][(string) $year][$date] = array(
            'date' => $date,
            'label' => $label,
            'created_by' => admin_normalize_email($user['email']),
            'created_by_name' => admin_user_display_name($user),
            'updated_at' => date('c'),
        );
        return array('ok' => true, 'message' => 'Día especial guardado.');
    });

    calendar_api_response(array_merge($result, array('custom_holidays' => $store['custom_holidays'][(string) $year])), 200);
}

if ($action === 'remove_holiday') {
    if (!calendar_user_can_manage_holidays($user)) {
        calendar_api_response(array('ok' => false, 'message' => 'Solo coordinación, directivos o admin pueden modificar días especiales.'), 403);
    }

    $date = isset($input['date']) ? (string) $input['date'] : '';
    if (!calendar_is_valid_date_key($date)) {
        calendar_api_response(array('ok' => false, 'message' => 'La fecha es inválida.'), 422);
    }

    $year = substr($date, 0, 4);
    list(, $store, $result) = calendar_store_mutate(function (&$store) use ($year, $date) {
        if (isset($store['custom_holidays'][(string) $year][$date])) {
            unset($store['custom_holidays'][(string) $year][$date]);
        }
        return array('ok' => true, 'message' => 'Día especial eliminado.');
    });

    $holidays = isset($store['custom_holidays'][(string) $year]) ? $store['custom_holidays'][(string) $year] : array();
    calendar_api_response(array_merge($result, array('custom_holidays' => $holidays)), 200);
}

if ($action === 'import_period') {
    if (!calendar_user_can_override($user)) {
        calendar_api_response(array('ok' => false, 'message' => 'Solo admin, directivos o coordinación pueden importar respaldos.'), 403);
    }

    $payload = isset($input['payload']) && is_array($input['payload']) ? $input['payload'] : null;
    if (!$payload) {
        calendar_api_response(array('ok' => false, 'message' => 'No llegó ningún respaldo válido.'), 422);
    }

    $year = isset($payload['year']) ? (int) $payload['year'] : (int) date('Y');
    $room = calendar_normalize_room(isset($payload['room']) ? $payload['room'] : 'basica');
    $semester = calendar_normalize_semester(isset($payload['semester']) ? $payload['semester'] : 's1');
    $reservations = isset($payload['reservations']) && is_array($payload['reservations']) ? $payload['reservations'] : array();
    $customHolidays = isset($payload['custom_holidays']) && is_array($payload['custom_holidays']) ? $payload['custom_holidays'] : array();

    list(, , $result) = calendar_store_mutate(function (&$store) use ($user, $year, $room, $semester, $reservations, $customHolidays) {
        $email = admin_normalize_email($user['email']);

        foreach ($reservations as $date => $reservation) {
            if (!calendar_is_valid_date_key($date) || !calendar_date_in_semester($date, $year, $semester)) {
                continue;
            }
            $current = calendar_get_reservation($store, $room, $date);
            $status = calendar_normalize_status($reservation['status'] ?? 'reservada');
            $responsable = trim((string) ($reservation['responsable_label'] ?? ''));
            $notes = trim((string) ($reservation['notes'] ?? ''));

            if ($status === 'disponible' && $responsable === '' && $notes === '') {
                calendar_remove_reservation($store, $room, $date);
                continue;
            }

            if (!$current) {
                $store['meta']['last_reservation_id'] = (int) ($store['meta']['last_reservation_id'] ?? 0) + 1;
                $current = array(
                    'id' => $store['meta']['last_reservation_id'],
                    'date' => $date,
                    'room' => $room,
                    'created_at' => date('c'),
                    'created_by' => $email,
                );
            }

            $current['status'] = $status;
            $current['responsable_label'] = $responsable;
            $current['notes'] = $notes;
            $current['owner_email'] = $reservation['owner_email'] ?? $email;
            $current['owner_name'] = $reservation['owner_name'] ?? admin_user_display_name($user);
            $current['updated_at'] = date('c');
            $current['updated_by'] = $email;
            $current['updated_by_name'] = admin_user_display_name($user);
            $current['version'] = (int) ($current['version'] ?? 0) + 1;
            $current['is_locked'] = true;
            calendar_set_reservation($store, $room, $date, $current);
        }

        if (!isset($store['custom_holidays'][(string) $year]) || !is_array($store['custom_holidays'][(string) $year])) {
            $store['custom_holidays'][(string) $year] = array();
        }
        foreach ($customHolidays as $date => $holiday) {
            if (!calendar_is_valid_date_key($date) || !calendar_date_in_semester($date, $year, $semester)) {
                continue;
            }
            $store['custom_holidays'][(string) $year][$date] = array(
                'date' => $date,
                'label' => trim((string) ($holiday['label'] ?? 'Jornada interna')),
                'created_by' => $email,
                'created_by_name' => admin_user_display_name($user),
                'updated_at' => date('c'),
            );
        }

        return array('ok' => true, 'message' => 'Respaldo importado al período actual.');
    });

    calendar_api_response($result, 200);
}

// =========================================================
// === list_incidences =====================================
// =========================================================
if ($action === 'list_incidences') {
    $store      = calendar_store_read_all();
    $role       = admin_user_role($user);
    $email      = admin_normalize_email($user['email']);
    $canSeeAll  = in_array($role, array('admin', 'coordinacion', 'directivo'), true);

    $incidences = array();
    foreach ($store['incidences'] as $inc) {
        if (!is_array($inc)) {
            continue;
        }
        // Docentes solo ven sus propias incidencias
        if (!$canSeeAll && ($inc['reporter_email'] ?? '') !== $email) {
            continue;
        }
        $incidences[] = $inc;
    }

    // Orden: más reciente primero
    usort($incidences, function ($a, $b) {
        return ($b['id'] ?? 0) - ($a['id'] ?? 0);
    });

    calendar_api_response(array(
        'ok'         => true,
        'incidences' => $incidences,
    ));
}

// =========================================================
// === update_incidence_status =============================
// =========================================================
if ($action === 'update_incidence_status' && $method === 'POST') {
    $input  = calendar_api_input();

    if (!isset($input['csrf_token']) || !admin_validate_csrf($input['csrf_token'])) {
        calendar_api_response(array('ok' => false, 'message' => 'Token CSRF inválido.'), 403);
    }

    $role = admin_user_role($user);
    if (!in_array($role, array('admin', 'coordinacion', 'directivo'), true)) {
        calendar_api_response(array('ok' => false, 'message' => 'Sin permiso para cambiar estado de incidencias.'), 403);
    }

    $incId    = (int) ($input['inc_id'] ?? 0);
    $newStatus = strtolower(trim((string) ($input['status'] ?? '')));
    $note     = trim((string) ($input['resolution_note'] ?? ''));

    $validStatuses = array('abierta', 'en_proceso', 'resuelta');
    if (!$incId) {
        calendar_api_response(array('ok' => false, 'message' => 'ID de incidencia inválido.'), 422);
    }
    if (!in_array($newStatus, $validStatuses, true)) {
        calendar_api_response(array('ok' => false, 'message' => 'Estado inválido.'), 422);
    }

    list($ok, , $result) = calendar_store_mutate(function (&$store) use ($incId, $newStatus, $note, $user) {
        $found = false;
        foreach ($store['incidences'] as &$inc) {
            if (!is_array($inc)) {
                continue;
            }
            if (($inc['id'] ?? 0) === $incId) {
                $inc['status']          = $newStatus;
                $inc['updated_at']      = date('c');
                $inc['updated_by']      = admin_normalize_email($user['email']);
                $inc['updated_by_name'] = admin_user_display_name($user);
                if ($note !== '') {
                    $inc['resolution_note'] = $note;
                }
                $found = true;
                break;
            }
        }
        unset($inc);

        if (!$found) {
            return array('ok' => false, 'message' => 'Incidencia no encontrada.', 'persist' => false);
        }

        return array('ok' => true);
    });

    if (!$ok) {
        calendar_api_response(array('ok' => false, 'message' => 'No se pudo guardar.'), 500);
    }

    calendar_api_response($result);
}

calendar_api_response(array('ok' => false, 'message' => 'Acción desconocida.'), 404);
