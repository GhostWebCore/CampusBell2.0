<?php
/**
 * includes/csrf.php
 *
 * Synchronizer-token-pattern CSRF protection.
 * - csrf_token()  generates (or reuses) a per-session token, base64 of
 *   32 random bytes from a CSPRNG.
 * - csrf_verify() compares the token sent by the client against the one
 *   stored in the session using a timing-safe comparison.
 *
 * The token is delivered to the page that renders the login form, sent
 * back by the client on every state-changing request (here: login),
 * and rejected/rotated on use so a captured token can't be replayed
 * indefinitely.
 */

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $submittedToken): bool
{
    if (empty($_SESSION['csrf_token']) || empty($submittedToken)) {
        return false;
    }

    $valid = hash_equals($_SESSION['csrf_token'], $submittedToken);

    // Rotate the token after every verification attempt (success or fail)
    // so a leaked/sniffed token is only ever usable once.
    unset($_SESSION['csrf_token']);

    return $valid;
}
