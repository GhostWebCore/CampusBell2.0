<?php
/**
 * config/session.php
 *
 * Centralized, hardened session start. Every entry point includes this
 * instead of calling session_start() directly, so cookie flags stay
 * consistent everywhere.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {

    // Detect HTTPS (covers reverse proxies that set X-Forwarded-Proto)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,            // expires when browser closes
        'path'     => '/',
        'domain'   => '',           // current domain only
        'secure'   => $isHttps,     // cookie only sent over HTTPS in production
        'httponly' => true,         // not accessible to JavaScript (mitigates XSS theft)
        'samesite' => 'Lax',        // mitigates CSRF on cross-site requests
    ]);

    session_name('campusbell_sid');
    session_start();

    // Regenerate the session ID periodically to limit session fixation /
    // hijacking windows, without destroying the data already in it.
    if (empty($_SESSION['_last_regen'])) {
        $_SESSION['_last_regen'] = time();
    } elseif (time() - $_SESSION['_last_regen'] > 900) { // every 15 minutes
        session_regenerate_id(true);
        $_SESSION['_last_regen'] = time();
    }
}
