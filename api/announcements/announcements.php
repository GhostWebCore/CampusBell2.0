<?php
/**
 * api/announcements/announcements.php
 *
 * Single-file REST-ish endpoint for the Announcements feature.
 *
 * Supported actions (all via POST with a JSON body, except 'list'):
 *
 *   POST  { action: 'create', csrf_token, title, body, type, audience }
 *         → Creates a new announcement. Requires active session.
 *
 *   GET   ?action=list[&type=general|urgent|event][&q=search+term]
 *         → Returns paginated announcements, newest first.
 *           Supports optional type filter and keyword search.
 *           Also requires an active session (checked server-side).
 *
 * Security measures:
 *   - require_login.php halts unauthenticated requests before any logic runs
 *   - CSRF token verified on every state-changing request (create)
 *   - All DB values use PDO prepared statements — no string-built SQL
 *   - Output is always JSON; errors never leak stack traces or file paths
 *   - Input is validated and length-capped before touching the DB
 */

declare(strict_types=1);

// Always respond as JSON, regardless of what happens below
header('Content-Type: application/json');

// ----------------------------------------------------------------
// Fatal-error safety net:
// If a require_once path is wrong, or PHP has a parse error, the
// shutdown function converts what would be a blank response into
// a real JSON error the client can actually display.
// ----------------------------------------------------------------
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        // Log the real error server-side; send a generic message to the client
        error_log('announcements.php fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
});

// ----------------------------------------------------------------
// Bootstrap: session + auth guard + helpers
// ----------------------------------------------------------------

// Starts or resumes the hardened session (cookie flags, ID regen)
require_once __DIR__ . '/../../config/session.php';

// Halts with 401 JSON or redirect if no valid session exists.
// Also refreshes $_SESSION['last_activity'] on pass-through.
require_once __DIR__ . '/../../includes/require_login.php';

// PDO connection factory
require_once __DIR__ . '/../../config/database.php';

// csrf_token() and csrf_verify()
require_once __DIR__ . '/../../includes/csrf.php';

// ----------------------------------------------------------------
// Route by HTTP method
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
    // Catch any uncaught exception (e.g. PDOException from a dropped DB connection)
    // and return a clean JSON error rather than an empty body or HTML stacktrace.
    error_log('announcements.php exception: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
}


// ================================================================
// ACTION: LIST / FILTER
// GET ?action=list[&type=general|urgent|event][&q=keyword][&page=1]
// ================================================================
function handleList(): void
{
    $pdo = get_db_connection();

    // --- Query params -------------------------------------------

    // Optional type filter — only accept known values to prevent SQL injection
    // (we also use a prepared statement, but whitelisting is defence-in-depth)
    $allowedTypes = ['general', 'urgent', 'event'];
    $typeFilter   = $_GET['type'] ?? '';
    if ($typeFilter !== '' && !in_array($typeFilter, $allowedTypes, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid type filter.']);
        return;
    }

    // Optional keyword search — sanitise length to prevent abuse
    $keyword = trim($_GET['q'] ?? '');
    if (strlen($keyword) > 200) {
        $keyword = substr($keyword, 0, 200);
    }

    // Simple pagination: 20 items per page, default page 1
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;

    // --- Build the query dynamically but safely ------------------
    // We always exclude soft-deleted rows (is_deleted = 0).
    // Additional WHERE clauses are added only when the filter/search
    // is actually provided.

    $where  = ['a.is_deleted = 0'];
    $params = [];

    if ($typeFilter !== '') {
        // Bind type as a string parameter — PDO handles quoting
        $where[]          = 'a.type = :type';
        $params[':type']  = $typeFilter;
    }

    if ($keyword !== '') {
        // LIKE search across title and body.
        // The % wildcards are added to the *value*, not the SQL string,
        // so they are safely escaped by the prepared statement.
        $where[]             = '(a.title LIKE :kw OR a.body LIKE :kw)';
        $params[':kw']       = '%' . $keyword . '%';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // Total count (for pagination metadata)
    $countSql  = "SELECT COUNT(*) FROM tbl_announcements a $whereSql";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Actual rows — newest first
    $dataSql  = "
        SELECT
            a.ann_id,
            a.title,
            a.body,
            a.type,
            a.audience,
            a.author_name,
            a.created_at
        FROM tbl_announcements a
        $whereSql
        ORDER BY a.created_at DESC
        LIMIT  :limit
        OFFSET :offset
    ";

    $dataStmt = $pdo->prepare($dataSql);

    // Bind the filter/search params (if any)
    foreach ($params as $key => $val) {
        $dataStmt->bindValue($key, $val, PDO::PARAM_STR);
    }

    // LIMIT and OFFSET must be bound as integers — PDO won't quote them
    $dataStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $dataStmt->execute();

    $rows = $dataStmt->fetchAll();

    // Format timestamps into a human-friendly string for the client.
    // The raw ISO timestamp is also included so JS can reformat if needed.
    foreach ($rows as &$row) {
        $row['created_at_formatted'] = formatTimestamp($row['created_at']);
    }
    unset($row); // break the reference

    // Issue a fresh CSRF token so the "New Announcement" form
    // always has a valid token even after the list refreshes.
    echo json_encode([
        'success'    => true,
        'data'       => $rows,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'csrf_token' => csrf_token(), // single-use token for the next POST
    ]);
}


// ================================================================
// ACTION: CREATE
// POST { action: 'create', csrf_token, title, body, type, audience }
// ================================================================
function handlePost(): void
{
    // --- Parse JSON body ----------------------------------------
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
        return;
    }

    // --- Confirm the action is one we support -------------------
    $action = trim((string)($data['action'] ?? ''));
    if ($action !== 'create') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        return;
    }

    // --- CSRF verification (single-use token) -------------------
    // Token can come from the JSON body OR the X-CSRF-Token header
    $submittedToken = $data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

    if (!csrf_verify($submittedToken)) {
        // csrf_verify() already rotated (deleted) the old token on failure,
        // so the response includes a fresh one for the client to use next time
        http_response_code(403);
        echo json_encode([
            'success'    => false,
            'message'    => 'Your session has expired. Please refresh the page and try again.',
            'csrf_token' => csrf_token(),
        ]);
        return;
    }

    // --- Input validation ---------------------------------------
    $title    = trim((string)($data['title']    ?? ''));
    $body     = trim((string)($data['body']     ?? ''));
    $type     = trim((string)($data['type']     ?? 'general'));
    $audience = trim((string)($data['audience'] ?? 'All Zones'));

    // Required fields
    if ($title === '' || $body === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Title and body are required.', 'csrf_token' => csrf_token()]);
        return;
    }

    // Length caps — prevents both DB errors and abuse
    if (strlen($title) > 255) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Title must be 255 characters or fewer.', 'csrf_token' => csrf_token()]);
        return;
    }
    if (strlen($body) > 5000) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Body must be 5000 characters or fewer.', 'csrf_token' => csrf_token()]);
        return;
    }
    if (strlen($audience) > 100) {
        $audience = substr($audience, 0, 100);
    }

    // Whitelist the type to prevent garbage data in the ENUM column
    $allowedTypes = ['general', 'urgent', 'event'];
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'general'; // silently coerce to the safe default
    }

    // --- Pull author info from the validated session -------------
    // Never trust client-supplied author names; always read from session.
    $userId     = (string)$_SESSION['user_id'];
    $authorName = (string)$_SESSION['full_name'];

    // --- Insert -------------------------------------------------
    $pdo = get_db_connection();

    $stmt = $pdo->prepare(
        'INSERT INTO tbl_announcements
            (created_by, author_name, title, body, type, audience)
         VALUES
            (:created_by, :author_name, :title, :body, :type, :audience)'
    );

    $stmt->execute([
        ':created_by'  => $userId,
        ':author_name' => $authorName,
        ':title'       => $title,
        ':body'        => $body,
        ':type'        => $type,
        ':audience'    => $audience,
    ]);

    $newId = (int)$pdo->lastInsertId();

    // Return the full new row so the client can prepend it to the list
    // without needing a separate GET request.
    echo json_encode([
        'success'    => true,
        'message'    => 'Announcement published.',
        'ann'        => [
            'ann_id'              => $newId,
            'title'               => $title,
            'body'                => $body,
            'type'                => $type,
            'audience'            => $audience,
            'author_name'         => $authorName,
            'created_at'          => date('Y-m-d H:i:s'),
            'created_at_formatted'=> 'Just now',
        ],
        // Issue a fresh single-use token so the form can be submitted again
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// Helper: human-friendly relative timestamp
// ================================================================
function formatTimestamp(string $dbTimestamp): string
{
    $ts  = strtotime($dbTimestamp);
    $now = time();
    $diff = $now - $ts;

    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        $m = (int)($diff / 60);
        return $m . ' min' . ($m !== 1 ? 's' : '') . ' ago';
    }

    $today     = strtotime('today midnight');
    $yesterday = strtotime('yesterday midnight');

    if ($ts >= $today) {
        return 'Today, ' . date('g:i A', $ts);
    }
    if ($ts >= $yesterday) {
        return 'Yesterday, ' . date('g:i A', $ts);
    }

    // Older than yesterday: show the date
    return date('M j, Y', $ts);
}
