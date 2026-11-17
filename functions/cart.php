<?php
session_start();

require_once __DIR__ . '/db_connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in to add items to your bag.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = array();
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

if (empty($payload)) {
    $payload = $_POST;
}

$action = isset($payload['action']) ? strtolower((string) $payload['action']) : 'add';
$userId = (int) ($_SESSION['user_id'] ?? 0);
$cart = $_SESSION['cart'] ?? [];

if ($action === 'update' || $action === 'remove') {
    $itemKey = isset($payload['item_key']) ? (string) $payload['item_key'] : '';
    if ($itemKey === '' && isset($payload['product_id'])) {
        $sizeLabel = isset($payload['size_label']) ? trim((string) $payload['size_label']) : 'Standard';
        $itemKey = ((int) $payload['product_id']) . '|' . $sizeLabel;
    }

    if ($itemKey !== '' && isset($cart[$itemKey])) {
        if ($action === 'remove') {
            unset($cart[$itemKey]);
        } elseif ($action === 'update') {
            $newQty = max(1, (int) ($payload['quantity'] ?? 1));
            $cart[$itemKey]['quantity'] = $newQty;
        }
    }

    $_SESSION['cart'] = $cart;

    $cartCount = 0;
    foreach ($cart as $item) {
        $cartCount += (int) ($item['quantity'] ?? 0);
    }

    echo json_encode([
        'success' => true,
        'count' => $cartCount,
        'message' => $action === 'remove' ? 'Item removed.' : 'Cart updated.'
    ]);
    exit;
}

$productId = isset($payload['product_id']) ? (int) $payload['product_id'] : 0;
$quantity = isset($payload['quantity']) ? max(1, (int) $payload['quantity']) : 1;
$sizeId = isset($payload['size_id']) ? (int) $payload['size_id'] : 0;
$sizeLabel = isset($payload['size_label']) ? trim((string) $payload['size_label']) : '';

if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid product.']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        p.product_id,
        p.name,
        p.price,
        img.image_url AS image,
        sub.name AS sub_name
    FROM products p
    LEFT JOIN product_images img ON p.product_id = img.product_id AND img.is_primary = 1
    INNER JOIN categories sub ON p.category_id = sub.category_id
    WHERE p.product_id = :product_id
    LIMIT 1
");
$stmt->execute(['product_id' => $productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Product not found.']);
    exit;
}

if ($sizeId > 0) {
    $sizeStmt = $pdo->prepare("
        SELECT size_label
        FROM product_sizes
        WHERE size_id = :size_id AND product_id = :product_id
        LIMIT 1
    ");
    $sizeStmt->execute(['size_id' => $sizeId, 'product_id' => $productId]);
    $sizeRow = $sizeStmt->fetch(PDO::FETCH_ASSOC);
    if ($sizeRow) {
        $sizeLabel = $sizeRow['size_label'];
    }
}

if ($sizeLabel === '') {
    $sizeLabel = 'Standard';
}

$cartKey = $productId . '|' . $sizeLabel;

if ($userId > 0) {
    $existingStmt = $pdo->prepare("
        SELECT cart_item_id, quantity
        FROM cart_items
        WHERE user_id = :user_id AND product_id = :product_id AND COALESCE(size_id, 0) = COALESCE(:size_id, 0)
        LIMIT 1
    ");
    $existingStmt->execute([
        'user_id' => $userId,
        'product_id' => $productId,
        'size_id' => $sizeId > 0 ? $sizeId : null
    ]);
    $existingItem = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingItem) {
        $newQty = (int) $existingItem['quantity'] + $quantity;
        $updateStmt = $pdo->prepare("UPDATE cart_items SET quantity = :quantity WHERE cart_item_id = :cart_item_id AND user_id = :user_id");
        $updateStmt->execute([
            'quantity' => $newQty,
            'cart_item_id' => (int) $existingItem['cart_item_id'],
            'user_id' => $userId
        ]);
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO cart_items (user_id, product_id, size_id, quantity)
            VALUES (:user_id, :product_id, :size_id, :quantity)
        ");
        $insertStmt->execute([
            'user_id' => $userId,
            'product_id' => $productId,
            'size_id' => $sizeId > 0 ? $sizeId : null,
            'quantity' => $quantity
        ]);
    }
}

if (isset($cart[$cartKey])) {
    $cart[$cartKey]['quantity'] += $quantity;
} else {
    $cart[$cartKey] = [
        'product_id' => (int) $product['product_id'],
        'name' => $product['name'],
        'price' => (float) $product['price'],
        'image' => $product['image'] ?? '',
        'size_id' => $sizeId > 0 ? $sizeId : null,
        'size_label' => $sizeLabel,
        'quantity' => $quantity,
    ];
}

$_SESSION['cart'] = $cart;

$cartCount = 0;
foreach ($cart as $item) {
    $cartCount += (int) ($item['quantity'] ?? 0);
}

echo json_encode([
    'success' => true,
    'message' => 'Item added to bag.',
    'count' => $cartCount,
    'item_count' => count($cart),
]);
