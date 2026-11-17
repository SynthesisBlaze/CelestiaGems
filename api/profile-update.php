<?php
/**
 * POST /api/profile-update.php   (Authorization: Bearer <token>)
 * Body: any of { first_name, last_name, email, phone,
 *                shipping_address, shipping_city, shipping_postal_code,
 *                shipping_lat, shipping_lng }
 *
 * Mirrors functions/account-update.php's two independent update blocks.
 */
require_once __DIR__ . '/_bootstrap.php';

$user = require_auth($pdo);
$in = read_input();

$hasProfileUpdate  = isset($in['first_name']) || isset($in['last_name']) || isset($in['email']) || isset($in['phone']);
$hasShippingUpdate = isset($in['shipping_address']) || isset($in['shipping_city']) || isset($in['shipping_postal_code']);

$messages = [];

try {
    if ($hasProfileUpdate) {
        $firstName = trim($in['first_name'] ?? $user['first_name']);
        $lastName  = trim($in['last_name'] ?? $user['last_name']);
        $email     = trim($in['email'] ?? $user['email']);
        $phone     = trim($in['phone'] ?? $user['phone']);

        if ($firstName === '' || $lastName === '' || $email === '') {
            json_error('First name, last name, and email are required.');
        }

        $dupeStmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $dupeStmt->execute([$email, $user['user_id']]);
        if ($dupeStmt->fetch()) {
            json_error('That email address is already in use.');
        }

        $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE user_id = ?")
            ->execute([$firstName, $lastName, $email, $phone, $user['user_id']]);

        $messages[] = 'Account details updated.';
    }

    if ($hasShippingUpdate) {
        $address = trim($in['shipping_address'] ?? '');
        $city    = trim($in['shipping_city'] ?? '');
        $postal  = trim($in['shipping_postal_code'] ?? '');
        $lat     = is_numeric($in['shipping_lat'] ?? null) ? (float) $in['shipping_lat'] : 0.0;
        $lng     = is_numeric($in['shipping_lng'] ?? null) ? (float) $in['shipping_lng'] : 0.0;

        if ($address === '' || $city === '' || $postal === '') {
            json_error('Shipping address, city, and postal code are required.');
        }

        $existing = $pdo->prepare("SELECT address_id FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, address_id DESC LIMIT 1");
        $existing->execute([$user['user_id']]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC);

        if ($existingRow) {
            $pdo->prepare("UPDATE user_addresses SET address_line = ?, city = ?, postal_code = ?, latitude = ?, longitude = ?, is_default = 1 WHERE address_id = ?")
                ->execute([$address, $city, $postal, $lat, $lng, $existingRow['address_id']]);
        } else {
            $pdo->prepare("INSERT INTO user_addresses (user_id, address_line, city, postal_code, latitude, longitude, is_default) VALUES (?, ?, ?, ?, ?, ?, 1)")
                ->execute([$user['user_id'], $address, $city, $postal, $lat, $lng]);
        }

        $messages[] = 'Shipping address saved.';
    }

    if (empty($messages)) {
        json_error('Nothing to update.');
    }

    json_out(['success' => true, 'message' => implode(' ', $messages)]);
} catch (PDOException $e) {
    json_error('An error occurred while updating your account.', 500);
}
