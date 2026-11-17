<?php
/**
 * /api/wishlist.php   (Authorization: Bearer <token>)
 * GET  -> current wishlist, with product details
 * POST -> { product_id }  toggles it on/off, same behavior as
 *         functions/wishlist-toggle.php
 */
require_once __DIR__ . '/_bootstrap.php';

$user = require_auth($pdo);
$userId = (int) $user['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->prepare("
        SELECT p.product_id, p.name, p.price, img.image_url
        FROM wishlist_items w
        JOIN products p ON p.product_id = w.product_id
        LEFT JOIN product_images img ON p.product_id = img.product_id AND img.is_primary = 1
        WHERE w.user_id = ?
        ORDER BY w.wishlist_id DESC
    ");
    $stmt->execute([$userId]);
    $items = array_map(fn($r) => [
        'product_id' => (int) $r['product_id'],
        'name'       => $r['name'],
        'price'      => (float) $r['price'],
        'image'      => product_image_url($r['image_url']),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    json_out(['success' => true, 'wishlist' => $items]);
}

if ($method === 'POST') {
    $in = read_input();
    $productId = (int) ($in['product_id'] ?? 0);
    if ($productId <= 0) {
        json_error('A valid product_id is required.');
    }

    $checkStmt = $pdo->prepare("SELECT wishlist_id FROM wishlist_items WHERE user_id = ? AND product_id = ?");
    $checkStmt->execute([$userId, $productId]);

    if ($checkStmt->fetch()) {
        $pdo->prepare("DELETE FROM wishlist_items WHERE user_id = ? AND product_id = ?")->execute([$userId, $productId]);
        json_out(['success' => true, 'status' => 'removed']);
    }

    $pdo->prepare("INSERT INTO wishlist_items (user_id, product_id) VALUES (?, ?)")->execute([$userId, $productId]);
    json_out(['success' => true, 'status' => 'added']);
}

json_error('Method not allowed.', 405);
