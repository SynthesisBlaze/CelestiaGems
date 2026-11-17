<?php
/**
 * GET /api/order-detail.php?order_id=123   (Authorization: Bearer <token>)
 * Mirrors functions/my-order-details.php, token-authed instead of session,
 * with an ownership check (a user can only ever see their own order).
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../functions/helpers.php';

$user = require_auth($pdo);
$order_id = (int) ($_GET['order_id'] ?? 0);

if ($order_id <= 0) {
    json_error('A valid order_id is required.');
}

$orderStmt = $pdo->prepare("
    SELECT order_id, total_amount, order_status, created_at
    FROM orders WHERE order_id = :order_id AND user_id = :user_id
");
$orderStmt->execute(['order_id' => $order_id, 'user_id' => $user['user_id']]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    json_error('Order not found.', 404);
}

$deliveryStmt = $pdo->prepare("SELECT address_line, delivery_status FROM deliveries WHERE order_id = :order_id LIMIT 1");
$deliveryStmt->execute(['order_id' => $order_id]);
$delivery = $deliveryStmt->fetch(PDO::FETCH_ASSOC);

$paymentStmt = $pdo->prepare("SELECT payment_method, payment_status FROM payments WHERE order_id = :order_id LIMIT 1");
$paymentStmt->execute(['order_id' => $order_id]);
$payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

$itemsStmt = $pdo->prepare("
    SELECT oi.quantity, oi.unit_price, p.product_id, p.name AS product_name, ps.size_label, img.image_url
    FROM order_items oi
    JOIN products p ON oi.product_id = p.product_id
    LEFT JOIN product_sizes ps ON oi.size_id = ps.size_id
    LEFT JOIN product_images img ON p.product_id = img.product_id AND img.is_primary = 1
    WHERE oi.order_id = :order_id
");
$itemsStmt->execute(['order_id' => $order_id]);
$items = array_map(function ($item) {
    return [
        'product_id' => (int) $item['product_id'],
        'name'       => $item['product_name'],
        'size'       => $item['size_label'] ?: 'Standard',
        'quantity'   => (int) $item['quantity'],
        'unit_price' => (float) $item['unit_price'],
        'line_total' => (float) $item['unit_price'] * (int) $item['quantity'],
        'image'      => product_image_url($item['image_url']),
    ];
}, $itemsStmt->fetchAll(PDO::FETCH_ASSOC));

json_out([
    'success' => true,
    'order' => [
        'order_id'        => (int) $order['order_id'],
        'order_number'    => format_order_number((int) $order['order_id']),
        'created_at'      => $order['created_at'],
        'order_status'    => $order['order_status'],
        'total_amount'    => (float) $order['total_amount'],
        'address_line'    => $delivery['address_line'] ?? null,
        'delivery_status' => $delivery['delivery_status'] ?? null,
        'payment_method'  => $payment['payment_method'] ?? 'cod',
        'payment_status'  => $payment['payment_status'] ?? 'Pending',
        'items'           => $items,
    ],
]);
