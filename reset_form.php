<?php
session_start();
$users = json_decode(file_get_contents('users.json'), true);
$token = $_GET['token'] ?? '';

foreach ($users as &$u) {
    if ($u['reset_token'] === $token && $u['reset_expires'] > time()) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $np = $_POST['new_password'];
            $u['password'] = password_hash($np, PASSWORD_BCRYPT);
            $u['reset_token'] = null;
            $u['reset_expires'] = null;
            file_put_contents('users.json', json_encode($users, JSON_PRETTY_PRINT));
            header('Location: index.php?reset=success');
            exit;
        }
        include 'templates/reset_form.php';
        exit;
    }
}
die('Invalid or expired token.');