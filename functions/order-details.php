<?php
session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

require_once __DIR__ . '/permissions.php';
cg_require_json('orders.view');

$order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order.']);
    exit;
}

$orderStmt = $pdo->prepare("
    SELECT o.order_id, o.total_amount, o.order_status, o.created_at, o.guest_email,
           u.first_name, u.last_name, u.email
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.user_id
    WHERE o.order_id = :order_id
");
$orderStmt->execute(['order_id' => $order_id]);
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
    SELECT oi.quantity, oi.unit_price, p.name AS product_name, ps.size_label, img.image_url
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
        'name'       => $item['product_name'],
        'size'       => $item['size_label'] ?: 'Standard',
        'quantity'   => (int) $item['quantity'],
        'line_total' => (float) $item['unit_price'] * (int) $item['quantity'],
        'image'      => !empty($item['image_url']) ? '/img/' . $item['image_url'] : '/img/default-product.png',
    ];
}, $itemRows);

$customerName = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));

echo json_encode([
    'success' => true,
    'order' => [
        'order_number'    => format_order_number((int) $order['order_id']),
        'customer_name'   => $customerName !== '' ? $customerName : 'Guest',
        'customer_email'  => $order['email'] ?? $order['guest_email'] ?? '',
        'created_at'      => date('M j, Y \a\t g:i A', strtotime($order['created_at'])),
        'order_status'    => $order['order_status'],
        'total_amount'    => (float) $order['total_amount'],
        'address_line'    => $delivery['address_line'] ?? null,
        'delivery_status' => $delivery['delivery_status'] ?? null,
        'payment_method'  => $payment['payment_method'] ?? 'cod',
        'payment_status'  => $payment['payment_status'] ?? 'Pending',
        'items'           => $items,
    ],
]);
