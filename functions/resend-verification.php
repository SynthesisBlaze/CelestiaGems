<?php
session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/email_verify.php';

// Check GET parameter first, fall back to session if set by login.php
$email = trim($_GET['email'] ?? ($_SESSION['pending_verification_email'] ?? ''));

if ($email !== '') {
    $stmt = $pdo->prepare("SELECT user_id, first_name, is_email_verified FROM users WHERE LOWER(email) = LOWER(?)");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && (int)$user['is_email_verified'] !== 1) {
        $token = create_verification_token($pdo, (int)$user['user_id']);
        send_verification_email($email, $user['first_name'], $token);
    }
}

$_SESSION['success_msg'] = "If that account exists and isn't verified yet, a new verification email has been sent.";
header("Location: ../pages/login.php");
exit;