<?php
/**
 * Small shared helper for sending transactional email through Brevo.
 * Used by the admin reply features (functions/feedback-action.php).
 *
 * Returns true on success, false on failure. Never throws — callers decide
 * what to do when an email doesn't go out.
 */
require_once __DIR__ . '/brevo_config.php';
require_once __DIR__ . '/helpers.php';

function send_brevo_email(string $toEmail, string $toName, string $subject, string $htmlContent): bool
{
    $payload = [
        'sender'      => ['name' => BREVO_SENDER_NAME, 'email' => BREVO_SENDER_EMAIL],
        'to'          => [['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail]],
        'replyTo'     => ['name' => BREVO_SENDER_NAME, 'email' => BREVO_SENDER_EMAIL],
        'subject'     => $subject,
        'htmlContent' => $htmlContent,
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
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
 * Wraps body HTML in a simple branded email layout.
 * $bodyHtml must already be escaped.
 */
function celestia_email_layout(string $bodyHtml): string
{
    return '
    <div style="background:#FAF7F2; padding:32px 16px; font-family: Arial, Helvetica, sans-serif;">
        <div style="max-width:560px; margin:0 auto; background:#FFFFFF; border:1px solid #EADFCB; border-radius:6px;">
            <div style="padding:20px 28px; border-bottom:1px solid #EADFCB;">
                <span style="font-family: Georgia, \'Times New Roman\', serif; font-size:20px; color:#1E1B16; letter-spacing:.5px;">
                    Celestia <span style="color:#8E6A2C;">Gems</span>
                </span>
            </div>
            <div style="padding:24px 28px; color:#1E1B16; font-size:15px; line-height:1.6;">
                ' . $bodyHtml . '
            </div>
            <div style="padding:16px 28px; border-top:1px solid #EADFCB; color:#8A8378; font-size:12px;">
                Celestia Gems · Jewelry drawn from the stars
            </div>
        </div>
    </div>';
}

/**
 * Absolute base URL of the site (e.g. https://celestiagems.com), used for links in emails.
 */
function celestia_base_url(): string
{
    return cg_base_url();
}
