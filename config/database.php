<?php
declare(strict_types=1);

function get_db_connection(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $host   = getenv('DB_HOST') ?: 'dpg-d8tn8j67r5hc73aik9ig-a.oregon-postgres.render.com';
    $port   = getenv('DB_PORT') ?: '5432';
    $dbname = getenv('DB_NAME') ?: 'campus_bell';
    $user   = getenv('DB_USER') ?: 'campus_bell_user';
    $pass   = getenv('DB_PASS') ?: '4gP8Jmz7pVsGIgWyLRkucAfiKxGpy1o8';

    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());

        http_response_code(500);
        header('Content-Type: application/json');

        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed.'
        ]);

        exit;
    }

    return $pdo;
}
?>
