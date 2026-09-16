<?php
require __DIR__ . '/auth.php';

start_session_if_needed();
if (!isset($_SESSION['user_id'])) {
    json_response(['authenticated' => false]);
}

json_response([
    'authenticated' => true,
    'email' => $_SESSION['email'],
    'company_id' => $_SESSION['company_id'],
]);
