<?php
/**
 * Castelgandolfo - API de Telemetría para Widget de Escritorio (Salas de Computación)
 * Provee datos en tiempo real de ocupación de Sala Básica y Sala Media.
 */
date_default_timezone_set("America/Santiago");

require_once __DIR__ . "/calendar_store.php";

$token = isset($_GET["token"]) ? (string)$_GET["token"] : "";
$token_config_path = __DIR__ . "/calendar_widget_config.php";
$token_config = is_file($token_config_path) ? require $token_config_path : [];
$expected_token = is_array($token_config) ? (string)($token_config["token"] ?? "") : "";

if ($expected_token === "" || !hash_equals($expected_token, $token)) {
    http_response_code(403);
    echo json_encode(["ok" => false, "message" => "Acceso no autorizado"]);
    exit;
}

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

$store = calendar_store_read_all();

// Config de bloques horarios
$config_path = __DIR__ . "/config_time_slots.json";
$config = is_file($config_path) ? json_decode(file_get_contents($config_path), true) : [];
$time_slots = isset($config["bloques_horarios"]) ? $config["bloques_horarios"] : [];

$now = new DateTime("now", new DateTimeZone("America/Santiago"));
$currentTimeStr = $now->format("H:i");
$currentDateStr = $now->format("Y-m-d");

$requested_date = isset($_GET["date"]) && preg_match("/^\d{4}-\d{2}-\d{2}$/", $_GET["date"]) ? $_GET["date"] : $currentDateStr;

// Calcular semana (Lunes a Viernes de la fecha solicitada)
$refDate = new DateTime($requested_date, new DateTimeZone("America/Santiago"));
$dayOfWeek = (int)$refDate->format("N"); // 1 (Mon) to 7 (Sun)
$monday = clone $refDate;
$monday->modify("-" . ($dayOfWeek - 1) . " days");

$week_dates = [];
$week_days_es = ["Lunes", "Martes", "Miércoles", "Jueves", "Viernes"];
for ($i = 0; $i < 5; $i++) {
    $d = clone $monday;
    $d->modify("+$i days");
    $week_dates[$d->format("Y-m-d")] = [
        "date" => $d->format("Y-m-d"),
        "day_name" => $week_days_es[$i],
        "day_num" => (int)$d->format("d"),
        "month_num" => (int)$d->format("m"),
        "is_today" => ($d->format("Y-m-d") === $currentDateStr),
        "is_selected" => ($d->format("Y-m-d") === $requested_date)
    ];
}

// Lee el mismo estado transaccional canónico que utiliza calendar_api.php.
// Las tablas normalizadas quedaron como respaldo de migración en julio de 2026.
$startWeek = $monday->format("Y-m-d");
$friday = clone $monday;
$friday->modify("+4 days");
$endWeek = $friday->format("Y-m-d");

$all_week_reservations = [];
foreach (($store["block_reservations"] ?? []) as $row) {
    if (!is_array($row)) {
        continue;
    }

    $r = calendar_normalize_room($row["room"] ?? "");
    $dk = (string)($row["date"] ?? "");
    $slot = calendar_normalize_slot_id($row["slot_id"] ?? "");
    if ($dk < $startWeek || $dk > $endWeek || $slot === "") {
        continue;
    }

    if (!isset($all_week_reservations[$dk])) {
        $all_week_reservations[$dk] = ["basica" => [], "media" => []];
    }

    $all_week_reservations[$dk][$r][$slot] = [
        "slot_id" => $slot,
        "status" => calendar_normalize_status($row["status"] ?? "disponible"),
        "asignatura" => (string)($row["asignatura"] ?? ""),
        "curso" => (string)($row["curso"] ?? ""),
        "curso_letra" => (string)($row["curso_letra"] ?? ""),
        "docente" => (string)($row["docente"] ?? ""),
        "owner_name" => (string)($row["owner_name"] ?? ""),
        "notes" => (string)($row["notes"] ?? "")
    ];
}

$holidays = [];
$holiday_years = array_unique([substr($startWeek, 0, 4), substr($endWeek, 0, 4)]);
foreach ($holiday_years as $holiday_year) {
    foreach (($store["custom_holidays"][$holiday_year] ?? []) as $holiday_date => $holiday) {
        if ($holiday_date < $startWeek || $holiday_date > $endWeek) {
            continue;
        }
        $holidays[$holiday_date] = is_array($holiday)
            ? (string)($holiday["label"] ?? "Feriado")
            : (string)$holiday;
    }
}

// Determinar el bloque actual y el próximo (para la fecha de hoy)
$current_slot = null;
$next_slot = null;
$time_in_slot_sec = 0;
$slot_duration_sec = 0;
$slot_remaining_min = 0;

if ($requested_date === $currentDateStr) {
    foreach ($time_slots as $idx => $ts) {
        $start = $ts["hora_inicio"];
        $end = $ts["hora_fin"];
        
        if ($currentTimeStr >= $start && $currentTimeStr < $end) {
            $current_slot = $ts;
            // Next slot
            if (isset($time_slots[$idx + 1])) {
                $next_slot = $time_slots[$idx + 1];
            }
            // Calculate remaining
            $endDT = new DateTime($currentDateStr . " " . $end . ":00", new DateTimeZone("America/Santiago"));
            $slot_remaining_min = max(0, round(($endDT->getTimestamp() - $now->getTimestamp()) / 60));
            break;
        } elseif ($currentTimeStr < $start && $next_slot === null) {
            $next_slot = $ts;
        }
    }
}

// Procesar el día solicitado
$day_reservations = isset($all_week_reservations[$requested_date]) ? $all_week_reservations[$requested_date] : ["basica" => [], "media" => []];
$is_holiday = isset($holidays[$requested_date]);
$holiday_label = $is_holiday ? $holidays[$requested_date] : null;

function build_room_day_data($room_key, $room_label, $day_res, $time_slots, $current_slot) {
    $slots_data = [];
    $total_class_slots = 0;
    $reserved_count = 0;
    $is_occupied_now = false;
    $current_occupant = null;
    $next_booking = null;

    foreach ($time_slots as $ts) {
        $slot_id = $ts["slot_id"];
        $is_class = ($ts["tipo"] === "clase" && empty($ts["es_bloqueado"]));
        if ($is_class) {
            $total_class_slots++;
        }
        
        $res = isset($day_res[$room_key][$slot_id]) ? $day_res[$room_key][$slot_id] : null;
        $status = "disponible";
        $curso = "";
        $curso_letra = "";
        $asignatura = "";
        $docente = "";
        $notes = "";
        $owner_name = "";

        if ($res && !empty($res["status"])) {
            $status = $res["status"];
            $asignatura = (string)$res["asignatura"];
            $curso = (string)$res["curso"];
            $curso_letra = (string)$res["curso_letra"];
            $docente = (string)$res["docente"];
            $notes = (string)$res["notes"];
            $owner_name = (string)$res["owner_name"];
            if ($is_class && $status === "reservada") {
                $reserved_count++;
            }
        } elseif (!empty($ts["es_bloqueado"])) {
            $status = "bloqueada";
        }

        $slot_item = [
            "id" => $ts["id"],
            "slot_id" => $slot_id,
            "nombre" => $ts["nombre"],
            "hora_inicio" => $ts["hora_inicio"],
            "hora_fin" => $ts["hora_fin"],
            "tipo" => $ts["tipo"],
            "es_bloqueado" => !empty($ts["es_bloqueado"]),
            "status" => $status,
            "asignatura" => $asignatura,
            "curso" => $curso,
            "curso_letra" => $curso_letra,
            "docente" => $docente,
            "owner_name" => $owner_name,
            "notes" => $notes,
            "display_curso" => $curso ? ($curso . ($curso_letra ? " " . $curso_letra : "")) : ""
        ];

        if ($current_slot && $current_slot["slot_id"] === $slot_id) {
            $slot_item["is_current"] = true;
            if ($status === "reservada") {
                $is_occupied_now = true;
                $current_occupant = $slot_item;
            }
        } else {
            $slot_item["is_current"] = false;
        }

        $slots_data[] = $slot_item;
    }

    return [
        "room" => $room_key,
        "label" => $room_label,
        "is_occupied_now" => $is_occupied_now,
        "current_occupant" => $current_occupant,
        "total_class_slots" => $total_class_slots,
        "reserved_slots" => $reserved_count,
        "available_slots" => max(0, $total_class_slots - $reserved_count),
        "slots" => $slots_data
    ];
}

$rooms_data = [
    "basica" => build_room_day_data("basica", "Sala Básica", $day_reservations, $time_slots, $current_slot),
    "media" => build_room_day_data("media", "Sala Media", $day_reservations, $time_slots, $current_slot)
];

// Resumen semanal por día
$week_summary = [];
foreach ($week_dates as $dateStr => $winfo) {
    $dres = isset($all_week_reservations[$dateStr]) ? $all_week_reservations[$dateStr] : ["basica" => [], "media" => []];
    $b_count = 0;
    $m_count = 0;
    foreach ($dres["basica"] as $s) {
        if (($s["status"] ?? "") === "reservada") $b_count++;
    }
    foreach ($dres["media"] as $s) {
        if (($s["status"] ?? "") === "reservada") $m_count++;
    }
    
    $week_summary[] = array_merge($winfo, [
        "is_holiday" => isset($holidays[$dateStr]),
        "holiday_label" => isset($holidays[$dateStr]) ? $holidays[$dateStr] : null,
        "basica_reserved" => $b_count,
        "media_reserved" => $m_count,
        "total_reserved" => $b_count + $m_count
    ]);
}

echo json_encode([
    "ok" => true,
    "server_time" => $now->format("c"),
    "time_str" => $currentTimeStr,
    "date" => $requested_date,
    "is_today" => ($requested_date === $currentDateStr),
    "is_holiday" => $is_holiday,
    "holiday_label" => $holiday_label,
    "current_slot" => $current_slot ? array_merge($current_slot, ["remaining_min" => $slot_remaining_min]) : null,
    "next_slot" => $next_slot,
    "rooms" => $rooms_data,
    "week" => $week_summary
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
