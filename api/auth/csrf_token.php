<?php
/**
 * auth/csrf_token.php
 *
 * GET endpoint the login page calls on load to obtain a fresh CSRF
 * token before the user submits the form.
 *
 * IMPORTANT — debugging an empty/blank JSON response:
 * If you're seeing "Unexpected end of JSON input" in the browser
 * console, it means this script returned ZERO bytes instead of JSON.
 * That happens when PHP hits a fatal error before reaching the final
 * echo, and your php.ini has display_errors off (normal in most
 * default Apache/XAMPP/WAMP setups). The try/catch and shutdown
 * handler below convert that into a real JSON error response so you
 * can actually see what's wrong instead of a blank page.
 *
 * To diagnose, open this file directly in the browser:
 *   http://localhost/bell/api/auth/csrf_token.php
 * You should see {"csrf_token":"...some hex string..."}. If you see
 * a blank page there too, check your PHP error log (not the browser)
 * for the real fatal error — common causes are a wrong require_once
 * path, or session.php/csrf.php not being readable.
 */

declare(strict_types=1);

// Always respond as JSON, no matter what happens below.
header('Content-Type: application/json');

// Catch any fatal error (e.g. a bad require_once path) and turn it
// into a JSON body instead of a blank response or an HTML error page.
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        // Don't leak full file paths/stack traces to the client in production —
        // log the detail server-side, send a generic message to the browser.
        error_log('csrf_token.php fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        echo json_encode([
            'success' => false,
            'message' => 'Server error while generating security token.',
        ]);
    }
});

try {
    require_once __DIR__ . '/../../config/session.php';
    require_once __DIR__ . '/../../includes/csrf.php';

    echo json_encode(['csrf_token' => csrf_token()]);
} catch (Throwable $e) {
    error_log('csrf_token.php exception: ' . $e->getMessage());
    http_response_code(500);
    
}
