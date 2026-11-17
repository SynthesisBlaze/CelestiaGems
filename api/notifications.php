<?php
/**
 * /api/notifications.php   (Authorization: Bearer <token>)
 *
 *   GET   -> the customer's notifications, newest first, with an unread count
 *   POST  -> mark them read: {} for all, or { notification_id } for one
 *
 * Same notifications table functions/notifier.php writes to, so an order
 * placed on the website shows up in the app and vice versa.
 */
require_once __DIR__ . '/_bootstrap.php';

$user = require_auth($pdo);
$userId = (int) $user['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("
        SELECT notification_id, type, title, message, related_type, related_id, is_read, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifications = array_map(fn($r) => [
        'notification_id' => (int) $r['notification_id'],
        'type'            => $r['type'],
        'title'           => $r['title'],
        'message'         => $r['message'],
        'related_type'    => $r['related_type'],
        'related_id'      => $r['related_id'] !== null ? (int) $r['related_id'] : null,
        'is_read'         => (bool) $r['is_read'],
        'created_at'      => $r['created_at'],
    ], $rows);

    $unread = 0;
    foreach ($notifications as $n) {
        if (!$n['is_read']) {
            $unread++;
        }
    }

    json_out([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => $unread,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = read_input();
    $one = isset($in['notification_id']) ? (int) $in['notification_id'] : 0;

    if ($one > 0) {
        // Scoped to this user, so one customer can't mark another's read.
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?")
            ->execute([$one, $userId]);
        json_out(['success' => true, 'message' => 'Notification marked as read.']);
    }

    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")
        ->execute([$userId]);
    json_out(['success' => true, 'message' => 'All notifications marked as read.']);
}

json_error('Method not allowed.', 405);
