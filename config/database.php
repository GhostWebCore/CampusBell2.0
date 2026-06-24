<?php
/**
 * config/database.php
 *
 * Single PDO connection factory. Keep credentials out of version control —
 * in real deployment, pull these from environment variables instead of
 * hardcoding them here.
 */

declare(strict_types=1);

function get_db_connection(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $host    = getenv('DB_HOST') ?: 'sql312.infinityfree.com';
    $dbname  = getenv('DB_NAME') ?: 'if0_42257174_campusbell';
    $user    = getenv('DB_USER') ?: 'if0_42257174';
    $pass    = getenv('DB_PASS') ?: 'iSVqC0CBsml7';
    $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // use real prepared statements
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        // Never leak DB connection details to the client.
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
        exit;
    }

    return $pdo;
}
