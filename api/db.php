<?php
// Shared PostgreSQL/PostGIS connection. Reads credentials from environment
// variables only — never commit real credentials into this file. For local
// development, set these in a git-ignored .env file (see .env.example); in
// production, set them as real environment variables on the host.
require_once __DIR__ . '/load_env.php';

$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_PORT = getenv('DB_PORT') ?: '5432';
$DB_NAME = getenv('DB_NAME') ?: 'hyra_delivery';
$DB_USER = getenv('DB_USER') ?: 'postgres';
$DB_PASS = getenv('DB_PASS');

if (!$DB_PASS) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'DB_PASS environment variable is not set']);
    exit;
}

function get_pdo(): PDO {
    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS;
    $dsn = "pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    return $pdo;
}

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data);
    exit;
}
