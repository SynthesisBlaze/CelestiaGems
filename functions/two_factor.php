<?php
require_once __DIR__ . '/mailjet_config.php';

/**
 * Generate a new 6-digit OTP for a user, store it in user_2fa, and return it.
 */
function create_2fa_code(PDO $pdo, int $userId): string
{
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = (new DateTime('+10 minutes'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        "INSERT INTO user_2fa (user_id, otp_code, expires_at, is_used) VALUES (?, ?, ?, 0)"
    );
    $stmt->execute([$userId, $code, $expiresAt]);

    return $code;
}

/**
 * Send the 2FA code via Mailjet's Send API (v3.1).
 * Returns true on success, false on failure — never throws.
 */
function send_2fa_email(string $toEmail, string $toName, string $code): bool
{
    $payload = [
        'Messages' => [
            [
                'From' => [
                    'Email' => MAILJET_SENDER_EMAIL,
                    'Name'  => MAILJET_SENDER_NAME,
                ],
                'To' => [
                    ['Email' => $toEmail, 'Name' => $toName],
                ],
                'Subject'  => 'Your Celestia Gems login code',
                'HTMLPart' => '
                    <div style="font-family: Arial, sans-serif; max-width: 480px; margin: auto;">
                        <h2>Hi ' . htmlspecialchars($toName) . ',</h2>
                        <p>Use the code below to finish logging in to Celestia Gems:</p>
                        <p style="font-size: 32px; font-weight: bold; letter-spacing: 6px; text-align: center; margin: 24px 0;">' . htmlspecialchars($code) . '</p>
                        <p style="color:#888; font-size:0.85rem;">This code expires in 10 minutes. If you did not try to log in, you can ignore this email.</p>
                    </div>
                ',
            ],
        ],
    ];

    $ch = curl_init('https://api.mailjet.com/v3.1/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_USERPWD => MAILJET_API_KEY . ':' . MAILJET_SECRET_KEY,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("Mailjet cURL error: $curlError");
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }

    error_log("Mailjet API error ($httpCode): $response");
    return false;
}

/**
 * Check a submitted code against the most recent unused, unexpired code
 * for this user. Marks it used on success. Returns true/false.
 */
function verify_2fa_code(PDO $pdo, int $userId, string $code): bool
{
    $stmt = $pdo->prepare(
        "SELECT two_factor_id, expires_at, is_used FROM user_2fa
         WHERE user_id = ? AND otp_code = ?
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$userId, $code]);
    $row = $stmt->fetch();

    if (!$row) {
        return false; // no matching code
    }
    if ((int)$row['is_used'] === 1) {
        return false; // already used
    }
    if (strtotime($row['expires_at']) < time()) {
        return false; // expired
    }

    $pdo->prepare("UPDATE user_2fa SET is_used = 1 WHERE two_factor_id = ?")
        ->execute([$row['two_factor_id']]);

    return true;
}
