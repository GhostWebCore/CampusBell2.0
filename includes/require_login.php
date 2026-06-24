<?php
/**
 * includes/require_login.php
 *
 * Include this at the very top of any page/endpoint that should only
 * be reachable by an authenticated user, e.g.:
 *
 *   require_once __DIR__ . '/includes/require_login.php';
 *
 * It does not render anything itself — it just halts with a 401/redirect
 * if there's no valid session, so the rest of the page never executes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';

$isLoggedIn = !empty($_SESSION['logged_in']) && !empty($_SESSION['user_id']);

// Optional: expire sessions after a period of inactivity even though the
// cookie itself is a session cookie. 30 minutes here as an example.
const IDLE_TIMEOUT_SECS = 30 * 60;

if ($isLoggedIn && !empty($_SESSION['login_time'])) {
    $idleFor = time() - (int)($_SESSION['last_activity'] ?? $_SESSION['login_time']);
    if ($idleFor > IDLE_TIMEOUT_SECS) {
        $_SESSION = [];
        session_destroy();
        $isLoggedIn = false;
    }
}

if (!$isLoggedIn) {
    // If this file is hit directly by an AJAX call, respond with JSON;
    // otherwise redirect a normal page load back to the login screen.
    $wantsJson = (
        (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') ||
        (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
    );

    if ($wantsJson) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    } else {
        header('Location: login.php');
    }
    exit;
}

$_SESSION['last_activity'] = time();
