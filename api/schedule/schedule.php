<?php
/**
 * api/schedule/schedule.php
 *
 * Single-file endpoint for the Schedule Management feature.
 *
 * ┌─────────┬────────────────────────────────────────────────────────┐
 * │ Method  │ Action / Payload                                       │
 * ├─────────┼────────────────────────────────────────────────────────┤
 * │ GET     │ ?action=list[&q=keyword][&type=class|break|prayer|     │
 * │         │   alert][&day=1-7]                                     │
 * │         │ Returns all schedules with their zones, sorted by time │
 * ├─────────┼────────────────────────────────────────────────────────┤
 * │ POST    │ { action:'create', csrf_token, bell_name, ring_time,   │
 * │         │   duration_s, bell_type, days_mask, zones[] }          │
 * │         │ Creates a new schedule row + zone rows                 │
 * ├─────────┼────────────────────────────────────────────────────────┤
 * │ POST    │ { action:'toggle', csrf_token, sched_id, is_active }   │
 * │         │ Flips the active flag for one row                      │
 * ├─────────┼────────────────────────────────────────────────────────┤
 * │ POST    │ { action:'delete', csrf_token, sched_id }              │
 * │         │ Hard-deletes a schedule (zones cascade-delete via FK)  │
 * └─────────┴────────────────────────────────────────────────────────┘
 *
 * Security:
 *   - require_login.php — halts unauthenticated requests immediately
 *   - CSRF token verified (and rotated) on every state-changing POST
 *   - All DB access uses PDO prepared statements — zero string SQL
 *   - Input whitelisted and length-capped before reaching the DB
 *   - Author is always taken from $_SESSION, never from the request body
 *   - Fatal errors and exceptions are caught and returned as JSON,
 *     never as blank bodies or HTML stack traces
 */

declare(strict_types=1);

// Always respond as JSON
header('Content-Type: application/json');

// ----------------------------------------------------------------
// Fatal-error safety net — converts PHP fatal errors into JSON
// so the client never receives a blank / HTML response body
// ----------------------------------------------------------------
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        // Log the real detail server-side; send a generic message to the client
        error_log('schedule.php fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
});

// ----------------------------------------------------------------
// Bootstrap — always in this order
// ----------------------------------------------------------------

// 1. Harden session start (cookie flags, periodic ID regen)
require_once __DIR__ . '/../../config/session.php';

// 2. Auth guard — redirects HTML requests to login.php,
//    returns 401 JSON for AJAX requests, halts either way
require_once __DIR__ . '/../../includes/require_login.php';

// 3. PDO connection factory
require_once __DIR__ . '/../../config/database.php';

// 4. csrf_token() and csrf_verify() helpers
require_once __DIR__ . '/../../includes/csrf.php';

// ----------------------------------------------------------------
// Route
// ----------------------------------------------------------------
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        handleList();
    } elseif ($method === 'POST') {
        handlePost();
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    }

} catch (Throwable $e) {
    // Catches PDOException, LogicException, etc. that bypass the
    // shutdown handler (which only catches true PHP fatal errors)
    error_log('schedule.php exception: ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
}


// ================================================================
// GET — LIST / SEARCH / FILTER
// ================================================================
function handleList(): void
{
    $pdo = get_db_connection();

    // ---- Allowed filter values (whitelist, not just sanitise) ----
    $allowedTypes = ['class', 'break', 'prayer', 'alert'];

    $typeFilter = trim($_GET['type'] ?? '');
    if ($typeFilter !== '' && !in_array($typeFilter, $allowedTypes, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid type filter.']);
        return;
    }

    // day filter: 1 (Mon) – 7 (Sun)  →  maps to bitmask position
    $dayFilter = (int)($_GET['day'] ?? 0);
    if ($dayFilter < 0 || $dayFilter > 7) $dayFilter = 0;

    // Keyword search — cap length to prevent oversized LIKE patterns
    $keyword = trim($_GET['q'] ?? '');
    if (strlen($keyword) > 200) $keyword = substr($keyword, 0, 200);

    // ---- Build WHERE dynamically ----------------------------------
    // We always start with no extra conditions (all rows visible).
    // Each active filter appends a new clause + a bound parameter.
    $where  = [];
    $params = [];

    if ($typeFilter !== '') {
        $where[]         = 's.bell_type = :bell_type';
        $params[':bell_type'] = $typeFilter;
    }

    if ($dayFilter > 0) {
        // Check whether the corresponding bit is set in days_mask.
        // days_mask is: bit 0 = Mon … bit 6 = Sun
        // $_GET['day'] is 1-based (1=Mon … 7=Sun), so shift = day - 1
        $bit = 1 << ($dayFilter - 1);
        // Bind as a literal int — safe because we've already validated
        // $dayFilter is 1-7, so $bit is a known-safe power-of-two
        $where[]       = "(s.days_mask & $bit) = $bit";
        // No parameter binding needed for a literal integer expression
    }

    if ($keyword !== '') {
        // LIKE search on bell_name only — straightforward and fast
        $where[]      = 's.bell_name LIKE :kw';
        $params[':kw'] = '%' . $keyword . '%';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // ---- Main query: schedules + aggregated zone list ------------
    // GROUP_CONCAT collapses the zone rows into a single CSV string
    // so we don't need a separate round-trip for zones on list view.
    $sql = "
        SELECT
            s.sched_id,
            s.bell_name,
            TIME_FORMAT(s.ring_time, '%h:%i %p') AS ring_time_fmt,
            s.ring_time                           AS ring_time_raw,
            s.duration_s,
            s.bell_type,
            s.days_mask,
            s.is_active,
            GROUP_CONCAT(sz.zone_code ORDER BY sz.zone_code SEPARATOR ',') AS zones
        FROM tbl_schedules s
        LEFT JOIN tbl_schedule_zones sz ON sz.sched_id = s.sched_id
        $whereSql
        GROUP BY s.sched_id
        ORDER BY s.ring_time ASC
    ";

    $stmt = $pdo->prepare($sql);

    // Bind only the filter params (day filter used a literal int above)
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }

    $stmt->execute();
    $rows = $stmt->fetchAll();

    // ---- Post-process each row -----------------------------------
    foreach ($rows as &$row) {
        // Convert days_mask bitmask → human-readable label for the UI
        $row['days_label'] = decodeDaysMask((int)$row['days_mask']);

        // Cast types so JSON serialises correctly (PDO returns strings)
        $row['sched_id']   = (int)$row['sched_id'];
        $row['duration_s'] = (int)$row['duration_s'];
        $row['days_mask']  = (int)$row['days_mask'];
        $row['is_active']  = (bool)$row['is_active'];

        // Normalise null zones (LEFT JOIN with no zone rows) to empty string
        $row['zones'] = $row['zones'] ?? '';
    }
    unset($row); // break the reference

    // Return a fresh CSRF token so the "Add Bell" form is always ready
    echo json_encode([
        'success'    => true,
        'data'       => $rows,
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// POST — route to sub-actions: create | toggle | delete
// ================================================================
function handlePost(): void
{
    // ---- Parse JSON body ----------------------------------------
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
        return;
    }

    // ---- CSRF verification (single-use, rotated on every call) --
    // Accept token from the JSON body OR the X-CSRF-Token header
    $submittedToken = $data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

    if (!csrf_verify($submittedToken)) {
        // csrf_verify() has already rotated (deleted) the old token,
        // so we issue a fresh one for the client to retry with
        http_response_code(403);
        echo json_encode([
            'success'    => false,
            'message'    => 'Your session has expired. Please refresh the page and try again.',
            'csrf_token' => csrf_token(),
        ]);
        return;
    }

    // ---- Dispatch to the correct sub-handler --------------------
    $action = trim((string)($data['action'] ?? ''));

    match ($action) {
        'create' => handleCreate($data),
        'toggle' => handleToggle($data),
        'delete' => handleDelete($data),
        default  => (function () {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        })(),
    };
}


// ================================================================
// CREATE — insert a new schedule + its zones
// ================================================================
function handleCreate(array $data): void
{
    $pdo = get_db_connection();

    // ---- Validate and sanitise inputs ---------------------------

    $bellName  = trim((string)($data['bell_name']  ?? ''));
    $ringTime  = trim((string)($data['ring_time']  ?? ''));    // expected: "HH:MM"
    $durationS = (int)($data['duration_s'] ?? 3);
    $bellType  = trim((string)($data['bell_type']  ?? 'class'));
    $daysMask  = (int)($data['days_mask']  ?? 31);             // default Mon–Fri
    $zonesRaw  = $data['zones'] ?? [];                         // array of zone strings

    // Required fields
    if ($bellName === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bell name is required.', 'csrf_token' => csrf_token()]);
        return;
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $ringTime)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ring time must be in HH:MM (24-hour) format.', 'csrf_token' => csrf_token()]);
        return;
    }

    // Length caps
    if (strlen($bellName) > 120) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bell name must be 120 characters or fewer.', 'csrf_token' => csrf_token()]);
        return;
    }

    // Clamp duration to a sane range (1–60 seconds)
    $durationS = max(1, min(60, $durationS));

    // Whitelist bell_type
    $allowedTypes = ['class', 'break', 'prayer', 'alert'];
    if (!in_array($bellType, $allowedTypes, true)) $bellType = 'class';

    // days_mask: valid range is 1–127 (at least one day must be set)
    $daysMask = max(1, min(127, $daysMask));

    // Zones: must be a non-empty array of strings, each ≤ 10 chars.
    // Silently discard any zone strings that look invalid.
    $allowedZones = ['All', 'A', 'B', 'C', 'D', 'E', 'F'];
    $zones = [];
    if (is_array($zonesRaw)) {
        foreach ($zonesRaw as $z) {
            $z = trim((string)$z);
            if ($z !== '' && strlen($z) <= 10 && in_array($z, $allowedZones, true)) {
                $zones[] = $z;
            }
        }
    }
    if (empty($zones)) {
        $zones = ['All']; // default if nothing valid was supplied
    }
    $zones = array_unique($zones); // deduplicate

    // Author from session — never trust the client for this
    $userId = (string)$_SESSION['user_id'];

    // ---- Insert schedule row (wrapped in a transaction so the
    //      zone rows are either all written or none are) ----------
    $pdo->beginTransaction();

    try {
        $stmtSched = $pdo->prepare(
            'INSERT INTO tbl_schedules
               (bell_name, ring_time, duration_s, bell_type, days_mask, is_active, created_by, updated_by)
             VALUES
               (:bell_name, :ring_time, :duration_s, :bell_type, :days_mask, 1, :created_by, :updated_by)'
        );
        $stmtSched->execute([
            ':bell_name'   => $bellName,
            ':ring_time'   => $ringTime . ':00',  // append seconds for TIME column
            ':duration_s'  => $durationS,
            ':bell_type'   => $bellType,
            ':days_mask'   => $daysMask,
            ':created_by'  => $userId,
            ':updated_by'  => $userId,
        ]);

        $newId = (int)$pdo->lastInsertId();

        // Insert one zone row per zone — prepared once, executed per zone
        $stmtZone = $pdo->prepare(
            'INSERT INTO tbl_schedule_zones (sched_id, zone_code) VALUES (:sched_id, :zone_code)'
        );
        foreach ($zones as $zone) {
            $stmtZone->execute([':sched_id' => $newId, ':zone_code' => $zone]);
        }

        $pdo->commit();

    } catch (Throwable $e) {
        // Roll back the partial insert so we don't leave orphan rows
        $pdo->rollBack();
        error_log('schedule create transaction failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save schedule.', 'csrf_token' => csrf_token()]);
        return;
    }

    // ---- Return the new row data so the client can prepend it ---
    echo json_encode([
        'success'    => true,
        'message'    => 'Bell schedule created.',
        'sched'      => [
            'sched_id'     => $newId,
            'bell_name'    => $bellName,
            'ring_time_fmt'=> date('g:i A', strtotime($ringTime)),
            'ring_time_raw'=> $ringTime . ':00',
            'duration_s'   => $durationS,
            'bell_type'    => $bellType,
            'days_mask'    => $daysMask,
            'days_label'   => decodeDaysMask($daysMask),
            'is_active'    => true,
            'zones'        => implode(',', $zones),
        ],
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// TOGGLE — flip is_active for a single row
// ================================================================
function handleToggle(array $data): void
{
    $pdo = get_db_connection();

    $schedId  = (int)($data['sched_id']  ?? 0);
    $isActive = !empty($data['is_active']) ? 1 : 0;

    if ($schedId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid schedule ID.', 'csrf_token' => csrf_token()]);
        return;
    }

    // Confirm the row exists before updating
    $check = $pdo->prepare('SELECT sched_id FROM tbl_schedules WHERE sched_id = :id LIMIT 1');
    $check->execute([':id' => $schedId]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Schedule not found.', 'csrf_token' => csrf_token()]);
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE tbl_schedules
         SET is_active = :is_active, updated_by = :updated_by
         WHERE sched_id = :id'
    );
    $stmt->execute([
        ':is_active'  => $isActive,
        ':updated_by' => (string)$_SESSION['user_id'],
        ':id'         => $schedId,
    ]);

    echo json_encode([
        'success'    => true,
        'message'    => $isActive ? 'Bell enabled.' : 'Bell disabled.',
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// DELETE — hard-delete a schedule (zones cascade via FK)
// ================================================================
function handleDelete(array $data): void
{
    $pdo = get_db_connection();

    $schedId = (int)($data['sched_id'] ?? 0);

    if ($schedId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid schedule ID.', 'csrf_token' => csrf_token()]);
        return;
    }

    // Grab the name before deleting so the success message is useful
    $nameStmt = $pdo->prepare('SELECT bell_name FROM tbl_schedules WHERE sched_id = :id LIMIT 1');
    $nameStmt->execute([':id' => $schedId]);
    $row = $nameStmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Schedule not found.', 'csrf_token' => csrf_token()]);
        return;
    }

    // tbl_schedule_zones has ON DELETE CASCADE on the FK,
    // so deleting the parent row also removes all zone rows automatically
    $pdo->prepare('DELETE FROM tbl_schedules WHERE sched_id = :id')
        ->execute([':id' => $schedId]);

    echo json_encode([
        'success'    => true,
        'message'    => '"' . $row['bell_name'] . '" deleted.',
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// Helper: decode days_mask bitmask → human label
// ================================================================
function decodeDaysMask(int $mask): string
{
    // Common presets first — avoids generating verbose strings for
    // the most frequently used schedules
    if ($mask === 127) return 'Every day';
    if ($mask === 63)  return 'Mon–Sat';
    if ($mask === 31)  return 'Mon–Fri';
    if ($mask === 96)  return 'Sat–Sun';  // weekend only

    // For custom combinations, build an abbreviation string
    $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $active   = [];
    for ($i = 0; $i < 7; $i++) {
        if ($mask & (1 << $i)) {
            $active[] = $dayNames[$i + 1];
        }
    }
    return implode(', ', $active);
}
