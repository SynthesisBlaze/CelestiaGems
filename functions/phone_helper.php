<?php
/**
 * Normalizes and validates a Philippine mobile number.
 *
 * Accepts the ways people actually type/paste one — "09171234567",
 * "0917 123 4567", "0917-123-4567", "+63 917 123 4567", "9171234567" —
 * and returns the canonical 11-digit "09XXXXXXXXX" form, or null if it
 * isn't a plausible PH mobile number.
 */
function normalize_ph_mobile(string $raw): ?string
{
    $digits = preg_replace('/\D+/', '', $raw);

    if (strlen($digits) === 12 && str_starts_with($digits, '639')) {
        $digits = '0' . substr($digits, 2);   // +63 917... -> 0917...
    } elseif (strlen($digits) === 10 && $digits[0] === '9') {
        $digits = '0' . $digits;              // 917... -> 0917...
    }

    // Mobile numbers are 09 + 9 digits.
    if (!preg_match('/^09\d{9}$/', $digits)) {
        return null;
    }

    // Reject obvious placeholders like 09000000000 / 09111111111.
    if (preg_match('/^09(\d)\1{8}$/', $digits)) {
        return null;
    }

    return $digits;
}
