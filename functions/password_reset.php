<?php
require_once __DIR__ . '/brevo_config.php';
require_once __DIR__ . '/helpers.php';

/**
 * Generate a new password reset token for a user, store it, and return it.
 * Each token is single-use and short-lived (1 hour) — much shorter than
 * email verification links, since a reset link grants account access.
 */
function create_password_reset_token(PDO $pdo, int $userId): string
{
    $token = bin2hex(random_bytes(32)); // 64-char random token
    $expiresAt = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        "INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)"
    );
    $stmt->execute([$userId, $token, $expiresAt]);

    return $token;
}

/**
 * Send the password reset email via Brevo's transactional email API.
 * Returns true on success, false on failure. Never throws.
 */
function send_password_reset_email(string $toEmail, string $toName, string $token): bool
{
    $resetLink = cg_base_url() . "/pages/reset-password.php?token=" . urlencode($token);

    $payload = [
        'sender' => [
            'name'  => BREVO_SENDER_NAME,
            'email' => BREVO_SENDER_EMAIL,
        ],
        'to' => [
            ['email' => $toEmail, 'name' => $toName],
        ],
        'subject' => 'Reset your Celestia Gems password',
        'htmlContent' => '
            <div style="font-family: Arial, sans-serif; max-width: 480px; margin: auto;">
                <h2>Hi ' . htmlspecialchars($toName) . ',</h2>
                <p>We received a request to reset your Celestia Gems password. Click the button below to choose a new one.</p>
                <p>
                    <a href="' . htmlspecialchars($resetLink) . '"
                       style="display:inline-block; padding:12px 24px; background:#C9A15C; color:#fff; text-decoration:none; border-radius:4px;">
                       Reset My Password
                    </a>
                </p>
                <p>Or copy and paste this link into your browser:<br>' . htmlspecialchars($resetLink) . '</p>
                <p style="color:#888; font-size:0.85rem;">This link expires in 1 hour. If you did not request a password reset, you can safely ignore this email — your password will not be changed.</p>
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
        error_log("Brevo cURL error (password reset): $curlError");
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }

    error_log("Brevo API error (password reset, $httpCode): $response");
    return false;
}

/**
 * Validate a token from the reset link WITHOUT consuming it.
 * Used to decide whether to show the "set new password" form at all.
 * Returns the user_id on a valid, unexpired, unused token — null otherwise.
 */
function check_password_reset_token(PDO $pdo, string $token): ?int
{
    $stmt = $pdo->prepare(
        "SELECT user_id, expires_at, used_at
         FROM password_resets WHERE token = ?"
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

    return (int) $row['user_id'];
}

/**
 * Maps a role_id to the actor_type string used in audit_logs, for actions
 * (like a self-service password reset) that any account type can perform —
 * unlike admin_actor_type() in audit_logger.php, which assumes admin/super_admin.
 */
function actor_type_for_role(int $roleId): string
{
    return match ($roleId) {
        4       => 'admin',
        5       => 'super_admin',
        3       => 'staff',
        default => 'customer',
    };
}

/**
 * Consume a valid reset token: set the new password, mark the token used,
 * and return the user_id. Caller is responsible for calling
 * check_password_reset_token() first (or handling a false return here) and
 * for writing the audit log entry, since only the caller knows the request
 * context worth recording.
 *
 * Returns the user_id on success, or null if the token was invalid/expired/
 * already used by the time this ran (handles a token being used twice in
 * quick succession, e.g. two open tabs).
 */
function consume_password_reset_token(PDO $pdo, string $token, string $newPasswordHash): ?int
{
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT reset_id, user_id, expires_at, used_at
         FROM password_resets WHERE token = ? FOR UPDATE"
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row || $row['used_at'] !== null || strtotime($row['expires_at']) < time()) {
        $pdo->rollBack();
        return null;
    }

    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE reset_id = ?")
        ->execute([$row['reset_id']]);
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
        ->execute([$newPasswordHash, $row['user_id']]);

    $pdo->commit();

    return (int) $row['user_id'];
}