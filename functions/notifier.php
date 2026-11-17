<?php
require_once __DIR__ . '/push.php';

/**
 * Helper for writing to the notifications table — the customer-facing
 * counterpart to audit_logs. Include with require_once wherever an order
 * or booking event happens that the customer should see on their account
 * page (Notifications tab).
 *
 * Rows sit here until the account page or the app queries them and marks
 * them read. Since the mobile app exists, this also sends the same text as
 * a push notification to any device that customer has registered — hooking
 * in here rather than at each call site means every notification type is
 * covered at once, including any the admin adds later.
 *
 * A failure here is caught and logged, never thrown — a notification
 * failing to save should never block the actual order/booking action.
 */
function notify_user(
    PDO $pdo,
    int $userId,
    string $type,
    string $title,
    string $message,
    ?string $relatedType = null,
    ?int $relatedId = null
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, related_type, related_id, created_at)
            VALUES (:user_id, :type, :title, :message, :related_type, :related_id, NOW())
        ");
        $stmt->execute([
            ':user_id'      => $userId,
            ':type'         => $type,
            ':title'        => $title,
            ':message'      => $message,
            ':related_type' => $relatedType,
            ':related_id'   => $relatedId,
        ]);
    } catch (PDOException $e) {
        error_log('notify_user() failed: ' . $e->getMessage());
        return; // nothing was saved, so there is nothing to announce
    }

    // Best-effort, and deliberately after the insert: the notification is
    // already safely recorded, so a failure to push costs the customer a
    // buzz, not the message.
    cg_send_push($pdo, $userId, $title, $message, [
        'type'         => $type,
        'related_type' => $relatedType,
        'related_id'   => $relatedId,
    ]);
}
