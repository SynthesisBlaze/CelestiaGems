<?php
/**
 * POST /api/forgot-password.php   (no auth — you can't sign in, that's the point)
 * Body: { email }
 *
 * pages/forgot-password.php's logic as JSON. It reuses the website's own
 * functions/password_reset.php, so the token, the one-hour expiry, the
 * single-use rule and the Brevo email are all literally the same code paths
 * the web uses — a link sent from the app and a link sent from the site are
 * indistinguishable, and either one works.
 *
 * The reply is deliberately identical whether or not the address has an
 * account. Saying "no such account" would turn this endpoint into a way to
 * test which email addresses are registered, which is worth more to an
 * attacker than it is to the one person who mistyped their own address.
 *
 * Light throttle: password_resets has no created_at, but every row expires
 * exactly an hour after it was made, so an expiry still more than 58
 * minutes away means a reset was requested in the last two minutes. That
 * stops this being used to mailbomb someone, without a schema change.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../functions/password_reset.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

$in = read_input();
$email = trim($in['email'] ?? '');

// Same reply for every outcome below, built once so no branch can leak a
// difference by accident.
$sameAnswer = [
    'success' => true,
    'message' => 'If an account exists for that email, a reset link is on its way. '
               . 'The link is valid for one hour.',
];

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('Please enter a valid email address.');
}

try {
    $stmt = $pdo->prepare("SELECT user_id, first_name, email FROM users WHERE LOWER(email) = LOWER(?)");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        json_out($sameAnswer);
    }

    $recent = $pdo->prepare("
        SELECT 1 FROM password_resets
        WHERE user_id = ? AND used_at IS NULL
          AND expires_at > NOW() + INTERVAL '58 minutes'
        LIMIT 1
    ");
    $recent->execute([(int) $user['user_id']]);

    if ($recent->fetch()) {
        // A link went out moments ago and is still valid — sending another
        // only adds a second email to sort through.
        json_out($sameAnswer);
    }

    $token = create_password_reset_token($pdo, (int) $user['user_id']);
    send_password_reset_email($user['email'], $user['first_name'], $token);

    json_out($sameAnswer);
} catch (PDOException $e) {
    error_log('forgot-password.php error: ' . $e->getMessage());
    // Still the same answer: a database hiccup is not the caller's business
    // and must not become a signal either.
    json_out($sameAnswer);
}
