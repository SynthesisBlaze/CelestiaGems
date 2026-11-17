<?php
/**
 * GET /api/orders.php   (Authorization: Bearer <token>)
 * List of the logged-in user's orders, newest first — for the app's
 * "My Orders" screen. For a single order's line items, call
 * order-detail.php?order_id=...
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../functions/helpers.php';

$user = require_auth($pdo);

// item_count comes from a correlated subquery rather than a JOIN + GROUP BY,
// because payments and deliveries are already LEFT JOINed here and summing
// across them would multiply the quantities. Same shape account.php uses.
$stmt = $pdo->prepare("
    SELECT o.order_id, o.total_amount, o.order_status, o.created_at,
           pay.payment_method, pay.payment_status,
           d.delivery_status,
           (SELECT COALESCE(SUM(oi.quantity), 0)
              FROM order_items oi WHERE oi.order_id = o.order_id) AS item_count
    FROM orders o
    LEFT JOIN payments pay ON pay.order_id = o.order_id
    LEFT JOIN deliveries d ON d.order_id = o.order_id
    WHERE o.user_id = ?
    ORDER BY o.created_at DESC
");
$stmt->execute([$user['user_id']]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$orders = array_map(function ($r) {
    return [
        'order_id'       => (int) $r['order_id'],
        'order_number'   => format_order_number((int) $r['order_id']),
        'total_amount'   => (float) $r['total_amount'],
        'item_count'     => (int) $r['item_count'],
        'order_status'   => $r['order_status'],
        'payment_method' => $r['payment_method'],
        'payment_status' => $r['payment_status'],
        'delivery_status'=> $r['delivery_status'],
        'created_at'     => $r['created_at'],
    ];
}, $rows);

// One primary thumbnail per product in each order, so the list can show
// what was bought instead of a reference number on its own.
$orderIds = array_column($orders, 'order_id');
if (!empty($orderIds)) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $thumbStmt = $pdo->prepare("
        SELECT oi.order_id, oi.product_id, p.name AS product_name, img.image_url
        FROM order_items oi
        JOIN products p ON oi.product_id = p.product_id
        LEFT JOIN product_images img ON p.product_id = img.product_id AND img.is_primary = 1
        WHERE oi.order_id IN ($placeholders)
        ORDER BY oi.order_item_id ASC
    ");
    $thumbStmt->execute($orderIds);

    $byOrder = [];
    foreach ($thumbStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byOrder[(int) $row['order_id']][] = [
            'product_id' => (int) $row['product_id'],
            'name'       => $row['product_name'],
            'image'      => product_image_url($row['image_url']),
        ];
    }

    foreach ($orders as &$o) {
        $all = $byOrder[$o['order_id']] ?? [];
        $o['thumbnails']  = array_slice($all, 0, 4);
        $o['extra_count'] = max(0, count($all) - 4);
    }
    unset($o);
}

json_out(['success' => true, 'orders' => $orders]);
