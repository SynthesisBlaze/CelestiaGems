<?php
session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/two_factor.php';

if (empty($_SESSION['pending_2fa_user_id'])) {
    header("Location: ../pages/login.php");
    exit;
}

$userId = (int)$_SESSION['pending_2fa_user_id'];

$stmt = $pdo->prepare("SELECT first_name, email FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if ($user) {
    $code = create_2fa_code($pdo, $userId);
    send_2fa_email($user['email'], $user['first_name'], $code);
}

$_SESSION['info_msg'] = "A new code has been sent to your email.";
header("Location: ../pages/verify-2fa.php");
exit;
