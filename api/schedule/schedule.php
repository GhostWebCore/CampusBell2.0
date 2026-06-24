<?php
/**
 * api/schedule/schedule.php  –  PostgreSQL version
 */

declare(strict_types=1);

header('Content-Type: application/json');

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        error_log('schedule.php fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
});

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/require_login.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';

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

    $allowedTypes = ['class', 'break', 'prayer', 'alert'];

    $typeFilter = trim($_GET['type'] ?? '');
    if ($typeFilter !== '' && !in_array($typeFilter, $allowedTypes, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid type filter.']);
        return;
    }

    $dayFilter = (int)($_GET['day'] ?? 0);
    if ($dayFilter < 0 || $dayFilter > 7) $dayFilter = 0;

    $keyword = trim($_GET['q'] ?? '');
    if (strlen($keyword) > 200) $keyword = substr($keyword, 0, 200);

    $where  = [];
    $params = [];

    if ($typeFilter !== '') {
        $where[]              = 's.bell_type = :bell_type';
        $params[':bell_type'] = $typeFilter;
    }

    if ($dayFilter > 0) {
        $bit     = 1 << ($dayFilter - 1);
        $where[] = "(s.days_mask & $bit) = $bit";
    }

    if ($keyword !== '') {
        // MySQL: LIKE  →  PostgreSQL: ILIKE (case-insensitive, more natural for search)
        $where[]      = 's.bell_name ILIKE :kw';
        $params[':kw'] = '%' . $keyword . '%';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // MySQL: TIME_FORMAT(t, '%h:%i %p')      →  PostgreSQL: TO_CHAR(t, 'HH12:MI AM')
    // MySQL: GROUP_CONCAT(... SEPARATOR ',') →  PostgreSQL: STRING_AGG(... ',' ORDER BY ...)
    // MySQL: GROUP BY s.sched_id (implicit)  →  PostgreSQL: must list all non-aggregated columns
    $sql = "
        SELECT
            s.sched_id,
            s.bell_name,
            TO_CHAR(s.ring_time, 'HH12:MI AM')                   AS ring_time_fmt,
            s.ring_time::text                                     AS ring_time_raw,
            s.duration_s,
            s.bell_type,
            s.days_mask,
            s.is_active,
            STRING_AGG(sz.zone_code, ',' ORDER BY sz.zone_code)  AS zones
        FROM tbl_schedules s
        LEFT JOIN tbl_schedule_zones sz ON sz.sched_id = s.sched_id
        $whereSql
        GROUP BY s.sched_id, s.bell_name, s.ring_time, s.duration_s,
                 s.bell_type, s.days_mask, s.is_active
        ORDER BY s.ring_time ASC
    ";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }

    $stmt->execute();
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['days_label'] = decodeDaysMask((int)$row['days_mask']);
        $row['sched_id']   = (int)$row['sched_id'];
        $row['duration_s'] = (int)$row['duration_s'];
        $row['days_mask']  = (int)$row['days_mask'];
        $row['is_active']  = (bool)$row['is_active'];
        $row['zones']      = $row['zones'] ?? '';
    }
    unset($row);

    echo json_encode([
        'success'    => true,
        'data'       => $rows,
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// POST — route to sub-actions
// ================================================================
function handlePost(): void
{
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
        return;
    }

    $submittedToken = $data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

    if (!csrf_verify($submittedToken)) {
        http_response_code(403);
        echo json_encode([
            'success'    => false,
            'message'    => 'Your session has expired. Please refresh the page and try again.',
            'csrf_token' => csrf_token(),
        ]);
        return;
    }

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
// CREATE
// ================================================================
function handleCreate(array $data): void
{
    $pdo = get_db_connection();

    $bellName  = trim((string)($data['bell_name']  ?? ''));
    $ringTime  = trim((string)($data['ring_time']  ?? ''));
    $durationS = (int)($data['duration_s'] ?? 3);
    $bellType  = trim((string)($data['bell_type']  ?? 'class'));
    $daysMask  = (int)($data['days_mask']  ?? 31);
    $zonesRaw  = $data['zones'] ?? [];

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
    if (strlen($bellName) > 120) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bell name must be 120 characters or fewer.', 'csrf_token' => csrf_token()]);
        return;
    }

    $durationS = max(1, min(60, $durationS));

    $allowedTypes = ['class', 'break', 'prayer', 'alert'];
    if (!in_array($bellType, $allowedTypes, true)) $bellType = 'class';

    $daysMask = max(1, min(127, $daysMask));

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
    if (empty($zones)) $zones = ['All'];
    $zones = array_unique($zones);

    $userId = (string)$_SESSION['user_id'];

    $pdo->beginTransaction();

    try {
        // MySQL: VALUES (..., 1, ...)       →  PostgreSQL: VALUES (..., TRUE, ...)
        // MySQL: $pdo->lastInsertId()       →  PostgreSQL: RETURNING sched_id
        $stmtSched = $pdo->prepare(
            'INSERT INTO tbl_schedules
               (bell_name, ring_time, duration_s, bell_type, days_mask, is_active, created_by, updated_by)
             VALUES
               (:bell_name, :ring_time, :duration_s, :bell_type, :days_mask, TRUE, :created_by, :updated_by)
             RETURNING sched_id'
        );
        $stmtSched->execute([
            ':bell_name'  => $bellName,
            ':ring_time'  => $ringTime . ':00',
            ':duration_s' => $durationS,
            ':bell_type'  => $bellType,
            ':days_mask'  => $daysMask,
            ':created_by' => $userId,
            ':updated_by' => $userId,
        ]);

        $newId = (int)$stmtSched->fetchColumn();

        $stmtZone = $pdo->prepare(
            'INSERT INTO tbl_schedule_zones (sched_id, zone_code) VALUES (:sched_id, :zone_code)'
        );
        foreach ($zones as $zone) {
            $stmtZone->execute([':sched_id' => $newId, ':zone_code' => $zone]);
        }

        $pdo->commit();

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('schedule create transaction failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save schedule.', 'csrf_token' => csrf_token()]);
        return;
    }

    echo json_encode([
        'success'    => true,
        'message'    => 'Bell schedule created.',
        'sched'      => [
            'sched_id'      => $newId,
            'bell_name'     => $bellName,
            'ring_time_fmt' => date('g:i A', strtotime($ringTime)),
            'ring_time_raw' => $ringTime . ':00',
            'duration_s'    => $durationS,
            'bell_type'     => $bellType,
            'days_mask'     => $daysMask,
            'days_label'    => decodeDaysMask($daysMask),
            'is_active'     => true,
            'zones'         => implode(',', $zones),
        ],
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// TOGGLE
// ================================================================
function handleToggle(array $data): void
{
    $pdo = get_db_connection();

    $schedId  = (int)($data['sched_id']  ?? 0);
    // MySQL: stores 1/0   →  PostgreSQL: TRUE/FALSE
    $isActive = !empty($data['is_active']) ? true : false;

    if ($schedId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid schedule ID.', 'csrf_token' => csrf_token()]);
        return;
    }

    $check = $pdo->prepare('SELECT sched_id FROM tbl_schedules WHERE sched_id = :id LIMIT 1');
    $check->execute([':id' => $schedId]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Schedule not found.', 'csrf_token' => csrf_token()]);
        return;
    }

    // MySQL: SET is_active = 1/0  →  PostgreSQL: SET is_active = TRUE/FALSE
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
// DELETE
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

    $nameStmt = $pdo->prepare('SELECT bell_name FROM tbl_schedules WHERE sched_id = :id LIMIT 1');
    $nameStmt->execute([':id' => $schedId]);
    $row = $nameStmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Schedule not found.', 'csrf_token' => csrf_token()]);
        return;
    }

    // ON DELETE CASCADE on the FK handles tbl_schedule_zones automatically
    // — no change needed here, works the same in PostgreSQL
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
// (Pure PHP — no SQL involved, no changes needed)
// ================================================================
function decodeDaysMask(int $mask): string
{
    if ($mask === 127) return 'Every day';
    if ($mask === 63)  return 'Mon–Sat';
    if ($mask === 31)  return 'Mon–Fri';
    if ($mask === 96)  return 'Sat–Sun';

    $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $active   = [];
    for ($i = 0; $i < 7; $i++) {
        if ($mask & (1 << $i)) {
            $active[] = $dayNames[$i + 1];
        }
    }
    return implode(', ', $active);
}