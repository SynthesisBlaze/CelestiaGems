<?php
session_start();
require_once __DIR__ . '/db_connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in to manage your cart.']);
    exit;
}

$cartItemId = isset($_POST['cart_item_id']) ? (int) $_POST['cart_item_id'] : 0;
$change = isset($_POST['change']) ? (int) $_POST['change'] : 0;

if ($cartItemId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid cart item.']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT quantity
    FROM cart_items
    WHERE cart_item_id = :cart_item_id AND user_id = :user_id
    LIMIT 1
");
$stmt->execute(['cart_item_id' => $cartItemId, 'user_id' => $userId]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Cart item not found.']);
    exit;
}

$newQty = (int) $item['quantity'] + $change;
if ($newQty <= 0) {
    $deleteStmt = $pdo->prepare("DELETE FROM cart_items WHERE cart_item_id = :cart_item_id AND user_id = :user_id");
    $deleteStmt->execute(['cart_item_id' => $cartItemId, 'user_id' => $userId]);

    $countStmt = $pdo->prepare("SELECT SUM(quantity) AS total FROM cart_items WHERE user_id = :user_id");
    $countStmt->execute(['user_id' => $userId]);
    $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'count' => (int) ($countRow['total'] ?? 0), 'message' => 'Item removed from cart.']);
    exit;
}

$updateStmt = $pdo->prepare("UPDATE cart_items SET quantity = :quantity WHERE cart_item_id = :cart_item_id AND user_id = :user_id");
$updateStmt->execute([
    'quantity' => $newQty,
    'cart_item_id' => $cartItemId,
    'user_id' => $userId
]);

$countStmt = $pdo->prepare("SELECT SUM(quantity) AS total FROM cart_items WHERE user_id = :user_id");
$countStmt->execute(['user_id' => $userId]);
$countRow = $countStmt->fetch(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'count' => (int) ($countRow['total'] ?? 0), 'message' => 'Cart updated.']);
