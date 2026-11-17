<?php
/**
 * GET /api/product-detail.php?id=16
 * Public endpoint. Returns one product with all images, all sizes
 * (with live stock_quantity — this is the number that must match what
 * the admin panel just saved), and a review summary.
 */
require_once __DIR__ . '/_bootstrap.php';

$productId = (int) ($_GET['id'] ?? 0);
if ($productId <= 0) {
    json_error('A valid product id is required.');
}

$stmt = $pdo->prepare("
    SELECT p.product_id, p.name, p.price, p.description, p.category_id,
           sub.name AS category_name, main.name AS main_category_name
    FROM products p
    INNER JOIN categories sub ON p.category_id = sub.category_id
    LEFT JOIN categories main ON sub.parent_id = main.category_id
    WHERE p.product_id = ?
");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    json_error('Product not found.', 404);
}

$imgStmt = $pdo->prepare("SELECT image_id, image_url, is_primary FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, image_id ASC");
$imgStmt->execute([$productId]);
$images = array_map(fn($r) => [
    'image_id'   => (int) $r['image_id'],
    'url'        => product_image_url($r['image_url']),
    'is_primary' => (bool) $r['is_primary'],
], $imgStmt->fetchAll(PDO::FETCH_ASSOC));

$sizeStmt = $pdo->prepare("SELECT size_id, size_label, gender, stock_quantity FROM product_sizes WHERE product_id = ? ORDER BY size_id ASC");
$sizeStmt->execute([$productId]);
$sizes = array_map(fn($r) => [
    'size_id'        => (int) $r['size_id'],
    'size_label'     => $r['size_label'],
    'gender'         => $r['gender'],
    'stock_quantity' => (int) $r['stock_quantity'],
    'in_stock'       => (int) $r['stock_quantity'] > 0,
], $sizeStmt->fetchAll(PDO::FETCH_ASSOC));

$reviewStmt = $pdo->prepare("
    SELECT r.rating, r.comment, r.created_at, u.first_name, u.last_name
    FROM product_reviews r
    JOIN users u ON u.user_id = r.user_id
    WHERE r.product_id = ?
    ORDER BY r.created_at DESC
    LIMIT 20
");
$reviewStmt->execute([$productId]);
$reviewRows = $reviewStmt->fetchAll(PDO::FETCH_ASSOC);
$avgRating = $reviewRows ? array_sum(array_column($reviewRows, 'rating')) / count($reviewRows) : null;

$wishlisted = false;
$canReview = false;
$hasReviewed = false;
$token = get_bearer_token();
if ($token) {
    $userStmt = $pdo->prepare("SELECT user_id FROM api_tokens WHERE token = ? AND expires_at > NOW()");
    $userStmt->execute([$token]);
    $userId = $userStmt->fetchColumn();
    if ($userId) {
        $wCheck = $pdo->prepare("SELECT 1 FROM wishlist_items WHERE user_id = ? AND product_id = ?");
        $wCheck->execute([$userId, $productId]);
        $wishlisted = (bool) $wCheck->fetchColumn();

        // Same two rules pages/product.php applies: you may review a piece
        // you have actually received, and only once. Answering here means
        // the app can show or hide the form on first paint instead of
        // offering a button that the server would refuse.
        $bought = $pdo->prepare("
            SELECT 1 FROM orders o
            JOIN order_items oi ON o.order_id = oi.order_id
            WHERE o.user_id = ? AND oi.product_id = ? AND o.order_status = 'Delivered'
            LIMIT 1
        ");
        $bought->execute([$userId, $productId]);
        $hasBought = (bool) $bought->fetchColumn();

        $already = $pdo->prepare("SELECT 1 FROM product_reviews WHERE user_id = ? AND product_id = ? LIMIT 1");
        $already->execute([$userId, $productId]);
        $hasReviewed = (bool) $already->fetchColumn();

        $canReview = $hasBought && !$hasReviewed;
    }
}

json_out([
    'success' => true,
    'product' => [
        'product_id'    => (int) $product['product_id'],
        'name'          => $product['name'],
        'price'         => (float) $product['price'],
        'description'   => $product['description'],
        'category_id'   => (int) $product['category_id'],
        'category'      => $product['category_name'],
        'main_category' => $product['main_category_name'],
        'wishlisted'    => $wishlisted,
        'can_review'    => $canReview,
        'has_reviewed'  => $hasReviewed,
        'images'        => $images,
        'sizes'         => $sizes,
        'rating_average'=> $avgRating !== null ? round($avgRating, 1) : null,
        'rating_count'  => count($reviewRows),
        'reviews'       => array_map(fn($r) => [
            'rating'     => (int) $r['rating'],
            'comment'    => $r['comment'],
            'created_at' => $r['created_at'],
            'reviewer'   => trim($r['first_name'] . ' ' . mb_substr($r['last_name'], 0, 1) . '.'),
        ], $reviewRows),
    ],
]);
