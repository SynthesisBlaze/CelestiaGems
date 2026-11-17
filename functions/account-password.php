<?php
session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/audit_logger.php';
require_once __DIR__ . '/password_reset.php'; // for actor_type_for_role()

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../pages/account.php");
    exit;
}

$userId = $_SESSION['user_id'];
$currentPassword = $_POST['current_password'];
$newPassword = $_POST['new_password'];
$confirmPassword = $_POST['confirm_password'];

if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
    $_SESSION['error_msg'] = "All password fields are required.";
    header("Location: ../pages/account.php?tab=details");
    exit;
}

if (strlen($newPassword) < 8) {
    $_SESSION['error_msg'] = "Your new password must be at least 8 characters long.";
    header("Location: ../pages/account.php?tab=details");
    exit;
}

if ($newPassword !== $confirmPassword) {
    $_SESSION['error_msg'] = "New passwords do not match.";
    header("Location: ../pages/account.php?tab=details");
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT password_hash, role_id FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if ($user && password_verify($currentPassword, $user['password_hash'])) {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
        $updateStmt->execute([$newHash, $userId]);

        log_audit(
            $pdo,
            (int) $userId,
            actor_type_for_role((int) $user['role_id']),
            'password_change',
            'user',
            (int) $userId,
            null,
            null
        );

        $_SESSION['success_msg'] = "Password changed successfully.";
    } else {
        $_SESSION['error_msg'] = "Incorrect current password.";
    }
} catch (PDOException $e) {
    $_SESSION['error_msg'] = "An error occurred while updating your password.";
}

header("Location: ../pages/account.php?tab=details");
exit;