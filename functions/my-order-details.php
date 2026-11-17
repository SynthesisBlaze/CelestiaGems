<?php
session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in.']);
    exit;
}

$user_id  = (int) $_SESSION['user_id'];
$order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order.']);
    exit;
}

// Ownership check — a customer can only ever see their own orders
$orderStmt = $pdo->prepare("
    SELECT order_id, total_amount, order_status, created_at
    FROM orders
    WHERE order_id = :order_id AND user_id = :user_id
");
$orderStmt->execute(['order_id' => $order_id, 'user_id' => $user_id]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo json_encode(['success' => false, 'message' => 'Order not found.']);
    exit;
}

$deliveryStmt = $pdo->prepare("SELECT address_line, delivery_status FROM deliveries WHERE order_id = :order_id LIMIT 1");
$deliveryStmt->execute(['order_id' => $order_id]);
$delivery = $deliveryStmt->fetch(PDO::FETCH_ASSOC);

$paymentStmt = $pdo->prepare("SELECT payment_method, payment_status FROM payments WHERE order_id = :order_id LIMIT 1");
$paymentStmt->execute(['order_id' => $order_id]);
$payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

$itemsStmt = $pdo->prepare("
    SELECT oi.quantity, oi.unit_price, oi.product_id, p.name AS product_name, ps.size_label, img.image_url
    FROM order_items oi
    JOIN products p ON oi.product_id = p.product_id
    LEFT JOIN product_sizes ps ON oi.size_id = ps.size_id
    LEFT JOIN product_images img ON p.product_id = img.product_id AND img.is_primary = 1
    WHERE oi.order_id = :order_id
");
$itemsStmt->execute(['order_id' => $order_id]);
$itemRows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$items = array_map(function ($item) {
    return [
        'product_id' => (int) $item['product_id'],
        'name'       => $item['product_name'],
        'size'       => $item['size_label'] ?: 'Standard',
        'quantity'   => (int) $item['quantity'],
        'line_total' => (float) $item['unit_price'] * (int) $item['quantity'],
        'image'      => !empty($item['image_url']) ? '/img/' . $item['image_url'] : '/img/default-product.png',
    ];
}, $itemRows);

echo json_encode([
    'success' => true,
    'order' => [
        'order_number'   => format_order_number((int) $order['order_id']),
        'created_at'     => date('M j, Y \a\t g:i A', strtotime($order['created_at'])),
        'order_status'   => $order['order_status'],
        'total_amount'   => (float) $order['total_amount'],
        'address_line'   => $delivery['address_line'] ?? null,
        'delivery_status'=> $delivery['delivery_status'] ?? null,
        'payment_method' => ($payment['payment_method'] ?? 'cod') === 'cod' ? 'Cash on Delivery' : ($payment['payment_method'] ?? 'Cash on Delivery'),
        'payment_status' => $payment['payment_status'] ?? 'Pending',
        'items'          => $items,
    ],
]);
