<?php
/**
 * api/announcements/announcements.php  –  PostgreSQL version
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
        error_log('announcements.php fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
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
    error_log('announcements.php exception: ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
}


// ================================================================
// GET ?action=list[&type=...][&q=...][&page=1]
// ================================================================
function handleList(): void
{
    $pdo = get_db_connection();

    $allowedTypes = ['general', 'urgent', 'event'];
    $typeFilter   = $_GET['type'] ?? '';
    if ($typeFilter !== '' && !in_array($typeFilter, $allowedTypes, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid type filter.']);
        return;
    }

    $keyword = trim($_GET['q'] ?? '');
    if (strlen($keyword) > 200) $keyword = substr($keyword, 0, 200);

    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;

    // MySQL: is_deleted = 0  →  PostgreSQL: is_deleted = FALSE
    $where  = ['a.is_deleted = FALSE'];
    $params = [];

    if ($typeFilter !== '') {
        $where[]         = 'a.type = :type';
        $params[':type'] = $typeFilter;
    }

    if ($keyword !== '') {
        // MySQL: LIKE  →  PostgreSQL: ILIKE (case-insensitive search)
        $where[]       = '(a.title ILIKE :kw OR a.body ILIKE :kw)';
        $params[':kw'] = '%' . $keyword . '%';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $countSql  = "SELECT COUNT(*) FROM tbl_announcements a $whereSql";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $dataSql = "
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
    foreach ($params as $key => $val) {
        $dataStmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $dataStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $dataStmt->execute();

    $rows = $dataStmt->fetchAll();

    foreach ($rows as &$row) {
        $row['created_at_formatted'] = formatTimestamp($row['created_at']);
    }
    unset($row);

    echo json_encode([
        'success'    => true,
        'data'       => $rows,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// POST { action: 'create', ... }
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

    $action = trim((string)($data['action'] ?? ''));
    if ($action !== 'create') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
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

    $title    = trim((string)($data['title']    ?? ''));
    $body     = trim((string)($data['body']     ?? ''));
    $type     = trim((string)($data['type']     ?? 'general'));
    $audience = trim((string)($data['audience'] ?? 'All Zones'));

    if ($title === '' || $body === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Title and body are required.', 'csrf_token' => csrf_token()]);
        return;
    }
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
    if (strlen($audience) > 100) $audience = substr($audience, 0, 100);

    $allowedTypes = ['general', 'urgent', 'event'];
    if (!in_array($type, $allowedTypes, true)) $type = 'general';

    $userId     = (string)$_SESSION['user_id'];
    $authorName = (string)$_SESSION['full_name'];

    $pdo = get_db_connection();

    // MySQL: $pdo->lastInsertId()  →  PostgreSQL: RETURNING ann_id
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_announcements
             (created_by, author_name, title, body, type, audience)
         VALUES
             (:created_by, :author_name, :title, :body, :type, :audience)
         RETURNING ann_id'
    );
    $stmt->execute([
        ':created_by'  => $userId,
        ':author_name' => $authorName,
        ':title'       => $title,
        ':body'        => $body,
        ':type'        => $type,
        ':audience'    => $audience,
    ]);

    $newId = (int)$stmt->fetchColumn();

    echo json_encode([
        'success'    => true,
        'message'    => 'Announcement published.',
        'ann'        => [
            'ann_id'               => $newId,
            'title'                => $title,
            'body'                 => $body,
            'type'                 => $type,
            'audience'             => $audience,
            'author_name'          => $authorName,
            'created_at'           => date('Y-m-d H:i:s'),
            'created_at_formatted' => 'Just now',
        ],
        'csrf_token' => csrf_token(),
    ]);
}


// ================================================================
// Helper: human-friendly relative timestamp
// (Pure PHP — no changes needed)
// ================================================================
function formatTimestamp(string $dbTimestamp): string
{
    $ts   = strtotime($dbTimestamp);
    $now  = time();
    $diff = $now - $ts;

    if ($diff < 60)   return 'Just now';
    if ($diff < 3600) {
        $m = (int)($diff / 60);
        return $m . ' min' . ($m !== 1 ? 's' : '') . ' ago';
    }

    $today     = strtotime('today midnight');
    $yesterday = strtotime('yesterday midnight');

    if ($ts >= $today)     return 'Today, '     . date('g:i A', $ts);
    if ($ts >= $yesterday) return 'Yesterday, ' . date('g:i A', $ts);

    return date('M j, Y', $ts);
}