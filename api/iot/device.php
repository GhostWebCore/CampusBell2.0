<?php

declare(strict_types=1);

header('Content-Type: application/json');

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        error_log('device.php fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
});

require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$deviceKey = trim($_SERVER['HTTP_X_DEVICE_KEY'] ?? '');
if ($deviceKey === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid Device Key']);
    exit;
}

$zone = trim((string)($_GET['zone'] ?? ''));
if ($zone === '') {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid zone parameter']);
    exit;
}

$pdo = get_db_connection();

// MySQL: is_active = 1  ?  PostgreSQL: is_active = TRUE
$stmtDevice = $pdo->prepare(
    'SELECT device_id
     FROM tbl_devices
     WHERE device_key = :key AND zone_code = :zone AND is_active = TRUE
     LIMIT 1'
);
$stmtDevice->execute([':key' => $deviceKey, ':zone' => $zone]);
$device = $stmtDevice->fetch();

if (!$device) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized device.']);
    exit;
}

try {
    // MySQL: NOW()  ?  PostgreSQL: NOW() (same, no change needed)
    $pdo->prepare('UPDATE tbl_devices SET last_seen_at = NOW() WHERE device_id = :id')
        ->execute([':id' => $device['device_id']]);
} catch (Throwable $e) {
    error_log('device.php last_seen_at update failed: ' . $e->getMessage());
}

// ---- Emergency -----------------------------------------------
$stmtEm = $pdo->prepare(
    "SELECT log_id, emergency_type, note
     FROM tbl_emergency_logs
     WHERE status = 'active'
     ORDER BY triggered_at DESC
     LIMIT 1"
);
$stmtEm->execute();
$emergency = $stmtEm->fetch();

if ($emergency) {
    echo json_encode([
        'priority' => 'emergency',
        'label'    => $emergency['emergency_type'],
        'note'     => $emergency['note'],
    ]);
    exit;
}

// ---- Day mask ------------------------------------------------
// MySQL: date('w') ? 0=Sun…6=Sat
// PostgreSQL uses the same bit map; PHP-side calculation is unchanged.
$dayMap = [
    0 => 64, // Sun
    1 => 1,  // Mon
    2 => 2,  // Tue
    3 => 4,  // Wed
    4 => 8,  // Thu
    5 => 16, // Fri
    6 => 32, // Sat
];
$todayBit    = $dayMap[(int)date('w')];
$currentTime = date('H:i:s');

// ---- Schedules -----------------------------------------------
// MySQL: SUBTIME(:timeStart, '00:00:01')
// PostgreSQL: :timeStart::time - INTERVAL '1 second'
//
// MySQL: s.ring_time BETWEEN expr AND :timeEnd
// PostgreSQL: same BETWEEN syntax works; just fix the SUBTIME call.
$stmtSc = $pdo->prepare("
    SELECT
        s.sched_id,
        s.bell_name,
        s.ring_time,
        s.duration_s
    FROM tbl_schedules s
    JOIN tbl_schedule_zones z ON s.sched_id = z.sched_id
    WHERE s.is_active = TRUE
      AND (s.days_mask & :day) <> 0
      AND (z.zone_code = :zone OR z.zone_code = 'All')
      AND s.ring_time BETWEEN
          (:timeStart::time - INTERVAL '1 second')
          AND :timeEnd::time
");
$stmtSc->execute([
    ':day'       => $todayBit,
    ':zone'      => $zone,
    ':timeStart' => $currentTime,
    ':timeEnd'   => $currentTime,
]);
$schedules = $stmtSc->fetchAll(PDO::FETCH_ASSOC);

if (!empty($schedules)) {
    echo json_encode([
        'priority' => 'schedule',
        'data'     => $schedules,
    ]);
    exit;
}

// ---- Announcements -------------------------------------------
// MySQL: CURDATE()                        ?  PostgreSQL: CURRENT_DATE
// MySQL: CURDATE() + INTERVAL 1 DAY      ?  PostgreSQL: CURRENT_DATE + INTERVAL '1 day'
// MySQL: NOW() - INTERVAL 60 MINUTE      ?  PostgreSQL: NOW() - INTERVAL '60 minutes'
$stmtAn = $pdo->prepare("
    SELECT *
    FROM tbl_announcements
    WHERE (audience = :zone OR audience = 'ALL')
      AND created_at >= CURRENT_DATE
      AND created_at <  CURRENT_DATE + INTERVAL '1 day'
      AND (
          last_played_at IS NULL
          OR last_played_at <= NOW() - INTERVAL '60 minutes'
      )
");
$stmtAn->execute([':zone' => $zone]);
$announcement = $stmtAn->fetchAll(PDO::FETCH_ASSOC);

if (!empty($announcement)) {
    try {
        $updateStmt = $pdo->prepare(
            'UPDATE tbl_announcements SET last_played_at = NOW() WHERE ann_id = :id'
        );
        foreach ($announcement as $row) {
            $updateStmt->execute([':id' => $row['ann_id']]);
        }
    } catch (Throwable $e) {
        error_log('device.php announcement update failed: ' . $e->getMessage());
    }

    echo json_encode([
        'priority' => 'announcements',
        'data'     => $announcement,
    ]);
    exit;
}

// ---- Events --------------------------------------------------
// MySQL: CURDATE()   ?  PostgreSQL: CURRENT_DATE
// MySQL: is_deleted = 0  ?  PostgreSQL: is_deleted = FALSE
$stmtEvt = $pdo->prepare(
    "SELECT *
     FROM tbl_events
     WHERE is_deleted = FALSE
       AND event_date >= CURRENT_DATE
       AND (zones = 'All' OR zones = :zone)
     ORDER BY event_date ASC"
);
$stmtEvt->execute([':zone' => $zone]);
$events = $stmtEvt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($events)) {
    echo json_encode([
        'priority' => 'events',
        'data'     => $events,
    ]);
    exit;
}

echo json_encode(['priority' => date('D')]);
exit;