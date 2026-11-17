<?php
session_start();

require_once __DIR__ . '/permissions.php';
cg_require_page('users.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../admin/users_management.php");
    exit;
}

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/audit_logger.php';

$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

if ($userId <= 0) {
    header("Location: ../admin/users_management.php?error=" . urlencode('Invalid user.'));
    exit;
}

// Prevent an admin from deleting their own account from this screen
if ($userId === (int)$_SESSION['user_id']) {
    header("Location: ../admin/users_management.php?error=" . urlencode('You cannot delete your own account.'));
    exit;
}

try {
    // Grab the row before it's gone — nothing left to query once it's deleted.
    $oldRowStmt = $pdo->prepare("SELECT first_name, last_name, email, phone, dob, role_id, is_email_verified, created_at FROM users WHERE user_id = ?");
    $oldRowStmt->execute([$userId]);
    $oldRow = $oldRowStmt->fetch(PDO::FETCH_ASSOC);

    if (!$oldRow) {
        header("Location: ../admin/users_management.php?error=" . urlencode('That user no longer exists.'));
        exit;
    }

    // Admins can only delete Guest/Customer/Staff accounts; Super Admins can delete anyone else.
    $actorRole = cg_current_role();
    if (!cg_can_manage_role($actorRole, (int) $oldRow['role_id'])) {
        header("Location: ../admin/users_management.php?error=" . urlencode(
            'Your role (' . cg_role_name($actorRole) . ') cannot delete ' . cg_role_name((int) $oldRow['role_id']) . ' accounts.'
        ));
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);

    log_audit(
        $pdo,
        (int)$_SESSION['user_id'],
        admin_actor_type((int)$_SESSION['role_id']),
        'delete',
        'user',
        $userId,
        $oldRow ?: null,
        null
    );

    header("Location: ../admin/users_management.php?deleted=1");
    exit;
} catch (PDOException $e) {
    // Most likely a foreign key constraint (user has orders, reviews, etc.)
    header("Location: ../admin/users_management.php?error=" . urlencode('This user could not be deleted — they may have related orders or reviews on record.'));
    exit;
}
