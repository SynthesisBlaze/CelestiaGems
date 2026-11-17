<?php
/**
 * POST /api/resend-2fa.php
 * Body: { user_id }
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../functions/two_factor.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

$in = read_input();
$userId = (int) ($in['user_id'] ?? 0);
if ($userId <= 0) {
    json_error('user_id is required.');
}

$stmt = $pdo->prepare("SELECT user_id, first_name, last_name, email FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    json_error('User not found.', 404);
}

$code = create_2fa_code($pdo, $userId);
$name = trim($user['first_name'] . ' ' . $user['last_name']);
$sent = send_2fa_email($user['email'], $name !== '' ? $name : $user['email'], $code);

if (!$sent) {
    json_error("We couldn't send a new code right now. Please try again shortly.", 500);
}

json_out(['success' => true, 'message' => 'A new code was sent to your email.']);
