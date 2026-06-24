<?php
/**
 * api/emergency/emergency.php
 *
 * Single-file endpoint for the Emergency Override feature.
 *
 * ┌────────┬──────────────────────────────────────────────────────────┐
 * │ Method │ Action / Payload                                         │
 * ├────────┼──────────────────────────────────────────────────────────┤
 * │ GET    │ ?action=log[&limit=N]                                    │
 * │        │ Returns the N most recent emergency log entries.         │
 * ├────────┼──────────────────────────────────────────────────────────┤
 * │ GET    │ ?action=active                                           │
 * │        │ Returns the currently active alert (if any), so the     │
 * │        │ page can restore the "CLEAR" state on refresh.          │
 * ├────────┼──────────────────────────────────────────────────────────┤
 * │ POST   │ { action:'trigger', csrf_token,                          │
 * │        │   emergency_type, note, zones }                          │
 * │        │ Inserts a new active log row and returns it.             │
 * ├────────┼──────────────────────────────────────────────────────────┤
 * │ POST   │ { action:'clear', csrf_token, log_id }                   │
 * │        │ Marks an active alert as cleared, sets cleared_at and    │
 * │        │ calculates duration_min.                                 │
 * └────────┴──────────────────────────────────────────────────────────┘
 *
 * Security applied:
 *   - require_login.php halts any unauthenticated request first
 *   - CSRF token verified and rotated on every state-changing POST
 *   - All DB access uses PDO prepared statements — zero string SQL
 *   - emergency_type is whitelisted against the ENUM before insert
 *   - Author/operator is always read from $_SESSION, never the body
 *   - Fatal errors caught by shutdown handler → clean JSON response
 */

declare(strict_types=1);

// Always respond as JSON no matter what
header('Content-Type: application/json');

// ----------------------------------------------------------------
// Fatal-error safety net
// Converts PHP E_ERROR / E_PARSE fatals into a JSON response
// so the client never sees a blank body or HTML stack trace.
// ----------------------------------------------------------------
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        // Log the real detail server-side; send a safe generic message to the client
        error_log('emergency.php fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
});

// ----------------------------------------------------------------
// Bootstrap — always in this order
// ----------------------------------------------------------------
require_once __DIR__ . '/../../config/session.php';         // hardened session start
require_once __DIR__ . '/../../includes/require_login.php'; // auth guard — halts if not logged in
require_once __DIR__ . '/../../config/database.php';        // PDO connection factory
require_once __DIR__ . '/../../includes/csrf.php';          // csrf_token() / csrf_verify()

// ----------------------------------------------------------------
// Whitelist of valid emergency types — must match the ENUM in the
// schema AND the card names in the frontend exactly.
// ----------------------------------------------------------------
const ALLOWED_TYPES = [
    'Fire Alert',
    'Flood Warning',
    'Lockdown',
    'Typhoon',
    'Earthquake',
    'Evacuation',
];

// ----------------------------------------------------------------
// Route by HTTP method
// ----------------------------------------------------------------
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
    // Catches PDOException, TypeError, etc. that bypass the shutdown handler
    error_log('emergency.php exception: ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
}


// ================================================================
// GET ?action=log[&limit=N]
// Returns the most recent log entries for the on-page table.
// ================================================================
function handleLog(): void
{
    $pdo = get_db_connection();

    // Cap limit to a sane range — default 20, max 100
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

    // Format timestamps and cast types for clean JSON output
    foreach ($rows as &$row) {
        $row['log_id']         = (int)$row['log_id'];
        $row['duration_min']   = $row['duration_min'] !== null ? (int)$row['duration_min'] : null;

        // Human-readable triggered_at: "Jun 10, 2026 — 2:14 PM"
        $row['triggered_at_fmt'] = date('M j, Y — g:i A', strtotime($row['triggered_at']));

        // Human-readable cleared_at (null if still active)
        $row['cleared_at_fmt']   = $row['cleared_at']
            ? date('M j, Y — g:i A', strtotime($row['cleared_at']))
            : null;
    }
    unset($row);

    echo json_encode([
        'success'    => true,
        'logs'       => $rows,
        'csrf_token' => csrf_token(), // fresh token for the next Trigger / Clear action
    ]);
}


// ================================================================
// GET ?action=active
// Returns the single currently-active alert, or null.
// Used on page load to restore UI state if an alert is still live.
// ================================================================
function handleActive(): void
{
    $pdo = get_db_connection();

    $stmt = $pdo->prepare(
        'SELECT log_id, emergency_type, triggered_at, zones, note
         FROM tbl_emergency_logs
         WHERE status = "active"
         ORDER BY triggered_at DESC
         LIMIT 1'
    );
    $stmt->execute();
    $row = $stmt->fetch();

    echo json_encode([
        'success'    => true,
        'active'     => $row ?: null,   // null = no active alert
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// POST — route to trigger or clear
// ================================================================
function handlePost(): void
{
    // Parse JSON body
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
        return;
    }

    // ---- CSRF verification (single-use, rotated on every call) --
    // Accept from JSON body OR X-CSRF-Token header
    $submittedToken = $data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

    if (!csrf_verify($submittedToken)) {
        // csrf_verify() already rotated (deleted) the old token on failure,
        // so we issue a fresh one the client can retry with
        http_response_code(403);
        echo json_encode([
            'success'    => false,
            'message'    => 'Your session has expired. Please refresh the page and try again.',
            'csrf_token' => csrf_token(),
        ]);
        return;
    }

    // Dispatch
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
// TRIGGER — insert a new active emergency log row
// ================================================================
function handleTrigger(array $data): void
{
    $pdo = get_db_connection();

    // ---- Validate inputs ----------------------------------------

    $emergencyType = trim((string)($data['emergency_type'] ?? ''));
    $note          = trim((string)($data['note']           ?? ''));
    $zones         = trim((string)($data['zones']          ?? 'All'));

    // Whitelist the type — only accept values matching the ENUM
    if (!in_array($emergencyType, ALLOWED_TYPES, true)) {
        http_response_code(400);
        echo json_encode([
            'success'    => false,
            'message'    => 'Invalid emergency type.',
            'csrf_token' => csrf_token(),
        ]);
        return;
    }

    // Length caps
    if (strlen($note) > 500)  $note  = substr($note,  0, 500);
    if (strlen($zones) > 100) $zones = substr($zones, 0, 100);

    // ---- Block duplicate active alerts --------------------------
    // Only one alert can be active at a time — prevent double-firing.
    $check = $pdo->prepare(
        'SELECT log_id FROM tbl_emergency_logs WHERE status = "active" LIMIT 1'
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

    // ---- Author from session — never trust the client -----------
    $userId       = (string)$_SESSION['user_id'];
    $operatorName = (string)$_SESSION['full_name'];

    // ---- Insert -------------------------------------------------
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_emergency_logs
           (emergency_type, note, zones, triggered_by, operator_name, status)
         VALUES
           (:type, :note, :zones, :triggered_by, :operator_name, "active")'
    );
    $stmt->execute([
        ':type'          => $emergencyType,
        ':note'          => $note ?: null,   // store NULL for empty notes
        ':zones'         => $zones,
        ':triggered_by'  => $userId,
        ':operator_name' => $operatorName,
    ]);

    $newId = (int)$pdo->lastInsertId();

    echo json_encode([
        'success'    => true,
        'message'    => $emergencyType . ' activated — All zones notified!',
        'log'        => [
            'log_id'          => $newId,
            'emergency_type'  => $emergencyType,
            'note'            => $note,
            'zones'           => $zones,
            'operator_name'   => $operatorName,
            'status'          => 'active',
            'triggered_at'    => date('Y-m-d H:i:s'),
            'triggered_at_fmt'=> date('M j, Y — g:i A'),
            'duration_min'    => null,
            'cleared_at'      => null,
            'cleared_at_fmt'  => null,
        ],
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// CLEAR — mark an active alert as cleared and compute duration
// ================================================================
function handleClear(array $data): void
{
    $pdo = get_db_connection();

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

    // Confirm the row exists and is still active
    $check = $pdo->prepare(
        'SELECT log_id, triggered_at FROM tbl_emergency_logs
         WHERE log_id = :id AND status = "active"
         LIMIT 1'
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

    // Calculate how long the alert was active, rounded up to the nearest minute
    $triggeredAt  = strtotime($row['triggered_at']);
    $now          = time();
    $durationMin  = max(1, (int)ceil(($now - $triggeredAt) / 60));
    $clearedAt    = date('Y-m-d H:i:s', $now);

    // Update the row: set cleared status, timestamp, and computed duration
    $pdo->prepare(
        'UPDATE tbl_emergency_logs
         SET status       = "cleared",
             cleared_at   = :cleared_at,
             duration_min = :duration_min,
             updated_by   = :updated_by
         WHERE log_id = :id'
    )->execute([
        ':cleared_at'   => $clearedAt,
        ':duration_min' => $durationMin,
        ':updated_by'   => (string)$_SESSION['user_id'],
        ':id'           => $logId,
    ]);

    echo json_encode([
        'success'       => true,
        'message'       => 'Alert cleared.',
        'log_id'        => $logId,
        'duration_min'  => $durationMin,
        'cleared_at_fmt'=> date('M j, Y — g:i A', $now),
        'csrf_token'    => csrf_token(),
    ]);
}
