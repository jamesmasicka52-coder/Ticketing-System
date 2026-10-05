<?php
require_once __DIR__ . '/ticket_store.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}
ticket_require_csrf();
$user = current_user();
if ($user !== null) {
    audit_event($user, 'auth.logout', 'user', $user['id']);
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], '', $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: login.php');
exit;
