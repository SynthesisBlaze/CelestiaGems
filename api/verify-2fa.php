<?php
/**
 * POST /api/verify-2fa.php
 * Body: { user_id, code, device_info? }
 *
 * Call this after login.php returns requires_2fa: true. On success, issues
 * the same kind of token a non-2FA login would.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../functions/two_factor.php';
require_once __DIR__ . '/../functions/login_logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

$in = read_input();
$userId = (int) ($in['user_id'] ?? 0);
$code   = trim((string) ($in['code'] ?? ''));
$deviceInfo = trim($in['device_info'] ?? 'mobile-app');

if ($userId <= 0 || $code === '') {
    json_error('user_id and code are required.');
}

if (!verify_2fa_code($pdo, $userId, $code)) {
    json_error('That code is invalid or has expired. Please request a new one.', 401);
}

$stmt = $pdo->prepare("SELECT user_id, role_id, first_name, last_name, email, phone FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    json_error('User not found.', 404);
}

log_login_attempt($pdo, $userId, $user['email'], 'success');
$token = issue_api_token($pdo, $userId, $deviceInfo);

json_out([
    'success' => true,
    'token' => $token,
    'user' => [
        'user_id'    => (int) $user['user_id'],
        'role_id'    => (int) $user['role_id'],
        'first_name' => $user['first_name'],
        'last_name'  => $user['last_name'],
        'email'      => $user['email'],
        'phone'      => $user['phone'],
    ],
]);
