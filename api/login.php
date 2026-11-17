<?php
/**
 * POST /api/login.php
 * Body: { email, password, device_info? }
 *
 * Mirrors pages/login.php's logic (same unverified-email block, same 2FA
 * gate from site_settings), but returns JSON + a token instead of setting
 * a PHP session cookie.
 *
 * Responses:
 *  - unverified email      -> 403 { success: false, message, needs_verification: true }
 *  - 2FA enabled           -> 200 { success: true, requires_2fa: true, user_id }
 *                             (a 6-digit code was just emailed via Mailjet)
 *  - 2FA disabled          -> 200 { success: true, token, user: {...} }
 *  - bad credentials       -> 401 { success: false, message }
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../functions/login_logger.php';
require_once __DIR__ . '/../functions/two_factor.php';
require_once __DIR__ . '/../functions/settings_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

$in = read_input();
$email = trim($in['email'] ?? '');
$pass  = $in['password'] ?? '';
$deviceInfo = trim($in['device_info'] ?? 'mobile-app');

if ($email === '' || $pass === '') {
    json_error('Email and password are required.');
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($pass, $user['password_hash'])) {
    $failureReason = $user ? 'invalid_password' : 'user_not_found';
    log_login_attempt($pdo, $user ? (int) $user['user_id'] : null, $email, 'failed', $failureReason);
    json_error('Invalid email or password.', 401);
}

if (empty($user['is_email_verified'])) {
    log_login_attempt($pdo, (int) $user['user_id'], $email, 'failed', 'unverified_email');
    json_out([
        'success' => false,
        'needs_verification' => true,
        'message' => 'Please verify your email address before logging in. Check your inbox for the verification link.',
    ], 403);
}

if (get_setting_bool($pdo, 'two_fa_enabled', false)) {
    $code = create_2fa_code($pdo, (int) $user['user_id']);
    $name = trim($user['first_name'] . ' ' . $user['last_name']);
    $emailSent = send_2fa_email($user['email'], $name !== '' ? $name : $user['email'], $code);

    if (!$emailSent) {
        json_error("We couldn't send your verification code right now. Please try again in a moment.", 500);
    }

    json_out([
        'success' => true,
        'requires_2fa' => true,
        'user_id' => (int) $user['user_id'],
        'message' => 'A 6-digit code was sent to your email.',
    ]);
}

// No 2FA required — log in immediately
log_login_attempt($pdo, (int) $user['user_id'], $email, 'success');
$token = issue_api_token($pdo, (int) $user['user_id'], $deviceInfo);

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
