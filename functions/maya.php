<?php
require_once __DIR__ . '/maya_config.php';
require_once __DIR__ . '/helpers.php';

if (!defined('MAYA_CALLBACK_URL')) {
    define('MAYA_CALLBACK_URL', cg_base_url() . '/functions/maya-callback.php');
}

/**
 * Create a Maya Checkout session for an order.
 * Returns the decoded response (contains 'checkoutId' and 'redirectUrl')
 * on success, or null on failure. Never throws.
 */
function create_maya_checkout(int $orderId, float $totalAmount, array $buyer): ?array
{
    $payload = [
        'totalAmount' => [
            'value'    => (float) round($totalAmount, 2),
            'currency' => 'PHP',
        ],
        'buyer' => $buyer,
        'redirectUrl' => [
            'success' => MAYA_CALLBACK_URL . '?order_id=' . $orderId . '&status=success',
            'failure' => MAYA_CALLBACK_URL . '?order_id=' . $orderId . '&status=failure',
            'cancel'  => MAYA_CALLBACK_URL . '?order_id=' . $orderId . '&status=cancel',
        ],
        // Must be unique per checkout attempt. The order_id is guaranteed
        // unique and this is a single attempt per order, so it works as-is.
        'requestReferenceNumber' => (string) $orderId,
    ];

    $ch = curl_init(MAYA_BASE_URL . '/checkout/v1/checkouts');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        // Create Checkout authenticates with the PUBLIC key.
        CURLOPT_USERPWD => MAYA_PUBLIC_KEY . ':',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("Maya create checkout cURL error: $curlError");
        return null;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("Maya create checkout API error ($httpCode): $response");
        return null;
    }

    $data = json_decode($response, true);
    if (empty($data['redirectUrl'])) {
        error_log("Maya create checkout: no redirectUrl in response: $response");
        return null;
    }

    return $data;
}

/**
 * Verify a payment's real status directly with Maya (never trust the
 * redirect query string alone). Looks it up by the requestReferenceNumber
 * we used when creating the checkout (our order_id).
 * Returns the status string (e.g. 'PAYMENT_SUCCESS') or null on failure.
 */
function get_maya_payment_status(string $requestReferenceNumber): ?string
{
    $ch = curl_init(MAYA_BASE_URL . '/payments/v1/payment-rrns/' . urlencode($requestReferenceNumber));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        // Retrieve Payment via RRN authenticates with the SECRET key.
        CURLOPT_USERPWD => MAYA_SECRET_KEY . ':',
        CURLOPT_TIMEOUT => 15,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("Maya retrieve payment cURL error: $curlError");
        return null;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("Maya retrieve payment API error ($httpCode): $response");
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data)) {
        return null;
    }

    // The endpoint returns an array of payment attempts for this reference
    // number — take the most recent one.
    $latest = end($data);
    return $latest['status'] ?? null;
}
