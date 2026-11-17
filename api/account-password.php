<?php
/**
 * POST /api/account-password.php   (Authorization: Bearer <token>)
 * Body: { current_password, new_password, confirm_password }
 *
 * functions/account-password.php with token auth instead of $_SESSION and
 * a JSON reply instead of a redirect. Same rules: the current password must
 * be right, the new one at least 8 characters, and the two copies must
 * match.
 *
 * Asking for the current password is not a formality. The token in the app
 * outlives the session on a borrowed or stolen phone, and without this
 * check whoever is holding it could lock the real owner out by changing the
 * password.
 *
 * Every other token for this account is revoked on success, so a password
 * change actually signs out the other devices rather than only looking like
 * it did. The caller's own token is kept, so the app does not throw the
 * person out of the screen they just used.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../functions/audit_logger.php';
require_once __DIR__ . '/../functions/password_reset.php'; // actor_type_for_role()

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

$user = require_auth($pdo);
$userId = (int) $user['user_id'];
$in = read_input();

$current = (string) ($in['current_password'] ?? '');
$new     = (string) ($in['new_password'] ?? '');
$confirm = (string) ($in['confirm_password'] ?? '');

if ($current === '' || $new === '' || $confirm === '') {
    json_error('All password fields are required.');
}
if (strlen($new) < 8) {
    json_error('Your new password must be at least 8 characters long.');
}
if ($new !== $confirm) {
    json_error('New passwords do not match.');
}
if ($new === $current) {
    json_error('Your new password must be different from your current one.');
}

try {
    $stmt = $pdo->prepare("SELECT password_hash, role_id FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !password_verify($current, $row['password_hash'])) {
        // Deliberately the same wording the website uses, and deliberately
        // not "that account doesn't exist" — this endpoint is already
        // behind a valid token, so the only useful answer is about the
        // password that was typed.
        json_error('Incorrect current password.', 403);
    }

    $newHash = password_hash($new, PASSWORD_DEFAULT);

    $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
        ->execute([$newHash, $userId]);

    // Sign out every other device. The token presented with this request
    // survives so the app stays usable.
    $callerToken = get_bearer_token();
    $pdo->prepare("DELETE FROM api_tokens WHERE user_id = ? AND token <> ?")
        ->execute([$userId, $callerToken]);

    log_audit(
        $pdo,
        $userId,
        actor_type_for_role((int) $row['role_id']),
        'password_change',
        'user',
        $userId,
        null,
        ['source' => 'mobile']
    );

    json_out([
        'success' => true,
        'message' => 'Password changed. Any other devices signed in to this account have been signed out.',
    ]);
} catch (PDOException $e) {
    error_log('account-password.php error: ' . $e->getMessage());
    json_error('An error occurred while updating your password.', 500);
}
