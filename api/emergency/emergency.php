<?php
/**
 * api/emergency/emergency.php  –  PostgreSQL version
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
        error_log('emergency.php fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
});

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/require_login.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';

const ALLOWED_TYPES = [
    'Fire Alert',
    'Flood Warning',
    'Lockdown',
    'Typhoon',
    'Earthquake',
    'Evacuation',
];

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $action = trim($_GET['action'] ?? 'log');
        match ($action) {
            'log'    => handleLog(),
            'active' => handleActive(),
            default  => (function () {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Unknown action.']);
            })(),
        };

    } elseif ($method === 'POST') {
        handlePost();

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    }

} catch (Throwable $e) {
    error_log('emergency.php exception: ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
}


// ================================================================
// GET ?action=log[&limit=N]
// ================================================================
function handleLog(): void
{
    $pdo   = get_db_connection();
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));

    $stmt = $pdo->prepare(
        'SELECT
             log_id,
             emergency_type,
             note,
             zones,
             duration_min,
             operator_name,
             status,
             triggered_at,
             cleared_at
         FROM tbl_emergency_logs
         ORDER BY triggered_at DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['log_id']       = (int)$row['log_id'];
        $row['duration_min'] = $row['duration_min'] !== null ? (int)$row['duration_min'] : null;
        $row['triggered_at_fmt'] = date('M j, Y — g:i A', strtotime($row['triggered_at']));
        $row['cleared_at_fmt']   = $row['cleared_at']
            ? date('M j, Y — g:i A', strtotime($row['cleared_at']))
            : null;
    }
    unset($row);

    echo json_encode([
        'success'    => true,
        'logs'       => $rows,
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// GET ?action=active
// ================================================================
function handleActive(): void
{
    $pdo = get_db_connection();

    // MySQL: WHERE status = "active"  →  PostgreSQL: single quotes only
    $stmt = $pdo->prepare(
        "SELECT log_id, emergency_type, triggered_at, zones, note
         FROM tbl_emergency_logs
         WHERE status = 'active'
         ORDER BY triggered_at DESC
         LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();

    echo json_encode([
        'success'    => true,
        'active'     => $row ?: null,
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// POST — route to trigger or clear
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
        'trigger' => handleTrigger($data),
        'clear'   => handleClear($data),
        default   => (function () {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        })(),
    };
}


// ================================================================
// TRIGGER
// ================================================================
function handleTrigger(array $data): void
{
    $pdo = get_db_connection();

    $emergencyType = trim((string)($data['emergency_type'] ?? ''));
    $note          = trim((string)($data['note']           ?? ''));
    $zones         = trim((string)($data['zones']          ?? 'All'));

    if (!in_array($emergencyType, ALLOWED_TYPES, true)) {
        http_response_code(400);
        echo json_encode([
            'success'    => false,
            'message'    => 'Invalid emergency type.',
            'csrf_token' => csrf_token(),
        ]);
        return;
    }

    if (strlen($note)  > 500) $note  = substr($note,  0, 500);
    if (strlen($zones) > 100) $zones = substr($zones, 0, 100);

    // MySQL: WHERE status = "active"  →  PostgreSQL: single quotes only
    $check = $pdo->prepare(
        "SELECT log_id FROM tbl_emergency_logs WHERE status = 'active' LIMIT 1"
    );
    $check->execute();
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode([
            'success'    => false,
            'message'    => 'An alert is already active. Clear it before triggering a new one.',
            'csrf_token' => csrf_token(),
        ]);
        return;
    }

    $userId       = (string)$_SESSION['user_id'];
    $operatorName = (string)$_SESSION['full_name'];

    // MySQL: VALUES (..., "active")          →  PostgreSQL: single quotes
    // MySQL: $pdo->lastInsertId()            →  PostgreSQL: RETURNING log_id
    $stmt = $pdo->prepare(
        "INSERT INTO tbl_emergency_logs
             (emergency_type, note, zones, triggered_by, operator_name, status)
         VALUES
             (:type, :note, :zones, :triggered_by, :operator_name, 'active')
         RETURNING log_id"
    );
    $stmt->execute([
        ':type'          => $emergencyType,
        ':note'          => $note ?: null,
        ':zones'         => $zones,
        ':triggered_by'  => $userId,
        ':operator_name' => $operatorName,
    ]);

    // PostgreSQL: fetch the returned log_id directly from RETURNING clause
    $newId = (int)$stmt->fetchColumn();

    echo json_encode([
        'success'    => true,
        'message'    => $emergencyType . ' activated — All zones notified!',
        'log'        => [
            'log_id'           => $newId,
            'emergency_type'   => $emergencyType,
            'note'             => $note,
            'zones'            => $zones,
            'operator_name'    => $operatorName,
            'status'           => 'active',
            'triggered_at'     => date('Y-m-d H:i:s'),
            'triggered_at_fmt' => date('M j, Y — g:i A'),
            'duration_min'     => null,
            'cleared_at'       => null,
            'cleared_at_fmt'   => null,
        ],
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// CLEAR
// ================================================================
function handleClear(array $data): void
{
    $pdo   = get_db_connection();
    $logId = (int)($data['log_id'] ?? 0);

    if ($logId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success'    => false,
            'message'    => 'Invalid log ID.',
            'csrf_token' => csrf_token(),
        ]);
        return;
    }

    // MySQL: WHERE status = "active"  →  PostgreSQL: single quotes only
    $check = $pdo->prepare(
        "SELECT log_id, triggered_at FROM tbl_emergency_logs
         WHERE log_id = :id AND status = 'active'
         LIMIT 1"
    );
    $check->execute([':id' => $logId]);
    $row = $check->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode([
            'success'    => false,
            'message'    => 'Active alert not found. It may already have been cleared.',
            'csrf_token' => csrf_token(),
        ]);
        return;
    }

    $triggeredAt = strtotime($row['triggered_at']);
    $now         = time();
    $durationMin = max(1, (int)ceil(($now - $triggeredAt) / 60));
    $clearedAt   = date('Y-m-d H:i:s', $now);

    // MySQL: SET status = "cleared"  →  PostgreSQL: single quotes only
    $pdo->prepare(
        "UPDATE tbl_emergency_logs
         SET status       = 'cleared',
             cleared_at   = :cleared_at,
             duration_min = :duration_min,
             updated_by   = :updated_by
         WHERE log_id = :id"
    )->execute([
        ':cleared_at'   => $clearedAt,
        ':duration_min' => $durationMin,
        ':updated_by'   => (string)$_SESSION['user_id'],
        ':id'           => $logId,
    ]);

    echo json_encode([
        'success'        => true,
        'message'        => 'Alert cleared.',
        'log_id'         => $logId,
        'duration_min'   => $durationMin,
        'cleared_at_fmt' => date('M j, Y — g:i A', $now),
        'csrf_token'     => csrf_token(),
    ]);
}