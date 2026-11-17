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
require_once __DIR__ . '/notifier.php';
require_once __DIR__ . '/settings_helper.php';

function back_with_error(string $message): void
{
    header("Location: ../admin/settings.php?error=" . urlencode($message));
    exit;
}

// --- Parse + validate ---
$freeShippingEnabled = isset($_POST['free_shipping_enabled']) ? '1' : '0';
$freeShippingThreshold = $_POST['free_shipping_threshold'] ?? '0';
$shippingFee = $_POST['shipping_fee'] ?? '0';
$codEnabled = isset($_POST['payment_cod_enabled']) ? '1' : '0';
$paymongoEnabled = isset($_POST['payment_paymongo_enabled']) ? '1' : '0';
$announcementEnabled = isset($_POST['announcement_enabled']) ? '1' : '0';
$announcementText = trim($_POST['announcement_text'] ?? '');
$twoFaEnabled = isset($_POST['two_fa_enabled']) ? '1' : '0';

if (!is_numeric($freeShippingThreshold) || (float) $freeShippingThreshold < 0) {
    back_with_error('Free shipping threshold must be a valid, non-negative number.');
}
if (!is_numeric($shippingFee) || (float) $shippingFee < 0) {
    back_with_error('Shipping fee must be a valid, non-negative number.');
}
if ($codEnabled === '0' && $paymongoEnabled === '0') {
    back_with_error('At least one payment method must stay enabled.');
}
if ($announcementEnabled === '1' && $announcementText === '') {
    back_with_error('Announcement text can\'t be empty while the announcement is turned on.');
}

$newValues = [
    'free_shipping_enabled'   => $freeShippingEnabled,
    'free_shipping_threshold' => number_format((float) $freeShippingThreshold, 2, '.', ''),
    'shipping_fee'            => number_format((float) $shippingFee, 2, '.', ''),
    'payment_cod_enabled'     => $codEnabled,
    'payment_paymongo_enabled' => $paymongoEnabled,
    'announcement_enabled'    => $announcementEnabled,
    'announcement_text'       => $announcementText,
    'two_fa_enabled'          => $twoFaEnabled,
];

try {
    $pdo->beginTransaction();

    // Capture old values first, for a real before/after diff — and so we
    // know whether the announcement actually changed before broadcasting.
    $oldValues = get_all_settings($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO site_settings (setting_key, setting_value)
        VALUES (:key, :value)
        ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value
    ");
    foreach ($newValues as $key => $value) {
        $stmt->execute(['key' => $key, 'value' => $value]);
    }

    $pdo->commit();

    // --- Audit log: only the keys that actually changed ---
    $changedOld = [];
    $changedNew = [];
    foreach ($newValues as $key => $value) {
        $previous = $oldValues[$key] ?? null;
        if ($previous !== $value) {
            $changedOld[$key] = $previous;
            $changedNew[$key] = $value;
        }
    }

    if (!empty($changedNew)) {
        log_audit(
            $pdo,
            (int) $_SESSION['user_id'],
            admin_actor_type((int) $_SESSION['role_id']),
            'update',
            'settings',
            null,
            $changedOld,
            $changedNew
        );
    }

    // --- Broadcast a notification when the announcement is newly turned on,
    //     or its text changes while it's already on. Not on every save. ---
    $oldAnnouncementEnabled = ($oldValues['announcement_enabled'] ?? '0') === '1';
    $oldAnnouncementText = $oldValues['announcement_text'] ?? '';

    $shouldBroadcast = $announcementEnabled === '1' && (
        !$oldAnnouncementEnabled || $oldAnnouncementText !== $announcementText
    );

    if ($shouldBroadcast) {
        // Bulk insert rather than looping notify_user() per customer —
        // this can be a lot of rows if the customer base is large.
        $broadcastStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, related_type, related_id, created_at)
            SELECT user_id, 'announcement', 'Announcement', :message, NULL, NULL, NOW()
            FROM users
            WHERE role_id NOT IN (4, 5)
        ");
        $broadcastStmt->execute(['message' => $announcementText]);
    }

    header("Location: ../admin/settings.php?saved=1");
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    back_with_error('Something went wrong while saving settings. Please try again.');
}
