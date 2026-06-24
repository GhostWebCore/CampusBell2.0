<?php
/**
 * api/events/events.php  –  PostgreSQL version
 */

declare(strict_types=1);

header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        error_log('events.php fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
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
        $action = trim($_GET['action'] ?? 'list');
        match ($action) {
            'list'     => handleList(),
            'upcoming' => handleUpcoming(),
            default    => (function () {
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
    error_log('events.php exception: ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
}


// ================================================================
// GET ?action=list&year=YYYY&month=M
// ================================================================
function handleList(): void
{
    $pdo = get_db_connection();

    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));

    if ($year < 2020 || $year > 2100) $year  = (int)date('Y');
    if ($month < 1   || $month > 12)  $month = (int)date('n');

    $firstDay = sprintf('%04d-%02d-01', $year, $month);
    $lastDay  = date('Y-m-t', strtotime($firstDay));

    // MySQL: is_deleted = 0  →  PostgreSQL: is_deleted = FALSE
    $stmt = $pdo->prepare(
        'SELECT
             event_id,
             event_title,
             description,
             event_date,
             color,
             bell_impact
         FROM tbl_events
         WHERE is_deleted = FALSE
           AND event_date BETWEEN :first_day AND :last_day
         ORDER BY event_date ASC, created_at ASC'
    );
    $stmt->execute([':first_day' => $firstDay, ':last_day' => $lastDay]);
    $rows = $stmt->fetchAll();

    $mapped = [];
    foreach ($rows as $row) {
        [$y, $m, $d] = explode('-', $row['event_date']);
        $key = (int)$y . '-' . (int)$m . '-' . (int)$d;

        $mapped[$key][] = [
            'event_id'    => (int)$row['event_id'],
            'text'        => $row['event_title'],
            'description' => $row['description'] ?? '',
            'color'       => $row['color'],
            'bell_impact' => $row['bell_impact'],
            'date'        => $row['event_date'],
        ];
    }

    echo json_encode([
        'success'    => true,
        'year'       => $year,
        'month'      => $month,
        'events'     => $mapped,
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// GET ?action=upcoming[&limit=N]
// ================================================================
function handleUpcoming(): void
{
    $pdo = get_db_connection();

    $limit = max(1, min(20, (int)($_GET['limit'] ?? 5)));
    $today = date('Y-m-d');

    // MySQL: is_deleted = 0  →  PostgreSQL: is_deleted = FALSE
    $stmt = $pdo->prepare(
        'SELECT
             event_id,
             event_title,
             description,
             event_date,
             color,
             bell_impact
         FROM tbl_events
         WHERE is_deleted = FALSE
           AND event_date >= :today
         ORDER BY event_date ASC
         LIMIT :limit'
    );
    $stmt->bindValue(':today', $today, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['event_id']       = (int)$row['event_id'];
        $row['date_formatted'] = date('M j', strtotime($row['event_date']));
    }
    unset($row);

    echo json_encode([
        'success'    => true,
        'upcoming'   => $rows,
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// POST — route to create or delete
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

    $title       = trim((string)($data['event_title']  ?? ''));
    $description = trim((string)($data['description']  ?? ''));
    $eventDate   = trim((string)($data['event_date']   ?? ''));
    $color       = trim((string)($data['color']        ?? 'blue'));
    $bellImpact  = trim((string)($data['bell_impact']  ?? 'none'));

    if ($title === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Event title is required.', 'csrf_token' => csrf_token()]);
        return;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate) || !strtotime($eventDate)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid event date. Use YYYY-MM-DD format.', 'csrf_token' => csrf_token()]);
        return;
    }

    if (strlen($title) > 180) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Title must be 180 characters or fewer.', 'csrf_token' => csrf_token()]);
        return;
    }
    if (strlen($description) > 3000) {
        $description = substr($description, 0, 3000);
    }

    $allowedColors = ['blue', 'green', 'amber', 'purple'];
    if (!in_array($color, $allowedColors, true)) $color = 'blue';

    $allowedImpacts = ['none', 'modified', 'suspended', 'override'];
    if (!in_array($bellImpact, $allowedImpacts, true)) $bellImpact = 'none';

    $userId = (string)$_SESSION['user_id'];

    // MySQL: $pdo->lastInsertId()  →  PostgreSQL: RETURNING event_id
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_events
             (event_title, description, event_date, color, bell_impact, created_by, updated_by)
         VALUES
             (:event_title, :description, :event_date, :color, :bell_impact, :created_by, :updated_by)
         RETURNING event_id'
    );
    $stmt->execute([
        ':event_title'  => $title,
        ':description'  => $description ?: null,
        ':event_date'   => $eventDate,
        ':color'        => $color,
        ':bell_impact'  => $bellImpact,
        ':created_by'   => $userId,
        ':updated_by'   => $userId,
    ]);

    $newId = (int)$stmt->fetchColumn();

    [$y, $m, $d] = explode('-', $eventDate);

    echo json_encode([
        'success'    => true,
        'message'    => 'Event added.',
        'event'      => [
            'event_id'       => $newId,
            'text'           => $title,
            'description'    => $description,
            'color'          => $color,
            'bell_impact'    => $bellImpact,
            'date'           => $eventDate,
            'date_formatted' => date('M j', strtotime($eventDate)),
            'map_key'        => (int)$y . '-' . (int)$m . '-' . (int)$d,
        ],
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// DELETE — soft-delete (sets is_deleted = TRUE)
// ================================================================
function handleDelete(array $data): void
{
    $pdo = get_db_connection();

    $eventId = (int)($data['event_id'] ?? 0);

    if ($eventId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid event ID.', 'csrf_token' => csrf_token()]);
        return;
    }

    // MySQL: is_deleted = 0  →  PostgreSQL: is_deleted = FALSE
    $check = $pdo->prepare(
        'SELECT event_title FROM tbl_events WHERE event_id = :id AND is_deleted = FALSE LIMIT 1'
    );
    $check->execute([':id' => $eventId]);
    $row = $check->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Event not found.', 'csrf_token' => csrf_token()]);
        return;
    }

    // MySQL: is_deleted = 1  →  PostgreSQL: is_deleted = TRUE
    $stmt = $pdo->prepare(
        'UPDATE tbl_events
         SET is_deleted = TRUE, updated_by = :updated_by
         WHERE event_id = :id'
    );
    $stmt->execute([
        ':updated_by' => (string)$_SESSION['user_id'],
        ':id'         => $eventId,
    ]);

    /* Hard-delete alternative (uncomment if you prefer):
    $pdo->prepare('DELETE FROM tbl_events WHERE event_id = :id')
        ->execute([':id' => $eventId]);
    */

    echo json_encode([
        'success'    => true,
        'message'    => '"' . $row['event_title'] . '" removed.',
        'csrf_token' => csrf_token(),
    ]);
}