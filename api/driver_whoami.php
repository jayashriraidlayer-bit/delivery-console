<?php
require __DIR__ . '/db.php';

// GET /api/driver_whoami.php?token=<access_token>
// Public (no session) — a driver's own phone doesn't have a dashboard
// login, only its private token. Deliberately returns just the driver's
// name, nothing else about the company, zones, or other drivers.
$token = $_GET['token'] ?? '';
if ($token === '') {
    json_response(['error' => 'token is required'], 400);
}

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT name FROM drivers WHERE access_token = :token");
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        json_response(['error' => 'invalid token'], 404);
    }

    json_response(['name' => $row['name']]);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
