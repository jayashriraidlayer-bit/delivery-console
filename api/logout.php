<?php
require __DIR__ . '/auth.php';

start_session_if_needed();
$_SESSION = [];
session_destroy();

json_response(['loggedOut' => true]);
