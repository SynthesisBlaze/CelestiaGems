<?php
require_once __DIR__ . '/brevo_config.php';
require_once __DIR__ . '/helpers.php';

/**
 * Generate a new verification token for a user, store it, and return it.
 * Old unused tokens for the user are left in place (harmless — only the
 * newest link you actually click matters), but each token is single-use.
 */
function create_verification_token(PDO $pdo, int $userId): string
{
    $token = bin2hex(random_bytes(32)); // 64-char random token
    $expiresAt = (new DateTime('+24 hours'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        "INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)"
    );
    $stmt->execute([$userId, $token, $expiresAt]);

    return $token;
}

/**
 * Send the verification email via Brevo's transactional email API.
 * Returns true on success, false on failure. Never throws — registration
 * shouldn't hard-fail just because the email provider hiccuped.
 */
function send_verification_email(string $toEmail, string $toName, string $token): bool
{
    $verifyLink = cg_base_url() . "/pages/verify-email.php?token=" . urlencode($token);

    $payload = [
        'sender' => [
            'name'  => BREVO_SENDER_NAME,
            'email' => BREVO_SENDER_EMAIL,
        ],
        'to' => [
            ['email' => $toEmail, 'name' => $toName],
        ],
        'subject' => 'Verify your Celestia Gems account',
        'htmlContent' => '
            <div style="font-family: Arial, sans-serif; max-width: 480px; margin: auto;">
                <h2>Welcome to Celestia Gems, ' . htmlspecialchars($toName) . '!</h2>
                <p>Please confirm your email address to activate your account.</p>
                <p>
                    <a href="' . htmlspecialchars($verifyLink) . '"
                       style="display:inline-block; padding:12px 24px; background:#C9A15C; color:#fff; text-decoration:none; border-radius:4px;">
                       Verify My Email
                    </a>
                </p>
                <p>Or copy and paste this link into your browser:<br>' . htmlspecialchars($verifyLink) . '</p>
                <p style="color:#888; font-size:0.85rem;">This link expires in 24 hours. If you did not create this account, you can ignore this email.</p>
            </div>
        ',
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("Brevo cURL error: $curlError");
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }

    error_log("Brevo API error ($httpCode): $response");
    return false;
}

/**
 * Validate a token from the verification link.
 * On success: marks the token used, flips the user to verified, and
 * returns the user_id. On failure (missing/expired/already used): null.
 */
function verify_token(PDO $pdo, string $token): ?int
{
    $stmt = $pdo->prepare(
        "SELECT verification_id, user_id, expires_at, used_at
         FROM email_verifications WHERE token = ?"
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        return null; // token doesn't exist
    }
    if ($row['used_at'] !== null) {
        return null; // already used
    }
    if (strtotime($row['expires_at']) < time()) {
        return null; // expired
    }

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE email_verifications SET used_at = NOW() WHERE verification_id = ?")
        ->execute([$row['verification_id']]);
    $pdo->prepare("UPDATE users SET is_email_verified = 1 WHERE user_id = ?")
        ->execute([$row['user_id']]);
    $pdo->commit();

    return (int)$row['user_id'];
}