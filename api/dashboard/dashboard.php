<?php
/**
 * api/dashboard/dashboard.php  –  PostgreSQL version
 */

declare(strict_types=1);

header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');
// ----------------------------------------------------------------
// Fatal-error safety net
// ----------------------------------------------------------------
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        error_log('dashboard.php fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
});

// ----------------------------------------------------------------
// Bootstrap
// ----------------------------------------------------------------
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/require_login.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $pdo   = get_db_connection();
    $now   = new DateTimeImmutable('now');
    $today = $now->format('Y-m-d');

    // ---- 1. TODAY'S BELLS  ------------------------------------
    // PHP date('N') → 1=Mon … 7=Sun → bit (N-1)
    // PostgreSQL bitwise: (days_mask & bit) = bit  (same syntax, works natively)
    $todayBit = 1 << ((int)$now->format('N') - 1);

    $bellsToday = (int)$pdo->query(
        "SELECT COUNT(*) FROM tbl_schedules
         WHERE is_active = TRUE AND (days_mask & $todayBit) = $todayBit"
    )->fetchColumn();

    $stmtCompleted = $pdo->prepare(
        "SELECT COUNT(*) FROM tbl_schedules
         WHERE is_active = TRUE
           AND (days_mask & $todayBit) = $todayBit
           AND ring_time < :now_time"
    );
    $stmtCompleted->execute([':now_time' => $now->format('H:i:s')]);
    $bellsCompleted = (int)$stmtCompleted->fetchColumn();
    $bellsPending   = $bellsToday - $bellsCompleted;

    // ---- 2. ACTIVE SCHEDULE COUNT  ---------------------------
    $activeSchedules = (int)$pdo->query(
        'SELECT COUNT(*) FROM tbl_schedules WHERE is_active = TRUE'
    )->fetchColumn();

    // ---- 3. ANNOUNCEMENTS COUNT  -----------------------------
    $totalAnnouncements = (int)$pdo->query(
        'SELECT COUNT(*) FROM tbl_announcements WHERE is_deleted = FALSE'
    )->fetchColumn();

    // ---- 4. UPCOMING EVENTS THIS MONTH  ----------------------
    $firstOfMonth = $now->format('Y-m-01');
    $lastOfMonth  = $now->format('Y-m-t');
    $stmtEvents   = $pdo->prepare(
        "SELECT COUNT(*) FROM tbl_events
         WHERE is_deleted = FALSE
           AND event_date BETWEEN :first AND :last
           AND event_date >= :today"
    );
    $stmtEvents->execute([':first' => $firstOfMonth, ':last' => $lastOfMonth, ':today' => $today]);
    $upcomingEvents = (int)$stmtEvents->fetchColumn();

    // ---- 5. ACTIVE EMERGENCY  --------------------------------
    // MySQL used double-quoted strings for values; PostgreSQL requires single quotes.
    $activeEmergency = (bool)$pdo->query(
        "SELECT COUNT(*) FROM tbl_emergency_logs WHERE status = 'active'"
    )->fetchColumn();

    // ---- 6. NEXT BELL  ---------------------------------------
    // MySQL: TIME_FORMAT(t, '%h:%i %p')  →  PostgreSQL: TO_CHAR(t, 'HH12:MI AM')
    // MySQL: TIMESTAMPDIFF(SECOND, a, b) →  PostgreSQL: EXTRACT(EPOCH FROM (b - a))
    // MySQL: CONCAT(date, ' ', time)     →  PostgreSQL: (date || ' ' || time)::timestamp
    $stmtNext = $pdo->prepare(
        "SELECT
             s.bell_name,
             s.ring_time::text                                             AS ring_time,
             TO_CHAR(s.ring_time, 'HH12:MI AM')                          AS ring_time_fmt,
             STRING_AGG(sz.zone_code, ', ' ORDER BY sz.zone_code)        AS zones,
             EXTRACT(EPOCH FROM (
                 (:today_date || ' ' || s.ring_time::text)::timestamp
                 - :now_ts::timestamp
             ))::int                                                       AS seconds_until
         FROM tbl_schedules s
         LEFT JOIN tbl_schedule_zones sz ON sz.sched_id = s.sched_id
         WHERE s.is_active = TRUE
           AND (s.days_mask & $todayBit) = $todayBit
           AND s.ring_time > :now_time
         GROUP BY s.sched_id, s.bell_name, s.ring_time
         ORDER BY s.ring_time ASC
         LIMIT 1"
    );
    $stmtNext->execute([
        ':now_ts'     => $now->format('Y-m-d H:i:s'),
        ':today_date' => $today,
        ':now_time'   => $now->format('H:i:s'),
    ]);
    $nextBell = $stmtNext->fetch() ?: null;

    if ($nextBell) {
        $nextBell['seconds_until'] = (int)$nextBell['seconds_until'];
        $nextBell['zones']         = $nextBell['zones'] ?? 'All';
    }

    // ---- 7. RECENT ACTIVITY  ----------------------------------

    $activity = [];

    // a) Bells that already fired today
    // MySQL: TIME_FORMAT(t, '%h:%i %p')  →  PostgreSQL: TO_CHAR(t, 'HH12:MI AM')
    $stmtBells = $pdo->prepare(
        "SELECT
             bell_name                          AS text,
             TO_CHAR(ring_time, 'HH12:MI AM')  AS time_fmt,
             'bell'                             AS source
         FROM tbl_schedules
         WHERE is_active = TRUE
           AND (days_mask & $todayBit) = $todayBit
           AND ring_time < :now_time
         ORDER BY ring_time DESC
         LIMIT 4"
    );
    $stmtBells->execute([':now_time' => $now->format('H:i:s')]);
    foreach ($stmtBells->fetchAll() as $row) {
        $activity[] = [
            'icon_class'  => 'fa-solid fa-bell',
            'color_class' => 'success',
            'text'        => htmlspecialchars($row['text'], ENT_QUOTES, 'UTF-8') . ' fired successfully',
            'time_fmt'    => $row['time_fmt'],
            'sort_key'    => $row['time_fmt'],
        ];
    }

    // b) Most recent announcements
    $stmtAnn = $pdo->prepare(
        'SELECT title, created_at
         FROM tbl_announcements
         WHERE is_deleted = FALSE
         ORDER BY created_at DESC
         LIMIT 3'
    );
    $stmtAnn->execute();
    foreach ($stmtAnn->fetchAll() as $row) {
        $activity[] = [
            'icon_class'  => 'fa-solid fa-bullhorn',
            'color_class' => 'info',
            'text'        => 'Announcement: ' . htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'),
            'time_fmt'    => date('g:i A', strtotime($row['created_at'])),
            'sort_key'    => $row['created_at'],
        ];
    }

    // c) Most recent emergency log entries
    $stmtEmLog = $pdo->prepare(
        "SELECT emergency_type, status, triggered_at
         FROM tbl_emergency_logs
         ORDER BY triggered_at DESC
         LIMIT 2"
    );
    $stmtEmLog->execute();
    foreach ($stmtEmLog->fetchAll() as $row) {
        $label = htmlspecialchars($row['emergency_type'], ENT_QUOTES, 'UTF-8');
        $activity[] = [
            'icon_class'  => 'fa-solid fa-triangle-exclamation',
            'color_class' => $row['status'] === 'active' ? 'danger' : 'warning',
            'text'        => 'Emergency alert: ' . $label . ' (' . $row['status'] . ')',
            'time_fmt'    => date('g:i A', strtotime($row['triggered_at'])),
            'sort_key'    => $row['triggered_at'],
        ];
    }

    usort($activity, fn($a, $b) => strcmp($b['sort_key'], $a['sort_key']));
    $activity = array_slice($activity, 0, 6);
    foreach ($activity as &$item) unset($item['sort_key']);
    unset($item);

    // ---- 8. UPCOMING EVENTS LIST (next 5) --------------------
    $stmtUpcoming = $pdo->prepare(
        "SELECT event_title, event_date, color
         FROM tbl_events
         WHERE is_deleted = FALSE
           AND event_date >= :today
         ORDER BY event_date ASC
         LIMIT 5"
    );
    $stmtUpcoming->execute([':today' => $today]);
    $upcomingList = $stmtUpcoming->fetchAll();
    foreach ($upcomingList as &$ev) {
        // MySQL: date()  →  PostgreSQL returns a proper date string, PHP handles it fine
        $ev['date_fmt'] = date('M j', strtotime($ev['event_date']));
    }
    unset($ev);

    // ---- Build response ----------------------------------------
    echo json_encode([
        'success' => true,
        'stats'   => [
            'bells_today'         => $bellsToday,
            'bells_completed'     => $bellsCompleted,
            'bells_pending'       => $bellsPending,
            'active_schedules'    => $activeSchedules,
            'total_announcements' => $totalAnnouncements,
            'upcoming_events'     => $upcomingEvents,
            'active_emergency'    => $activeEmergency,
        ],
        'next_bell'       => $nextBell,
        'recent_activity' => $activity,
        'upcoming_events' => $upcomingList,
        'csrf_token'      => csrf_token(),
    ]);

} catch (Throwable $e) {
    error_log('dashboard api exception: ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
}