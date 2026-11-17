<?php
session_start();

require_once __DIR__ . '/permissions.php';
cg_require_page('settings.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../admin/settings.php");
    exit;
}

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/audit_logger.php';

function back_with_error(string $message): void
{
    header("Location: ../admin/settings.php?error=" . urlencode($message));
    exit;
}

$title = trim($_POST['title'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($title === '' || $message === '') {
    back_with_error('Both a header and a message are required to send a notification.');
}

try {
    // Bulk insert rather than looping notify_user() per customer — this can
    // be a lot of rows if the customer base is large.
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, message, related_type, related_id, created_at)
        SELECT user_id, 'admin_message', :title, :message, NULL, NULL, NOW()
        FROM users
        WHERE role_id NOT IN (4, 5)
    ");
    $stmt->execute(['title' => $title, 'message' => $message]);
    $recipientCount = $stmt->rowCount();

    log_audit(
        $pdo,
        (int) $_SESSION['user_id'],
        admin_actor_type((int) $_SESSION['role_id']),
        'create',
        'notification',
        null,
        null,
        ['title' => $title, 'message' => $message, 'recipients' => $recipientCount]
    );

    header("Location: ../admin/settings.php?sent=" . $recipientCount);
    exit;
} catch (PDOException $e) {
    back_with_error('Something went wrong while sending the notification. Please try again.');
}
