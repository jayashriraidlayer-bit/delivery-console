<?php
require_once __DIR__ . '/db.php';

function start_session_if_needed(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

// Ends the request with a 401 unless the caller has a valid session.
// Returns the logged-in user's company_id when it does.
function require_company_id(): int {
    start_session_if_needed();
    if (!isset($_SESSION['user_id'], $_SESSION['company_id'])) {
        json_response(['error' => 'not authenticated'], 401);
    }
    return (int)$_SESSION['company_id'];
}
