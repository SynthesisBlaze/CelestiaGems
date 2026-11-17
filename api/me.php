<?php
/**
 * GET /api/me.php   (Authorization: Bearer <token>)
 * Returns the logged-in user's profile + default shipping address —
 * everything the app's Account screen needs in one call.
 */
require_once __DIR__ . '/_bootstrap.php';

$user = require_auth($pdo);

$addrStmt = $pdo->prepare("
    SELECT address_line, city, postal_code, latitude, longitude
    FROM user_addresses
    WHERE user_id = ?
    ORDER BY is_default DESC, address_id DESC
    LIMIT 1
");
$addrStmt->execute([$user['user_id']]);
$address = $addrStmt->fetch(PDO::FETCH_ASSOC);

json_out([
    'success' => true,
    'user' => [
        'user_id'    => (int) $user['user_id'],
        'first_name' => $user['first_name'],
        'last_name'  => $user['last_name'],
        'email'      => $user['email'],
        'phone'      => $user['phone'],
        'created_at' => $user['created_at'] ?? null,
    ],
    'shipping_address' => $address ?: null,
]);
