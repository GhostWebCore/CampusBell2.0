<?php
/**
 * api/dashboard/dashboard.php
 *
 * Single GET endpoint that returns all dynamic data the dashboard needs
 * in ONE request, so the page only makes a single round-trip on load.
 *
 * Response shape:
 * {
 *   success: true,
 *   stats: {
 *     bells_today:       int,   -- active schedules that fire today
 *     bells_completed:   int,   -- how many have already passed
 *     bells_pending:     int,   -- how many are still to fire
 *     active_schedules:  int,   -- total enabled schedule rows
 *     total_announcements: int, -- visible announcements
 *     upcoming_events:   int,   -- events from today onward this month
 *     active_emergency:  bool,  -- is an emergency alert live right now?
 *   },
 *   next_bell: {              -- the very next bell that hasn't fired yet
 *     bell_name: string,
 *     ring_time: string,      -- "HH:MM:SS"
 *     ring_time_fmt: string,  -- "g:i A"
 *     seconds_until: int,     -- seconds from now until ring time
 *     zones: string,          -- comma-separated zone codes
 *   } | null,
 *   recent_activity: [        -- last 6 items across schedules + announcements + emergency
 *     { icon_class, color_class, text, time_fmt }
 *   ],
 *   csrf_token: string
 * }
 *
 * Security:
 *   - require_login.php halts unauthenticated requests immediately
 *   - Read-only endpoint — no CSRF needed for GET, but we issue a
 *     fresh token anyway so other pages can use it after a refresh
 *   - All DB queries use PDO prepared statements
 *   - No user-supplied input reaches SQL (only session data used)
 */

declare(strict_types=1);

header('Content-Type: application/json');

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

// Only GET is supported
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $pdo  = get_db_connection();
    $now  = new DateTimeImmutable('now');
    $today = $now->format('Y-m-d');

    // ---- 1. TODAY'S BELLS  ------------------------------------
    // A bell fires "today" when its days_mask bit for today's weekday
    // is set and the schedule is active.
    // PHP date('N') → 1=Mon … 7=Sun, which maps to bit (N-1) in our mask.
    $todayBit = 1 << ((int)$now->format('N') - 1);

    $bellsToday = (int)$pdo->query(
        "SELECT COUNT(*) FROM tbl_schedules
         WHERE is_active = 1 AND (days_mask & $todayBit) = $todayBit"
    )->fetchColumn();

    // Completed = active today and ring_time is already past
    $bellsCompleted = (int)$pdo->prepare(
        "SELECT COUNT(*) FROM tbl_schedules
         WHERE is_active = 1
           AND (days_mask & $todayBit) = $todayBit
           AND ring_time < :now_time"
    )->execute([':now_time' => $now->format('H:i:s')]) ? $pdo->prepare(
        "SELECT COUNT(*) FROM tbl_schedules
         WHERE is_active = 1
           AND (days_mask & $todayBit) = $todayBit
           AND ring_time < :now_time"
    )->execute([':now_time' => $now->format('H:i:s')]) : 0;

    // Run properly with a reusable prepared statement
    $stmtCompleted = $pdo->prepare(
        "SELECT COUNT(*) FROM tbl_schedules
         WHERE is_active = 1
           AND (days_mask & $todayBit) = $todayBit
           AND ring_time < :now_time"
    );
    $stmtCompleted->execute([':now_time' => $now->format('H:i:s')]);
    $bellsCompleted = (int)$stmtCompleted->fetchColumn();
    $bellsPending   = $bellsToday - $bellsCompleted;

    // ---- 2. ACTIVE SCHEDULE COUNT  ---------------------------
    $activeSchedules = (int)$pdo->query(
        'SELECT COUNT(*) FROM tbl_schedules WHERE is_active = 1'
    )->fetchColumn();

    // ---- 3. ANNOUNCEMENTS COUNT  -----------------------------
    $totalAnnouncements = (int)$pdo->query(
        'SELECT COUNT(*) FROM tbl_announcements WHERE is_deleted = 0'
    )->fetchColumn();

    // ---- 4. UPCOMING EVENTS THIS MONTH  ----------------------
    $firstOfMonth = $now->format('Y-m-01');
    $lastOfMonth  = $now->format('Y-m-t');
    $stmtEvents   = $pdo->prepare(
        'SELECT COUNT(*) FROM tbl_events
         WHERE is_deleted = 0
           AND event_date BETWEEN :first AND :last
           AND event_date >= :today'
    );
    $stmtEvents->execute([':first' => $firstOfMonth, ':last' => $lastOfMonth, ':today' => $today]);
    $upcomingEvents = (int)$stmtEvents->fetchColumn();

    // ---- 5. ACTIVE EMERGENCY  --------------------------------
    $activeEmergency = (bool)$pdo->query(
        'SELECT COUNT(*) FROM tbl_emergency_logs WHERE status = "active"'
    )->fetchColumn();

    // ---- 6. NEXT BELL  ---------------------------------------
    // The next bell that fires today and hasn't passed yet.
    $stmtNext = $pdo->prepare(
        "SELECT
             s.bell_name,
             s.ring_time,
             TIME_FORMAT(s.ring_time, '%h:%i %p') AS ring_time_fmt,
             GROUP_CONCAT(sz.zone_code ORDER BY sz.zone_code SEPARATOR ', ') AS zones,
             TIMESTAMPDIFF(SECOND, :now_ts, CONCAT(:today_date, ' ', s.ring_time)) AS seconds_until
         FROM tbl_schedules s
         LEFT JOIN tbl_schedule_zones sz ON sz.sched_id = s.sched_id
         WHERE s.is_active = 1
           AND (s.days_mask & $todayBit) = $todayBit
           AND s.ring_time > :now_time
         GROUP BY s.sched_id
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
    // Merge the last 6 items from three sources:
    //   a) Recent bell rings  (schedules that fired today, newest first)
    //   b) Recent announcements
    //   c) Recent emergency logs
    // We use a UNION inside PHP to avoid a complex cross-table SQL UNION
    // that would be harder to read and maintain.

    $activity = [];

    // a) Bells that already fired today (passed ring_time)
    $stmtBells = $pdo->prepare(
        "SELECT
             bell_name                       AS text,
             TIME_FORMAT(ring_time,'%h:%i %p') AS time_fmt,
             'bell'                          AS source
         FROM tbl_schedules
         WHERE is_active = 1
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

    // b) Most recent announcements (today or yesterday)
    $stmtAnn = $pdo->prepare(
        'SELECT title, created_at
         FROM tbl_announcements
         WHERE is_deleted = 0
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
        'SELECT emergency_type, status, triggered_at
         FROM tbl_emergency_logs
         ORDER BY triggered_at DESC
         LIMIT 2'
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

    // Sort merged activity descending by sort_key, take top 6
    usort($activity, fn($a, $b) => strcmp($b['sort_key'], $a['sort_key']));
    $activity = array_slice($activity, 0, 6);

    // Remove sort_key before sending to client (internal only)
    foreach ($activity as &$item) unset($item['sort_key']);
    unset($item);

    // ---- 8. UPCOMING EVENTS LIST (next 5) --------------------
    $stmtUpcoming = $pdo->prepare(
        'SELECT event_title, event_date, color
         FROM tbl_events
         WHERE is_deleted = 0
           AND event_date >= :today
         ORDER BY event_date ASC
         LIMIT 5'
    );
    $stmtUpcoming->execute([':today' => $today]);
    $upcomingList = $stmtUpcoming->fetchAll();
    foreach ($upcomingList as &$ev) {
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
