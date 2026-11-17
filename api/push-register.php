<?php
/**
 * /api/push-register.php   (Authorization: Bearer <token>)
 *
 *   POST   { token, device_info? }  -> remember this device for push
 *   DELETE { token }                -> forget it (called on sign-out)
 *
 * The token is Expo's, not ours: a string like ExponentPushToken[xxxx]
 * that identifies one installation of the app on one device.
 *
 * A token is tied to the user who registered it, so signing in as someone
 * else on the same phone moves the token rather than duplicating it —
 * otherwise the previous account's notifications would keep arriving on a
 * phone that now belongs to a different customer.
 */
require_once __DIR__ . '/_bootstrap.php';

$user = require_auth($pdo);
$userId = (int) $user['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$in = read_input();

$pushToken = trim((string) ($in['token'] ?? ''));

if ($pushToken === '') {
    json_error('A push token is required.');
}

// Expo's tokens have a known shape; anything else is a mistake or a probe,
// and storing it would only mean failed sends later.
if (!preg_match('/^(ExponentPushToken\[[^\]]+\]|ExpoPushToken\[[^\]]+\])$/', $pushToken)) {
    json_error('That does not look like an Expo push token.');
}

try {
    if ($method === 'POST') {
        $deviceInfo = trim((string) ($in['device_info'] ?? ''));

        // One row per token. If the token is already known, point it at
        // whoever is signed in now and refresh its timestamp.
        $existing = $pdo->prepare("SELECT push_id FROM push_tokens WHERE token = ?");
        $existing->execute([$pushToken]);

        if ($existing->fetchColumn()) {
            $pdo->prepare("
                UPDATE push_tokens
                SET user_id = ?, device_info = ?, updated_at = NOW()
                WHERE token = ?
            ")->execute([$userId, $deviceInfo !== '' ? $deviceInfo : null, $pushToken]);
        } else {
            $pdo->prepare("
                INSERT INTO push_tokens (user_id, token, device_info, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
            ")->execute([$userId, $pushToken, $deviceInfo !== '' ? $deviceInfo : null]);
        }

        json_out(['success' => true, 'message' => 'This device will receive notifications.']);
    }

    if ($method === 'DELETE') {
        // Scoped to the caller so one customer cannot unregister another's
        // device by guessing a token.
        $pdo->prepare("DELETE FROM push_tokens WHERE token = ? AND user_id = ?")
            ->execute([$pushToken, $userId]);

        json_out(['success' => true, 'message' => 'This device will no longer receive notifications.']);
    }

    json_error('Method not allowed.', 405);
} catch (PDOException $e) {
    error_log('push-register.php error: ' . $e->getMessage());
    json_error('Could not register this device for notifications.', 500);
}
