<?php
require_once __DIR__ . '/auth.php';

function calendar_store_path()
{
    return __DIR__ . '/../data/calendar_store.json';
}

function calendar_store_default()
{
    return array(
        'version' => 1,
        'meta' => array(
            'last_reservation_id' => 0,
            'last_change_request_id' => 0,
        ),
        'reservations' => array(),
        'custom_holidays' => array(),
        'change_requests' => array(),
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
