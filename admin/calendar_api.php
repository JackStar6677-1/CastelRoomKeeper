<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/calendar_store.php';
require_once __DIR__ . '/mailer.php';

admin_bootstrap_session();
admin_require_login();

header('Content-Type: application/json; charset=UTF-8');

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

function calendar_api_send_mail($to, $subject, $body)
{
    $to = admin_normalize_email($to);
    if ($to === '') {
        return false;
    }

    $error = null;
    return castel_mailer_send($to, $subject, $body, $error);
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

    $body = implode("\n", array(
        'Hola ' . ($targetName !== '' ? $targetName : $targetEmail) . ',',
        '',
        $messageIntro,
        '',
        'Fecha: ' . $date,
        'Sala: ' . $room,
        'Estado: ' . $status,
        'Responsable / curso: ' . $responsable,
        'Observaciones: ' . $notes,
        'Registrado por: ' . $actorName,
        '',
        'Puedes revisar el calendario privado en:',
        'https://www.colegiocastelgandolfo.cl/admin/calendar.php',
        '',
        'Mensaje automático del panel privado del Colegio Castelgandolfo.'
    ));

    return calendar_api_send_mail($targetEmail, $messageTitle, $body);
}

function calendar_api_send_change_request_notice($ownerEmail, $ownerName, $request)
{
    $body = implode("\n", array(
        'Hola ' . ($ownerName !== '' ? $ownerName : $ownerEmail) . ',',
        '',
        'Un docente solicitó modificar una reserva que hoy está a tu nombre en el calendario de la sala de computación.',
        '',
        'Fecha: ' . ($request['date'] ?? ''),
        'Sala: ' . calendar_api_room_label($request['room'] ?? 'basica'),
        'Solicitante: ' . ($request['requested_by_name'] ?? $request['requested_by_email'] ?? ''),
        'Estado solicitado: ' . calendar_api_status_label($request['requested_status'] ?? 'reservada'),
        'Responsable propuesto: ' . (($request['requested_responsable_label'] ?? '') !== '' ? $request['requested_responsable_label'] : 'Sin detalle'),
        'Observaciones propuestas: ' . (($request['requested_notes'] ?? '') !== '' ? $request['requested_notes'] : 'Sin observaciones.'),
        'Motivo: ' . (($request['reason'] ?? '') !== '' ? $request['reason'] : 'Sin motivo indicado.'),
        '',
        'Para aprobar o rechazar este cambio, entra al panel privado:',
        'https://www.colegiocastelgandolfo.cl/admin/calendar.php',
        '',
        'Mensaje automático del panel privado del Colegio Castelgandolfo.'
    ));

    return calendar_api_send_mail($ownerEmail, 'Solicitud de cambio en calendario de sala de computación', $body);
}

function calendar_api_send_request_result_notice($request, $decision)
{
    $approvedBy = $request['approved_by_name'] ?? $request['approved_by_email'] ?? 'Equipo del colegio';
    $body = implode("\n", array(
        'Hola ' . (($request['requested_by_name'] ?? '') !== '' ? $request['requested_by_name'] : ($request['requested_by_email'] ?? '')) . ',',
        '',
        $decision === 'approve'
            ? 'Tu solicitud de cambio fue aprobada.'
            : 'Tu solicitud de cambio fue rechazada.',
        '',
        'Fecha: ' . ($request['date'] ?? ''),
        'Sala: ' . calendar_api_room_label($request['room'] ?? 'basica'),
        'Estado solicitado: ' . calendar_api_status_label($request['requested_status'] ?? 'reservada'),
        'Responsable propuesto: ' . (($request['requested_responsable_label'] ?? '') !== '' ? $request['requested_responsable_label'] : 'Sin detalle'),
        'Respondió: ' . $approvedBy,
        '',
        'Puedes revisar el estado actualizado en:',
        'https://www.colegiocastelgandolfo.cl/admin/calendar.php',
        '',
        'Mensaje automático del panel privado del Colegio Castelgandolfo.'
    ));

    return calendar_api_send_mail(
        $request['requested_by_email'] ?? '',
        $decision === 'approve' ? 'Solicitud aprobada en calendario de sala de computación' : 'Solicitud rechazada en calendario de sala de computación',
        $body
    );
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
        'can_manage_holidays' => calendar_user_can_manage_holidays($user),
    );
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

$user = calendar_api_current_user();
$method = strtoupper($_SERVER['REQUEST_METHOD']);
$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
$input = $method === 'POST' ? calendar_api_input() : $_GET;

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

calendar_api_response(array('ok' => false, 'message' => 'Acción desconocida.'), 404);
