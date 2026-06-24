<?php
/**
 * api/events/events.php
 *
 * Single-file endpoint for the Events Calendar feature.
 *
 * ┌────────┬────────────────────────────────────────────────────────────┐
 * │ Method │ Action / Payload                                           │
 * ├────────┼────────────────────────────────────────────────────────────┤
 * │ GET    │ ?action=list&year=YYYY&month=M                             │
 * │        │ Returns every visible event in the given calendar month,   │
 * │        │ keyed by "YYYY-M-D" so the JS calendar can look them up   │
 * │        │ directly without client-side iteration.                    │
 * ├────────┼────────────────────────────────────────────────────────────┤
 * │ GET    │ ?action=upcoming[&limit=N]                                 │
 * │        │ Returns the next N upcoming events (today onward),         │
 * │        │ formatted for the "Upcoming Events" list below the grid.   │
 * ├────────┼────────────────────────────────────────────────────────────┤
 * │ POST   │ { action:'create', csrf_token, event_title, description,   │
 * │        │   event_date, color, bell_impact }                         │
 * │        │ Creates a new event row.                                   │
 * ├────────┼────────────────────────────────────────────────────────────┤
 * │ POST   │ { action:'delete', csrf_token, event_id }                  │
 * │        │ Soft-deletes a single event (sets is_deleted = 1).         │
 * └────────┴────────────────────────────────────────────────────────────┘
 *
 * Security applied:
 *   - require_login.php halts unauthenticated requests before any logic
 *   - CSRF token verified and rotated on every state-changing POST
 *   - All DB values use PDO prepared statements — zero string-built SQL
 *   - Input is whitelisted and length-capped before reaching the DB
 *   - Author is always read from $_SESSION, never from the request body
 *   - Fatal errors and exceptions are caught and returned as clean JSON
 */

declare(strict_types=1);

// Always respond as JSON
header('Content-Type: application/json');

// ----------------------------------------------------------------
// Fatal-error safety net
// Converts PHP E_ERROR / E_PARSE fatals into JSON so the client
// never receives a blank body or an HTML stack trace.
// ----------------------------------------------------------------
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

// ----------------------------------------------------------------
// Bootstrap — always in this order
// ----------------------------------------------------------------
require_once __DIR__ . '/../../config/session.php';      // hardened session start
require_once __DIR__ . '/../../includes/require_login.php'; // auth guard
require_once __DIR__ . '/../../config/database.php';     // PDO factory
require_once __DIR__ . '/../../includes/csrf.php';       // csrf_token() / csrf_verify()

// ----------------------------------------------------------------
// Route by HTTP method
// ----------------------------------------------------------------
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        // Dispatch to the correct GET sub-handler based on ?action=
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
// Returns events for one calendar month, keyed by "YYYY-M-D".
// ================================================================
function handleList(): void
{
    $pdo = get_db_connection();

    // Validate year and month — clamp to sane ranges
    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));  // 1-based (1=Jan, 12=Dec)

    if ($year < 2020 || $year > 2100) $year  = (int)date('Y');
    if ($month < 1   || $month > 12)  $month = (int)date('n');

    // Calculate the first and last DATE of the requested month
    // so the WHERE clause is a clean DATE range (index-friendly)
    $firstDay = sprintf('%04d-%02d-01', $year, $month);
    $lastDay  = date('Y-m-t', strtotime($firstDay));  // 't' = last day of month

    $stmt = $pdo->prepare(
        'SELECT
             event_id,
             event_title,
             description,
             event_date,
             color,
             bell_impact
         FROM tbl_events
         WHERE is_deleted = 0
           AND event_date BETWEEN :first_day AND :last_day
         ORDER BY event_date ASC, created_at ASC'
    );
    $stmt->execute([':first_day' => $firstDay, ':last_day' => $lastDay]);
    $rows = $stmt->fetchAll();

    // Build the keyed map the JS calendar expects: { "YYYY-M-D": [ event, … ] }
    // Using non-zero-padded month/day (e.g. "2026-6-5", not "2026-06-05")
    // to match the key format already used in the original calEvents object.
    $mapped = [];
    foreach ($rows as $row) {
        // Parse the stored DATE string to extract year, month, day integers
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
        'events'     => $mapped,          // keyed map for the calendar grid
        'csrf_token' => csrf_token(),     // fresh token for the "Add Event" form
    ]);
}


// ================================================================
// GET ?action=upcoming[&limit=N]
// Returns the next N events from today onward (max 20).
// ================================================================
function handleUpcoming(): void
{
    $pdo = get_db_connection();

    $limit = max(1, min(20, (int)($_GET['limit'] ?? 5)));
    $today = date('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT
             event_id,
             event_title,
             description,
             event_date,
             color,
             bell_impact
         FROM tbl_events
         WHERE is_deleted = 0
           AND event_date >= :today
         ORDER BY event_date ASC
         LIMIT :limit'
    );
    $stmt->bindValue(':today', $today, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Format dates for the upcoming list display
    foreach ($rows as &$row) {
        $row['event_id']      = (int)$row['event_id'];
        $row['date_formatted'] = date('M j', strtotime($row['event_date'])); // "Jun 20"
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
    // Parse JSON body
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
        return;
    }

    // ---- CSRF verification — single-use, rotated on every call --
    // Accept token from the JSON body or the X-CSRF-Token header
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

    // Dispatch to sub-handler
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
// CREATE — validate inputs and insert a new event row
// ================================================================
function handleCreate(array $data): void
{
    $pdo = get_db_connection();

    // ---- Validate and sanitise ----------------------------------

    $title       = trim((string)($data['event_title']  ?? ''));
    $description = trim((string)($data['description']  ?? ''));
    $eventDate   = trim((string)($data['event_date']   ?? ''));
    $color       = trim((string)($data['color']        ?? 'blue'));
    $bellImpact  = trim((string)($data['bell_impact']  ?? 'none'));

    // Required fields
    if ($title === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Event title is required.', 'csrf_token' => csrf_token()]);
        return;
    }

    // Validate date format: must be YYYY-MM-DD
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate) || !strtotime($eventDate)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid event date. Use YYYY-MM-DD format.', 'csrf_token' => csrf_token()]);
        return;
    }

    // Length caps
    if (strlen($title) > 180) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Title must be 180 characters or fewer.', 'csrf_token' => csrf_token()]);
        return;
    }
    if (strlen($description) > 3000) {
        $description = substr($description, 0, 3000);
    }

    // Whitelist color and bell_impact so only known ENUM values reach the DB
    $allowedColors = ['blue', 'green', 'amber', 'purple'];
    if (!in_array($color, $allowedColors, true)) $color = 'blue';

    $allowedImpacts = ['none', 'modified', 'suspended', 'override'];
    if (!in_array($bellImpact, $allowedImpacts, true)) $bellImpact = 'none';

    // Author is always taken from the validated session — never from the client
    $userId = (string)$_SESSION['user_id'];

    // ---- Insert -------------------------------------------------
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_events
           (event_title, description, event_date, color, bell_impact, created_by, updated_by)
         VALUES
           (:event_title, :description, :event_date, :color, :bell_impact, :created_by, :updated_by)'
    );
    $stmt->execute([
        ':event_title'  => $title,
        ':description'  => $description ?: null,   // store NULL if empty
        ':event_date'   => $eventDate,
        ':color'        => $color,
        ':bell_impact'  => $bellImpact,
        ':created_by'   => $userId,
        ':updated_by'   => $userId,
    ]);

    $newId = (int)$pdo->lastInsertId();

    // Parse the date into year/month/day ints for the JS map key
    [$y, $m, $d] = explode('-', $eventDate);

    echo json_encode([
        'success'    => true,
        'message'    => 'Event added.',
        'event'      => [
            'event_id'        => $newId,
            'text'            => $title,
            'description'     => $description,
            'color'           => $color,
            'bell_impact'     => $bellImpact,
            'date'            => $eventDate,
            'date_formatted'  => date('M j', strtotime($eventDate)),  // "Jun 20"
            // JS calendar map key format (non-padded)
            'map_key'         => (int)$y . '-' . (int)$m . '-' . (int)$d,
        ],
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// DELETE — soft-delete a single event (sets is_deleted = 1)
// Using soft-delete keeps audit history; uncomment the hard-delete
// variant below if you'd rather purge the row entirely.
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

    // Verify the row exists and isn't already deleted
    $check = $pdo->prepare(
        'SELECT event_title FROM tbl_events WHERE event_id = :id AND is_deleted = 0 LIMIT 1'
    );
    $check->execute([':id' => $eventId]);
    $row = $check->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Event not found.', 'csrf_token' => csrf_token()]);
        return;
    }

    // Soft-delete: mark as deleted and record who did it
    $stmt = $pdo->prepare(
        'UPDATE tbl_events
         SET is_deleted = 1, updated_by = :updated_by
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
