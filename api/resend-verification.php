<?php
/**
 * POST /api/resend-verification.php   (no auth — an unverified account
 * cannot sign in, so there is no token to present)
 * Body: { email }
 *
 * functions/resend-verification.php as JSON, reusing the website's own
 * functions/email_verify.php so the token and the Brevo email are the same
 * ones registration sends.
 *
 * Needed because login.php refuses an unverified account with
 * needs_verification, and without this the only way out of that state is to
 * find an email that may never have arrived.
 *
 * Like forgot-password.php, the reply never reveals whether the address has
 * an account or whether it is already verified.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../functions/email_verify.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

$in = read_input();
$email = trim($in['email'] ?? '');

$sameAnswer = [
    'success' => true,
    'message' => "If that account exists and isn't verified yet, a new verification email is on its way.",
];

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('Please enter a valid email address.');
}

try {
    $stmt = $pdo->prepare("
        SELECT user_id, first_name, is_email_verified
        FROM users WHERE LOWER(email) = LOWER(?)
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && empty($user['is_email_verified'])) {
        // Verification tokens last 24 hours, so "issued in the last two
        // minutes" is an expiry still more than 23h58m out.
        $recent = $pdo->prepare("
            SELECT 1 FROM email_verifications
            WHERE user_id = ? AND expires_at > NOW() + INTERVAL '23 hours 58 minutes'
            LIMIT 1
        ");
        $recent->execute([(int) $user['user_id']]);

        if (!$recent->fetch()) {
            $token = create_verification_token($pdo, (int) $user['user_id']);
            send_verification_email($email, $user['first_name'], $token);
        }
    }

    json_out($sameAnswer);
} catch (PDOException $e) {
    error_log('resend-verification.php error: ' . $e->getMessage());
    json_out($sameAnswer);
}
