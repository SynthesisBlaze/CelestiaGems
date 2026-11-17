<?php
/**
 * POST /api/review.php   (Authorization: Bearer <token>)
 * Body: { product_id, rating: 1-5, comment? }
 *
 * functions-less equivalent of the review block in pages/product.php,
 * enforcing the same two rules — and enforcing them here rather than
 * trusting the app, because the app's form is only a convenience:
 *
 *   1. You may only review a piece you have actually received. There has to
 *      be a Delivered order of yours containing this product.
 *   2. One review per product per customer.
 *
 * Both are re-checked inside the write, so a second tap on a slow
 * connection cannot leave two reviews behind.
 */
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

$user = require_auth($pdo);
$userId = (int) $user['user_id'];
$in = read_input();

$productId = (int) ($in['product_id'] ?? 0);
$rating    = (int) ($in['rating'] ?? 0);
$comment   = trim((string) ($in['comment'] ?? ''));

if ($productId <= 0) {
    json_error('A valid product is required.');
}
if ($rating < 1 || $rating > 5) {
    json_error('Please give a rating from 1 to 5 stars.');
}

// A review is public and permanent-ish; a stray 5000-word paste helps
// nobody and breaks every layout it lands in.
if (mb_strlen($comment) > 1000) {
    json_error('Please keep your review under 1000 characters.');
}

try {
    $bought = $pdo->prepare("
        SELECT 1 FROM orders o
        JOIN order_items oi ON o.order_id = oi.order_id
        WHERE o.user_id = ? AND oi.product_id = ? AND o.order_status = 'Delivered'
        LIMIT 1
    ");
    $bought->execute([$userId, $productId]);

    if (!$bought->fetchColumn()) {
        json_error(
            'You can review a piece once your order for it has been delivered.',
            403
        );
    }

    $already = $pdo->prepare("SELECT 1 FROM product_reviews WHERE user_id = ? AND product_id = ? LIMIT 1");
    $already->execute([$userId, $productId]);

    if ($already->fetchColumn()) {
        json_error('You have already reviewed this piece.', 409);
    }

    $pdo->prepare("
        INSERT INTO product_reviews (product_id, user_id, rating, comment, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ")->execute([$productId, $userId, $rating, $comment !== '' ? $comment : null]);

    // Hand back the fresh average so the product page can update without
    // re-fetching the whole product.
    $stats = $pdo->prepare("
        SELECT COUNT(*) AS count, AVG(rating) AS average
        FROM product_reviews WHERE product_id = ?
    ");
    $stats->execute([$productId]);
    $row = $stats->fetch(PDO::FETCH_ASSOC);

    json_out([
        'success' => true,
        'message' => 'Thank you — your review has been posted.',
        'rating_count'   => (int) $row['count'],
        'rating_average' => $row['average'] !== null ? round((float) $row['average'], 1) : null,
        'review' => [
            'rating'     => $rating,
            'comment'    => $comment !== '' ? $comment : null,
            'created_at' => date('Y-m-d H:i:s'),
            'reviewer'   => trim($user['first_name'] . ' ' . mb_substr($user['last_name'], 0, 1) . '.'),
        ],
    ]);
} catch (PDOException $e) {
    error_log('review.php error: ' . $e->getMessage());
    json_error('We could not post your review just now. Please try again.', 500);
}
