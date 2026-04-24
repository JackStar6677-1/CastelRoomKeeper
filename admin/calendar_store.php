<?php
require_once __DIR__ . '/auth.php';

function calendar_store_path()
{
    return __DIR__ . '/../data/calendar_store.json';
}

function calendar_store_default()
{
    return array(
        'version' => 2,
        'meta' => array(
            'last_reservation_id' => 0,
            'last_change_request_id' => 0,
            'last_block_id' => 0,
            'last_block_change_request_id' => 0,
            'last_incidence_id' => 0,
        ),
        'reservations' => array(),
        'custom_holidays' => array(),
        'change_requests' => array(),
        'block_reservations' => array(),
        'block_change_requests' => array(),
        'course_rosters' => array(),
        'incidences' => array(),
        'audit_log' => array(),
    );
}

function calendar_store_ensure_file()
{
    $path = calendar_store_path();
    if (file_exists($path)) {
        return;
    }

    file_put_contents(
        $path,
        json_encode(calendar_store_default(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function calendar_store_read_all()
{
    calendar_store_ensure_file();
    $path = calendar_store_path();
    $handle = fopen($path, 'rb');
    if (!$handle) {
        return calendar_store_default();
    }

    flock($handle, LOCK_SH);
    $raw = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_replace_recursive(calendar_store_default(), $decoded) : calendar_store_default();
}

function calendar_store_mutate($callback)
{
    calendar_store_ensure_file();
    $path = calendar_store_path();
    $handle = fopen($path, 'c+');
    if (!$handle) {
        return array(false, calendar_store_default(), array('ok' => false, 'message' => 'No se pudo abrir el almacenamiento.'));
    }

    flock($handle, LOCK_EX);
    rewind($handle);
    $raw = stream_get_contents($handle);
    $store = json_decode($raw, true);
    if (!is_array($store)) {
        $store = calendar_store_default();
    } else {
        $store = array_replace_recursive(calendar_store_default(), $store);
    }

    $result = call_user_func_array($callback, array(&$store));
    if (!is_array($result)) {
        $result = array('ok' => true);
    }

    if (!array_key_exists('persist', $result) || $result['persist'] !== false) {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($handle);
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return array(true, $store, $result);
}

function calendar_normalize_room($room)
{
    $room = strtolower(trim((string) $room));
    return in_array($room, array('basica', 'media'), true) ? $room : 'basica';
}

function calendar_normalize_semester($semester)
{
    $semester = strtolower(trim((string) $semester));
    return in_array($semester, array('s1', 's2'), true) ? $semester : 's1';
}

function calendar_normalize_status($status)
{
    $status = strtolower(trim((string) $status));
    return in_array($status, array('disponible', 'reservada', 'mantenimiento', 'bloqueada', 'liberar'), true) ? $status : 'disponible';
}

function calendar_is_valid_date_key($date)
{
    return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
}

function calendar_get_semester_bounds($year, $semester)
{
    $year = (int) $year;
    $semester = calendar_normalize_semester($semester);

    if ($semester === 's1') {
        return array(
            'start' => sprintf('%04d-03-01', $year),
            'end' => sprintf('%04d-07-31', $year),
        );
    }

    return array(
        'start' => sprintf('%04d-08-01', $year),
        'end' => sprintf('%04d-12-31', $year),
    );
}

function calendar_date_in_semester($date, $year, $semester)
{
    if (!calendar_is_valid_date_key($date)) {
        return false;
    }

    $bounds = calendar_get_semester_bounds($year, $semester);
    return $date >= $bounds['start'] && $date <= $bounds['end'];
}

function calendar_reservation_key($room, $date)
{
    return calendar_normalize_room($room) . ':' . $date;
}

function calendar_get_reservation($store, $room, $date)
{
    $key = calendar_reservation_key($room, $date);
    return isset($store['reservations'][$key]) && is_array($store['reservations'][$key]) ? $store['reservations'][$key] : null;
}

function calendar_set_reservation(&$store, $room, $date, $payload)
{
    $store['reservations'][calendar_reservation_key($room, $date)] = $payload;
}

function calendar_remove_reservation(&$store, $room, $date)
{
    unset($store['reservations'][calendar_reservation_key($room, $date)]);
}

function calendar_append_audit(&$store, $actionType, $performedBy, $reservationKey, $oldPayload, $newPayload)
{
    $store['audit_log'][] = array(
        'id' => count($store['audit_log']) + 1,
        'reservation_key' => $reservationKey,
        'action_type' => $actionType,
        'performed_by' => $performedBy,
        'old_payload' => $oldPayload,
        'new_payload' => $newPayload,
        'created_at' => date('c'),
    );
}

function calendar_user_can_override($user)
{
    return admin_user_has_calendar_override($user);
}

function calendar_user_can_manage_holidays($user)
{
    return admin_user_can_manage_holidays($user);
}

function calendar_store_export_period($store, $year, $room, $semester)
{
    $room = calendar_normalize_room($room);
    $semester = calendar_normalize_semester($semester);
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

    $customHolidays = array();
    if (isset($store['custom_holidays'][(string) $year]) && is_array($store['custom_holidays'][(string) $year])) {
        foreach ($store['custom_holidays'][(string) $year] as $date => $holiday) {
            if (calendar_date_in_semester($date, $year, $semester)) {
                $customHolidays[$date] = $holiday;
            }
        }
    }

    return array(
        'version' => 2,
        'exported_at' => date('c'),
        'year' => (int) $year,
        'room' => $room,
        'semester' => $semester,
        'reservations' => $reservations,
        'custom_holidays' => $customHolidays,
    );
}

// =========================================================
// === Funciones de Bloques Horarios =======================
// =========================================================

function calendar_normalize_slot_id($slotId)
{
    $slotId = strtolower(trim((string) $slotId));
    return in_array($slotId, array('b1', 'b2', 'b3', 'b4', 'b5', 'r1', 'r2', 'r3', 'a1'), true) ? $slotId : '';
}

function calendar_block_catalog()
{
    return array(
        array('slot_id' => 'b1', 'id' => 1, 'nombre' => 'Bloque 1', 'hora_inicio' => '08:00', 'hora_fin' => '09:30', 'tipo' => 'clase', 'es_bloqueado' => false),
        array('slot_id' => 'r1', 'id' => 4, 'nombre' => 'Recreo 1', 'hora_inicio' => '09:30', 'hora_fin' => '09:45', 'tipo' => 'recreo', 'es_bloqueado' => true),
        array('slot_id' => 'b2', 'id' => 2, 'nombre' => 'Bloque 2', 'hora_inicio' => '09:45', 'hora_fin' => '11:15', 'tipo' => 'clase', 'es_bloqueado' => false),
        array('slot_id' => 'r2', 'id' => 5, 'nombre' => 'Recreo 2', 'hora_inicio' => '11:15', 'hora_fin' => '11:30', 'tipo' => 'recreo', 'es_bloqueado' => true),
        array('slot_id' => 'b3', 'id' => 3, 'nombre' => 'Bloque 3', 'hora_inicio' => '11:30', 'hora_fin' => '13:00', 'tipo' => 'clase', 'es_bloqueado' => false),
        array('slot_id' => 'a1', 'id' => 6, 'nombre' => 'Almuerzo', 'hora_inicio' => '13:00', 'hora_fin' => '14:00', 'tipo' => 'almuerzo', 'es_bloqueado' => true),
        array('slot_id' => 'b4', 'id' => 7, 'nombre' => 'Bloque 4', 'hora_inicio' => '14:00', 'hora_fin' => '15:30', 'tipo' => 'clase', 'es_bloqueado' => false),
        array('slot_id' => 'r3', 'id' => 9, 'nombre' => 'Recreo 3', 'hora_inicio' => '15:30', 'hora_fin' => '15:45', 'tipo' => 'recreo', 'es_bloqueado' => true),
        array('slot_id' => 'b5', 'id' => 8, 'nombre' => 'Bloque 5', 'hora_inicio' => '15:45', 'hora_fin' => '17:15', 'tipo' => 'clase', 'es_bloqueado' => false),
    );
}

function calendar_block_meta($slotId)
{
    $slotId = calendar_normalize_slot_id($slotId);
    if ($slotId === '') {
        return null;
    }
    foreach (calendar_block_catalog() as $slot) {
        if (($slot['slot_id'] ?? '') === $slotId) {
            return $slot;
        }
    }
    return null;
}

function calendar_block_key($room, $date, $slotId)
{
    return calendar_normalize_room($room) . ':' . $date . ':' . $slotId;
}

function calendar_get_block($store, $room, $date, $slotId)
{
    $key = calendar_block_key($room, $date, $slotId);
    return isset($store['block_reservations'][$key]) && is_array($store['block_reservations'][$key])
        ? $store['block_reservations'][$key]
        : null;
}

function calendar_set_block(&$store, $room, $date, $slotId, $payload)
{
    $store['block_reservations'][calendar_block_key($room, $date, $slotId)] = $payload;
}

function calendar_remove_block(&$store, $room, $date, $slotId)
{
    unset($store['block_reservations'][calendar_block_key($room, $date, $slotId)]);
}

function calendar_get_blocks_for_day($store, $room, $date)
{
    $room   = calendar_normalize_room($room);
    $prefix = $room . ':' . $date . ':';
    $blocks = array();
    foreach ($store['block_reservations'] as $key => $block) {
        if (strpos($key, $prefix) !== 0 || !is_array($block)) {
            continue;
        }
        $slotId = $block['slot_id'] ?? '';
        if ($slotId !== '') {
            $blocks[$slotId] = $block;
        }
    }
    return $blocks;
}

function calendar_get_block_summaries($store, $room, $year, $semester)
{
    $room      = calendar_normalize_room($room);
    $prefix    = $room . ':';
    $summaries = array();
    foreach ($store['block_reservations'] as $key => $block) {
        if (strpos($key, $prefix) !== 0 || !is_array($block)) {
            continue;
        }
        $date = $block['date'] ?? '';
        if (!calendar_date_in_semester($date, $year, $semester)) {
            continue;
        }
        $slotMeta = calendar_block_meta($block['slot_id'] ?? '');
        if (!$slotMeta || !empty($slotMeta['es_bloqueado'])) {
            continue;
        }
        $status = calendar_normalize_status($block['status'] ?? 'disponible');
        if ($status !== 'disponible') {
            $summaries[$date] = ($summaries[$date] ?? 0) + 1;
        }
    }
    return $summaries;
}

function calendar_get_block_pending_requests($store, $user, $year, $room, $month = null)
{
    $email      = admin_normalize_email($user['email']);
    $canOverride = calendar_user_can_override($user);
    $items      = array();
    $monthPrefix = null;
    if ($month !== null) {
        $month = (int) $month;
        if ($month >= 1 && $month <= 12) {
            $monthPrefix = sprintf('%04d-%02d-', (int) $year, $month);
        }
    }
    foreach ($store['block_change_requests'] as $request) {
        if (!is_array($request)) {
            continue;
        }
        if (($request['approval_status'] ?? '') !== 'pendiente') {
            continue;
        }
        if (($request['room'] ?? '') !== $room) {
            continue;
        }
        if (substr($request['date'] ?? '', 0, 4) !== (string) $year) {
            continue;
        }
        if ($monthPrefix !== null && strpos((string) ($request['date'] ?? ''), $monthPrefix) !== 0) {
            continue;
        }
        if (!$canOverride
            && $email !== ($request['owner_email'] ?? '')
            && $email !== ($request['requested_by_email'] ?? '')) {
            continue;
        }
        $items[] = $request;
    }
    usort($items, function ($a, $b) {
        return strcmp(
            ($a['date'] ?? '') . ($a['slot_id'] ?? ''),
            ($b['date'] ?? '') . ($b['slot_id'] ?? '')
        );
    });
    return $items;
}
