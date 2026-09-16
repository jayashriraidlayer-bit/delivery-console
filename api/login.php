<?php
require __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'method not allowed'], 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$email = trim($body['email'] ?? '');
$password = $body['password'] ?? '';

if ($email === '' || $password === '') {
    json_response(['error' => 'email and password are required'], 400);
}

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "SELECT id, company_id, email, password_hash, name FROM users WHERE email = :email"
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_response(['error' => 'invalid email or password'], 401);
    }

    start_session_if_needed();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['company_id'] = (int)$user['company_id'];
    $_SESSION['email'] = $user['email'];

    json_response([
        'user' => [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'company_id' => (int)$user['company_id'],
        ],
    ]);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
