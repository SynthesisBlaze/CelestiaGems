<?php
require_once __DIR__ . '/paymongo_config.php';

/**
 * Low-level PayMongo API request helper. Returns the decoded response body
 * (with 'data' at the top level, matching PayMongo's JSON:API shape) on
 * success, or null on any transport/HTTP failure. Never throws.
 */
function paymongo_request(string $method, string $path, ?array $body = null): ?array
{
    $ch = curl_init('https://api.paymongo.com' . $path);

    $headers = [
        'accept: application/json',
        'content-type: application/json',
        'authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
    ];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("PayMongo cURL error: $curlError");
        return null;
    }

    $decoded = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("PayMongo API error ($httpCode): $response");
        return null;
    }

    return $decoded;
}

/**
 * Creates a PayMongo Hosted Checkout Session (v2) for the given peso amount.
 * Returns ['id' => 'cs_...', 'checkout_url' => '...'] on success, or null
 * on failure — the caller decides how to handle that (e.g. fall back to
 * "pay at appointment" rather than leave the customer stuck).
 */
function create_paymongo_checkout_session(
    float $amountPesos,
    string $description,
    string $referenceNumber,
    string $successUrl,
    string $cancelUrl
): ?array {
    $payload = [
        'data' => [
            'attributes' => [
                'line_items' => [[
                    'name' => $description,
                    'amount' => (int) round($amountPesos * 100), // PayMongo amounts are in centavos
                    'currency' => 'PHP',
                    'quantity' => 1,
                ]],
                'payment_method_types' => ['gcash', 'card', 'paymaya', 'grab_pay'],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'reference_number' => $referenceNumber,
                'description' => $description,
            ],
        ],
    ];

    $result = paymongo_request('POST', '/v2/checkout_sessions', $payload);
    if (!$result || empty($result['data']['id']) || empty($result['data']['attributes']['checkout_url'])) {
        return null;
    }

    return [
        'id' => $result['data']['id'],
        'checkout_url' => $result['data']['attributes']['checkout_url'],
    ];
}

/**
 * Fetches a Checkout Session. PayMongo documents retrieval under /v1, while
 * sessions created via /v2 may only be retrievable there or on /v2 — so try
 * /v1 first and fall back to /v2 rather than guess which one applies.
 */
function paymongo_get_checkout_session(string $checkoutSessionId): ?array
{
    foreach (['/v1', '/v2'] as $version) {
        $result = paymongo_request('GET', $version . '/checkout_sessions/' . urlencode($checkoutSessionId));
        if ($result && isset($result['data'])) {
            return $result;
        }
    }
    return null;
}

/**
 * Tri-state payment check for a Checkout Session:
 *   'paid'    — PayMongo shows a completed payment
 *   'unpaid'  — PayMongo answered, and there is no completed payment (yet)
 *   'unknown' — we couldn't get a usable answer from PayMongo at all
 * Callers must NOT treat 'unknown' as a failed payment — it only means the
 * check itself failed, and the customer may well have paid.
 */
function paymongo_checkout_status(string $checkoutSessionId): string
{
    $result = paymongo_get_checkout_session($checkoutSessionId);
    if (!$result) {
        return 'unknown';
    }

    $attrs = $result['data']['attributes'] ?? [];

    // Signal 1: any payment attempt marked paid (entries may be wrapped
    // as {attributes: {status}} or flat {status}).
    foreach (($attrs['payments'] ?? []) as $payment) {
        $status = $payment['attributes']['status'] ?? $payment['status'] ?? '';
        if (in_array($status, ['paid', 'succeeded'], true)) {
            return 'paid';
        }
    }

    // Signal 2: the latest Payment Intent succeeded.
    $intentStatus = $attrs['payment_intent']['attributes']['status']
        ?? $attrs['payment_intent']['status']
        ?? '';
    if ($intentStatus === 'succeeded') {
        return 'paid';
    }

    // Nothing says paid — log the shape once so a real mismatch is diagnosable.
    error_log('PayMongo: no paid signal for ' . $checkoutSessionId . ' — response: '
        . substr(json_encode($result), 0, 1500));
    return 'unpaid';
}

function paymongo_checkout_is_paid(string $checkoutSessionId): bool
{
    return paymongo_checkout_status($checkoutSessionId) === 'paid';
}
