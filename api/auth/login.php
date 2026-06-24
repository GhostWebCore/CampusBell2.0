<?php
/**
 * auth/login.php
 *
 * POST JSON endpoint: { email, pass, csrf_token }
 * (Field is called "email" on the frontend/in the request body for
 * backwards compatibility with the existing JS, but it's matched
 * against tbl_users.username — adjust if you add a real email column.)
 *
 * Response shape (always JSON, always same shape on failure so the
 * client can't distinguish "wrong password" from "unknown user"):
 *   { success: bool, message: string }
 *
 * Security measures applied here:
 *  - CSRF token required and single-use (includes/csrf.php)
 *  - Input validated/sanitized before use
 *  - Prepared statements only — no string-built SQL
 *  - password_verify() against a password_hash() hash — no plaintext
 *    comparison, ever
 *  - Generic error messages — doesn't reveal whether the username
 *    exists or the password was wrong
 *  - Rate limiting per username+IP (includes/rate_limit.php)
 *  - Session regenerated on successful login (prevents session fixation)
 *  - Only minimal, non-sensitive user data stored in the session
 */

declare(strict_types=1);

header('Content-Type: application/json');

// Guard against ever returning an empty body. If something fatals
// (e.g. a wrong require_once path, a DB connection class error, a
// typo'd function name) before we reach a normal exit, this converts
// what would otherwise be a blank response — which breaks res.json()
// on the client with "Unexpected end of JSON input" — into a real
// JSON error you can actually see in the Network tab.
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        error_log('login.php fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    }
});

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/rate_limit.php';

try {

// --- Method guard -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// --- Parse JSON body ------------------------------------------------
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
    exit;
}

// --- CSRF check -------------------------------------------------
// Token can arrive either in the JSON body (csrf_token) or in the
// X-CSRF-Token header — supporting both makes it easy to wire into
// the existing fetch() call with a minimal change.
$submittedToken = $data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

if (!csrf_verify($submittedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Please refresh the page and try again.']);
    exit;
}

// --- Input validation -------------------------------------------------
$username = trim((string)($data['email'] ?? ''));
$password = (string)($data['pass'] ?? '');

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter your username and password.']);
    exit;
}

// Defensive caps — nobody has a 300-character username typed by hand.
if (strlen($username) > 255 || strlen($password) > 1000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input.']);
    exit;
}

$pdo = get_db_connection();
$ip  = client_ip();

// --- Rate limit check (before touching the DB for the real lookup) ---
if (is_rate_limited($pdo, $username, $ip)) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Too many failed attempts. Please try again in 15 minutes.',
    ]);
    exit;
}

// --- Look up user -------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT user_id, username, userpassword, user_fname, user_lname, user_status
     FROM tbl_users
     WHERE username = :username
     LIMIT 1'
);
$stmt->execute(['username' => $username]);
$user = $stmt->fetch();

// --- Verify credentials -------------------------------------------------
// Always run password_verify() even when no user is found, using a dummy
// hash, so the response time doesn't leak whether the username exists
// (timing side-channel mitigation).
$dummyHash = '$2y$10$abcdefghijklmnopqrstuvKjY8tJYz1lU0sQbS5y0n8Q5e7m6gk0e6';
$hashToCheck = $user['userpassword'] ?? $dummyHash;
$passwordOk  = password_verify($password, $hashToCheck);

if (!$user || !$passwordOk) {
    record_failed_attempt($pdo, $username, $ip);
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    exit;
}

if ($user['user_status'] !== 'active') {
    record_failed_attempt($pdo, $username, $ip);
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This account is inactive. Contact your administrator.']);
    exit;
}

// --- Success -------------------------------------------------

clear_failed_attempts($pdo, $username, $ip);

// Prevent session fixation: issue a brand new session ID now that the
// user's privilege level is changing (anonymous -> authenticated).
session_regenerate_id(true);

$_SESSION['user_id']    = $user['user_id'];
$_SESSION['username']   = $user['username'];
$_SESSION['full_name']  = trim($user['user_fname'] . ' ' . $user['user_lname']);
$_SESSION['logged_in']  = true;
$_SESSION['login_time'] = time();

// Optional: rehash transparently if password_hash()'s default algorithm/cost
// has changed since this hash was created (keeps old accounts current
// without forcing a manual reset).
if (password_needs_rehash($hashToCheck, PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE tbl_users SET userpassword = :hash WHERE user_id = :id')
        ->execute(['hash' => $newHash, 'id' => $user['user_id']]);
}

$pdo->prepare('UPDATE tbl_users SET last_login_at = NOW() WHERE user_id = :id')
    ->execute(['id' => $user['user_id']]);

echo json_encode([
    'success' => true,
    'message' => 'Welcome back, ' . $_SESSION['full_name'] . '!',
    'user'    => [
        'id'   => $user['user_id'],
        'name' => $_SESSION['full_name'],
    ],
    // Issue a fresh CSRF token for the next state-changing request
    // (e.g. logout), since csrf_verify() consumed the old one.
    'csrf_token' => csrf_token(),
]);

} catch (Throwable $e) {
    // Catches thrown exceptions (e.g. PDOException from a bad query or
    // dropped DB connection) that register_shutdown_function above
    // won't see, since those only catch true fatal errors, not
    // exceptions. Same principle: never let the client receive a
    // blank/HTML body where JSON was expected.
    error_log('login.php exception: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
}
