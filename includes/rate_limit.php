<?php
/**
 * includes/rate_limit.php
 *
 * Throttles login attempts per username AND per IP address, so an
 * attacker can't brute force one account quickly, or spray many
 * accounts quickly from one IP. Backed by a small dedicated table
 * (see schema_updates.sql) rather than session storage, since
 * sessions are per-browser and won't stop a scripted attacker who
 * never sends cookies back.
 */

declare(strict_types=1);

const RATE_LIMIT_MAX_ATTEMPTS = 5;
const RATE_LIMIT_WINDOW_SECS  = 15 * 60; // 15 minutes
const RATE_LIMIT_LOCKOUT_SECS = 15 * 60; // lockout duration once tripped

function client_ip(): string
{
    // Behind a trusted reverse proxy you'd read X-Forwarded-For here instead,
    // but only after validating it comes from a trusted proxy IP.
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Returns true if the given username/IP pair is currently allowed to attempt
 * a login, false if they're locked out.
 */
function is_rate_limited(PDO $pdo, string $username, string $ip): bool
{
    $stmt = $pdo->prepare(
        'SELECT attempt_count, first_attempt_at, locked_until
         FROM tbl_login_attempts
         WHERE username = :username AND ip_address = :ip
         LIMIT 1'
    );
    $stmt->execute(['username' => $username, 'ip' => $ip]);
    $row = $stmt->fetch();

    if (!$row) {
        return false;
    }

    if ($row['locked_until'] !== null && strtotime($row['locked_until']) > time()) {
        return true;
    }

    // Lockout window expired naturally — treat as not limited; record_attempt
    // will reset the counter on the next failure.
    return false;
}

/**
 * Call after a FAILED login attempt. Increments the counter and, once the
 * threshold is crossed inside the time window, sets a lockout expiry.
 */
function record_failed_attempt(PDO $pdo, string $username, string $ip): void
{
    $stmt = $pdo->prepare(
        'SELECT attempt_count, first_attempt_at
         FROM tbl_login_attempts
         WHERE username = :username AND ip_address = :ip
         LIMIT 1'
    );
    $stmt->execute(['username' => $username, 'ip' => $ip]);
    $row = $stmt->fetch();

    $now = time();

    if (!$row) {
        $pdo->prepare(
            'INSERT INTO tbl_login_attempts (username, ip_address, attempt_count, first_attempt_at, locked_until)
             VALUES (:username, :ip, 1, NOW(), NULL)'
        )->execute(['username' => $username, 'ip' => $ip]);
        return;
    }

    $windowExpired = (time() - strtotime($row['first_attempt_at'])) > RATE_LIMIT_WINDOW_SECS;

    if ($windowExpired) {
        // Start a fresh window.
        $pdo->prepare(
            'UPDATE tbl_login_attempts
             SET attempt_count = 1, first_attempt_at = NOW(), locked_until = NULL
             WHERE username = :username AND ip_address = :ip'
        )->execute(['username' => $username, 'ip' => $ip]);
        return;
    }

    $newCount = (int)$row['attempt_count'] + 1;
    $lockedUntil = null;

    if ($newCount >= RATE_LIMIT_MAX_ATTEMPTS) {
        $lockedUntil = date('Y-m-d H:i:s', $now + RATE_LIMIT_LOCKOUT_SECS);
    }

    $pdo->prepare(
        'UPDATE tbl_login_attempts
         SET attempt_count = :count, locked_until = :locked_until
         WHERE username = :username AND ip_address = :ip'
    )->execute([
        'count'        => $newCount,
        'locked_until' => $lockedUntil,
        'username'     => $username,
        'ip'           => $ip,
    ]);
}

/** Call after a SUCCESSFUL login to clear the slate. */
function clear_failed_attempts(PDO $pdo, string $username, string $ip): void
{
    $pdo->prepare(
        'DELETE FROM tbl_login_attempts WHERE username = :username AND ip_address = :ip'
    )->execute(['username' => $username, 'ip' => $ip]);
}
