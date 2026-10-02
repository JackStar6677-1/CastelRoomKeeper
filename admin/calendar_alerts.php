<?php

/** Obtiene la ubicación de la configuración administrable de avisos TI. */
function calendar_alert_settings_path()
{
    return __DIR__ . '/config_time_slots.json';
}

/** Normaliza una configuración para que el worker no dependa de valores inseguros. */
function calendar_alert_settings_normalize($settings)
{
    $settings = is_array($settings) ? $settings : array();
    $email = admin_normalize_email($settings['recipient_email'] ?? '');
    $lead = (int) ($settings['lead_minutes'] ?? 10);

    return array(
        'enabled' => !empty($settings['enabled']),
        'recipient_email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
        'lead_minutes' => max(1, min(60, $lead)),
    );
}

/** Lee la configuración desde el mismo archivo que ya define los bloques horarios. */
function calendar_alert_settings_read()
{
    $path = calendar_alert_settings_path();
    $config = array();
    if (is_file($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }

    return calendar_alert_settings_normalize($config['avisos_inicio_clase'] ?? array());
}

/** Guarda la configuración de forma atómica para no dañar el catálogo de bloques. */
function calendar_alert_settings_save($settings)
{
    $path = calendar_alert_settings_path();
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return false;
    }

    $decoded['avisos_inicio_clase'] = calendar_alert_settings_normalize($settings);
    $payload = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $temp = $path . '.tmp.' . getmypid();
    if (file_put_contents($temp, $payload, LOCK_EX) === false) {
        return false;
    }

    if (!@rename($temp, $path)) {
        @unlink($temp);
        return false;
    }

    return true;
}

/** Formula la etiqueta de un bloque sin revelar datos personales de la reserva. */
function calendar_alert_slot_label($slot)
{
    return (string) ($slot['nombre'] ?? 'Bloque') . ' (' . (string) ($slot['hora_inicio'] ?? '') . ' a ' . (string) ($slot['hora_fin'] ?? '') . ')';
}

/** Encola, una única vez por reserva, los avisos TI de las clases que están por iniciar. */
function calendar_alerts_queue_due_class_notifications()
{
    $settings = calendar_alert_settings_read();
    $result = array('ok' => true, 'queued' => 0, 'skipped' => 0, 'failed' => 0, 'reason' => '');
    if (empty($settings['enabled']) || empty($settings['recipient_email'])) {
        $result['reason'] = 'disabled_or_missing_recipient';
        return $result;
    }

    $timezone = new DateTimeZone('America/Santiago');
    $now = new DateTimeImmutable('now', $timezone);
    $today = $now->format('Y-m-d');
    $leadSeconds = (int) $settings['lead_minutes'] * 60;
    $claimPrefix = 'class_start:';

    list($saved, $store, $claimResult) = calendar_store_mutate(function (&$store) use ($today, $now, $timezone, $leadSeconds, $claimPrefix) {
        $alerts = isset($store['meta']['class_start_alerts']) && is_array($store['meta']['class_start_alerts'])
            ? $store['meta']['class_start_alerts']
            : array();
        $retentionCutoff = $now->modify('-35 days')->getTimestamp();
        foreach ($alerts as $key => $alert) {
            $recordedAt = is_array($alert) ? strtotime((string) ($alert['queued_at'] ?? $alert['claimed_at'] ?? '')) : false;
            if ($recordedAt !== false && $recordedAt < $retentionCutoff) {
                unset($alerts[$key]);
            }
        }

        $jobs = array();
        foreach ($store['block_reservations'] as $reservation) {
            if (!is_array($reservation) || (string) ($reservation['date'] ?? '') !== $today) {
                continue;
            }
            if (calendar_normalize_status((string) ($reservation['status'] ?? '')) !== 'reservada') {
                continue;
            }
            $slot = calendar_block_meta((string) ($reservation['slot_id'] ?? ''));
            if (!$slot || !empty($slot['es_bloqueado'])) {
                continue;
            }

            $start = new DateTimeImmutable($today . ' ' . (string) $slot['hora_inicio'], $timezone);
            $secondsUntilStart = $start->getTimestamp() - $now->getTimestamp();
            if ($secondsUntilStart <= 0 || $secondsUntilStart > $leadSeconds) {
                continue;
            }

            $key = $claimPrefix . (string) ($reservation['room'] ?? '') . ':' . $today . ':' . (string) ($reservation['slot_id'] ?? '') . ':v' . (int) ($reservation['version'] ?? 1);
            $existing = $alerts[$key] ?? null;
            $claimedAt = is_array($existing) ? strtotime((string) ($existing['claimed_at'] ?? '')) : false;
            if (is_array($existing) && (($existing['state'] ?? '') === 'queued' || ($claimedAt !== false && $claimedAt > $now->modify('-5 minutes')->getTimestamp()))) {
                continue;
            }

            $claimToken = hash('sha256', $key . '|' . $now->format('c') . '|' . uniqid('', true));
            $alerts[$key] = array('state' => 'pending', 'claimed_at' => $now->format(DATE_ATOM), 'claim_token' => $claimToken);
            $jobs[] = array('key' => $key, 'claim_token' => $claimToken, 'reservation' => $reservation, 'slot' => $slot);
        }

        $store['meta']['class_start_alerts'] = $alerts;
        return array('ok' => true, 'jobs' => $jobs);
    });

    if (!$saved || empty($claimResult['ok'])) {
        $result['ok'] = false;
        $result['failed'] = 1;
        $result['reason'] = 'store_mutation_failed';
        admin_log_operation('calendar_class_alert', 'claim', 'failed', array(), 'No se pudieron registrar los avisos TI pendientes.');
        return $result;
    }

    $jobs = isset($claimResult['jobs']) && is_array($claimResult['jobs']) ? $claimResult['jobs'] : array();
    foreach ($jobs as $job) {
        $reservation = $job['reservation'];
        $slot = $job['slot'];
        $course = trim((string) ($reservation['curso'] ?? '') . ' ' . (string) ($reservation['curso_letra'] ?? ''));
        $room = calendar_api_room_label((string) ($reservation['room'] ?? ''));
        $subject = trim((string) ($reservation['asignatura'] ?? 'Clase'));
        $mailSubject = 'La clase ' . $subject . ' va a empezar';
        $body = "Aviso operativo de sala de computación.\n\n"
            . "La clase {$subject} comienza a las " . (string) $slot['hora_inicio'] . ".\n"
            . "Sala: {$room}\n"
            . "Bloque: " . calendar_alert_slot_label($slot) . "\n"
            . "Curso: " . ($course !== '' ? $course : 'Sin curso informado') . "\n"
            . "Docente responsable: " . ((string) ($reservation['docente'] ?? 'Sin informar')) . "\n"
            . "Fecha: " . (string) ($reservation['date'] ?? '') . "\n";
        $queued = calendar_api_send_mail($settings['recipient_email'], $mailSubject, $body);

        list($markSaved, $markStore, $markResult) = calendar_store_mutate(function (&$store) use ($job, $queued, $settings, $subject, $slot, $room, $course, $reservation) {
            $alerts = isset($store['meta']['class_start_alerts']) && is_array($store['meta']['class_start_alerts'])
                ? $store['meta']['class_start_alerts']
                : array();
            $current = $alerts[$job['key']] ?? null;
            if (is_array($current) && hash_equals((string) ($current['claim_token'] ?? ''), (string) $job['claim_token'])) {
                if ($queued) {
                    $current['state'] = 'queued';
                    $current['queued_at'] = date(DATE_ATOM);
                    unset($current['claim_token']);
                    $alerts[$job['key']] = $current;
                    if (function_exists('calendar_add_notification')) {
                        calendar_add_notification(
                            $store,
                            $settings['recipient_email'],
                            'aviso_clase',
                            'Clase por iniciar: ' . $subject,
                            "La clase {$subject} comienza a las " . (string) ($slot['hora_inicio'] ?? '') . " en {$room} (" . ($course !== '' ? $course : 'Sin curso') . ").",
                            array($room, (string) ($slot['hora_inicio'] ?? ''), (string) ($reservation['docente'] ?? ''))
                        );
                    }
                } else {
                    unset($alerts[$job['key']]);
                }
                $store['meta']['class_start_alerts'] = $alerts;
            }
            return array('ok' => true);
        });

        if (!$markSaved || empty($markResult['ok']) || !$queued) {
            $result['failed']++;
            continue;
        }
        $result['queued']++;
    }

    $result['skipped'] = max(0, count($store['block_reservations'] ?? array()) - count($jobs));
    if (!empty($jobs) || $result['failed']) {
        admin_log_operation('calendar_class_alert', 'queue', $result['failed'] ? 'partial' : 'ok', array(
            'queued' => $result['queued'], 'failed' => $result['failed'], 'recipient' => $settings['recipient_email'],
        ), 'Revisión única de avisos previos al inicio de clases.');
    }
    return $result;
}
