<?php
require_once __DIR__ . '/../functions/permissions.php';
cg_require_page('orders.view');

require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';

$order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
$type = ($_GET['type'] ?? 'invoice') === 'packing-slip' ? 'packing-slip' : 'invoice';

if ($order_id <= 0) {
    die('Invalid order.');
}

$orderStmt = $pdo->prepare("
    SELECT o.order_id, o.total_amount, o.order_status, o.created_at, o.guest_email,
           u.first_name, u.last_name, u.email, u.phone
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.user_id
    WHERE o.order_id = :order_id
");
$orderStmt->execute(['order_id' => $order_id]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die('Order not found.');
}

$deliveryStmt = $pdo->prepare("SELECT address_line FROM deliveries WHERE order_id = :order_id LIMIT 1");
$deliveryStmt->execute(['order_id' => $order_id]);
$delivery = $deliveryStmt->fetch(PDO::FETCH_ASSOC);

$paymentStmt = $pdo->prepare("SELECT payment_method, payment_status FROM payments WHERE order_id = :order_id LIMIT 1");
$paymentStmt->execute(['order_id' => $order_id]);
$payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

$itemsStmt = $pdo->prepare("
    SELECT oi.quantity, oi.unit_price, p.name AS product_name, ps.size_label
    FROM order_items oi
    JOIN products p ON oi.product_id = p.product_id
    LEFT JOIN product_sizes ps ON oi.size_id = ps.size_id
    WHERE oi.order_id = :order_id
");
$itemsStmt->execute(['order_id' => $order_id]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Shipping isn't stored as its own column — it's baked into total_amount —
// so it's derived here the same way cart.php/checkout.php compute it:
// items subtotal vs. the recorded total.
$subtotal = 0.0;
foreach ($items as $item) {
    $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
}
$shipping = max(0, (float) $order['total_amount'] - $subtotal);

// Store details for the header — same source content.php edits (contact_page).
$storeStmt = $pdo->query("SELECT store_name, store_address, phone, email_general FROM contact_page LIMIT 1");
$store = $storeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$storeName = $store['store_name'] ?? 'Celestia Gems';
$storeAddress = $store['store_address'] ?? '';
$storePhone = $store['phone'] ?? '';
$storeEmail = $store['email_general'] ?? '';

$orderNumber = format_order_number((int) $order['order_id']);
$customerName = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
$customerName = $customerName !== '' ? $customerName : 'Guest';
$customerEmail = $order['email'] ?? $order['guest_email'] ?? '';
$customerPhone = $order['phone'] ?? '';
$addressLine = $delivery['address_line'] ?? 'No address on file';
$orderDate = date('F j, Y', strtotime($order['created_at']));

$docTitle = $type === 'packing-slip' ? 'Packing Slip' : 'Invoice';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($docTitle) ?> <?= htmlspecialchars($orderNumber) ?> — Celestia Gems</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: 'Jost', sans-serif;
            font-weight: 300;
            color: #1c140c;
            background: #e9e9e9;
            margin: 0;
            padding: 2rem 0;
        }

        h1, h2, h3 {
            font-family: 'Cormorant Garamond', serif;
            font-weight: 500;
            margin: 0;
        }

        .doc {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            padding: 3rem 3.2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .toolbar {
            max-width: 800px;
            margin: 0 auto 1rem;
            display: flex;
            justify-content: flex-end;
            gap: .6rem;
        }

        .toolbar button, .toolbar a {
            font-family: 'Jost', sans-serif;
            font-size: .8rem;
            letter-spacing: .04em;
            text-transform: uppercase;
            padding: .55rem 1.1rem;
            border-radius: 3px;
            border: 1px solid #C9A15C;
            background: #C9A15C;
            color: #1c140c;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .toolbar a.secondary {
            background: #fff;
            color: #555;
            border-color: #ccc;
        }

        .doc-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #1c140c;
            padding-bottom: 1.2rem;
            margin-bottom: 1.6rem;
        }

        .store-name {
            font-size: 1.5rem;
        }

        .store-meta {
            font-size: .8rem;
            color: #666;
            margin-top: .3rem;
            line-height: 1.5;
        }

        .doc-type {
            text-align: right;
        }

        .doc-type h2 {
            font-size: 1.7rem;
            letter-spacing: .05em;
        }

        .doc-type .order-number {
            font-size: .85rem;
            color: #666;
            margin-top: .2rem;
        }

        .doc-meta-row {
            display: flex;
            justify-content: space-between;
            gap: 2rem;
            margin-bottom: 1.8rem;
        }

        .doc-meta-block .label {
            font-size: .68rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #999;
            margin-bottom: .3rem;
        }

        .doc-meta-block .value {
            font-size: .9rem;
            line-height: 1.6;
        }

        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
        }

        table.items th {
            text-align: left;
            font-size: .68rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #999;
            padding: .5rem 0;
            border-bottom: 2px solid #1c140c;
        }

        table.items td {
            padding: .7rem 0;
            border-bottom: 1px solid #eee;
            font-size: .9rem;
        }

        table.items th.num, table.items td.num {
            text-align: right;
        }

        .totals {
            width: 260px;
            margin-left: auto;
            margin-bottom: 2rem;
        }

        .totals .row {
            display: flex;
            justify-content: space-between;
            padding: .35rem 0;
            font-size: .88rem;
        }

        .totals .row.grand {
            border-top: 2px solid #1c140c;
            margin-top: .4rem;
            padding-top: .6rem;
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.2rem;
        }

        .payment-note {
            font-size: .82rem;
            color: #555;
            background: #faf6ee;
            border-left: 3px solid #C9A15C;
            padding: .7rem 1rem;
            margin-bottom: 1.5rem;
        }

        .signature-block {
            display: flex;
            justify-content: space-between;
            margin-top: 3.5rem;
            gap: 3rem;
        }

        .signature-line {
            flex: 1;
            border-top: 1px solid #999;
            padding-top: .4rem;
            font-size: .78rem;
            color: #666;
        }

        .doc-footer {
            margin-top: 2.5rem;
            padding-top: 1.2rem;
            border-top: 1px solid #eee;
            font-size: .78rem;
            color: #999;
            text-align: center;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none; }
            .doc { box-shadow: none; padding: 0; max-width: none; }
        }
    </style>
</head>

<body>

    <div class="toolbar">
        <a href="orders_management.php" class="secondary">&larr; Back to Orders</a>
        <?php if ($type === 'invoice'): ?>
            <a href="?order_id=<?= $order_id ?>&type=packing-slip" class="secondary">View Packing Slip</a>
        <?php else: ?>
            <a href="?order_id=<?= $order_id ?>&type=invoice" class="secondary">View Invoice</a>
        <?php endif; ?>
        <button onclick="window.print()">Print</button>
    </div>

    <div class="doc">
        <div class="doc-header">
            <div>
                <div class="store-name"><?= htmlspecialchars($storeName) ?></div>
                <div class="store-meta">
                    <?= $storeAddress !== '' ? htmlspecialchars($storeAddress) . '<br>' : '' ?>
                    <?= $storePhone !== '' ? htmlspecialchars($storePhone) . ' &middot; ' : '' ?><?= htmlspecialchars($storeEmail) ?>
                </div>
            </div>
            <div class="doc-type">
                <h2><?= htmlspecialchars($docTitle) ?></h2>
                <div class="order-number"><?= htmlspecialchars($orderNumber) ?></div>
                <div class="order-number"><?= htmlspecialchars($orderDate) ?></div>
            </div>
        </div>

        <div class="doc-meta-row">
            <div class="doc-meta-block">
                <div class="label"><?= $type === 'packing-slip' ? 'Ship To' : 'Bill To' ?></div>
                <div class="value">
                    <strong><?= htmlspecialchars($customerName) ?></strong><br>
                    <?= htmlspecialchars($addressLine) ?>
                    <?php if ($type === 'invoice'): ?>
                        <br><?= htmlspecialchars($customerEmail) ?>
                        <?= $customerPhone !== '' ? '<br>' . htmlspecialchars($customerPhone) : '' ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="doc-meta-block" style="text-align:right;">
                <div class="label">Order Status</div>
                <div class="value"><?= htmlspecialchars($order['order_status']) ?></div>
            </div>
        </div>

        <?php if ($type === 'invoice' && $payment): ?>
            <div class="payment-note">
                Paid via <?= htmlspecialchars($payment['payment_method'] === 'cod' ? 'Cash on Delivery' : ucfirst($payment['payment_method'])) ?>
                &middot; Status: <strong><?= htmlspecialchars($payment['payment_status']) ?></strong>
            </div>
        <?php endif; ?>

        <table class="items">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Size</th>
                    <th class="num">Qty</th>
                    <?php if ($type === 'invoice'): ?>
                        <th class="num">Unit Price</th>
                        <th class="num">Line Total</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <td><?= htmlspecialchars($item['size_label'] ?: 'Standard') ?></td>
                        <td class="num"><?= (int) $item['quantity'] ?></td>
                        <?php if ($type === 'invoice'): ?>
                            <td class="num">₱<?= number_format((float) $item['unit_price'], 2) ?></td>
                            <td class="num">₱<?= number_format((float) $item['unit_price'] * (int) $item['quantity'], 2) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($type === 'invoice'): ?>
            <div class="totals">
                <div class="row"><span>Subtotal</span><span>₱<?= number_format($subtotal, 2) ?></span></div>
                <div class="row"><span>Shipping</span><span><?= $shipping > 0 ? '₱' . number_format($shipping, 2) : 'Free' ?></span></div>
                <div class="row grand"><span>Total</span><span>₱<?= number_format((float) $order['total_amount'], 2) ?></span></div>
            </div>
        <?php endif; ?>

        <?php if ($type === 'packing-slip'): ?>
            <div class="signature-block">
                <div class="signature-line">Packed by</div>
                <div class="signature-line">Checked by</div>
            </div>
        <?php endif; ?>

        <div class="doc-footer">
            <?= $type === 'invoice' ? 'Thank you for shopping with ' . htmlspecialchars($storeName) . '.' : htmlspecialchars($orderNumber) . ' &middot; ' . htmlspecialchars($storeName) ?>
        </div>
    </div>

</body>

</html>
