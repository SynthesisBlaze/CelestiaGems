<?php
session_start();
require_once __DIR__ . '/../functions/db_connection.php';

$current_page = 'order-success';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = $_SESSION['user_id'];

$order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
if ($order_id <= 0) {
    header("Location: account.php?tab=orders");
    exit;
}

// Order must belong to the logged-in user
$orderStmt = $pdo->prepare("SELECT order_id, total_amount, order_status, created_at FROM orders WHERE order_id = :order_id AND user_id = :user_id");
$orderStmt->execute(['order_id' => $order_id, 'user_id' => $user_id]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header("Location: account.php?tab=orders");
    exit;
}

$order_number = 'CG-' . str_pad((string) $order['order_id'], 6, '0', STR_PAD_LEFT);
$order_date = $order['created_at'] ? date('F j, Y \a\t g:i A', strtotime($order['created_at'])) : '';

$deliveryStmt = $pdo->prepare("SELECT address_line, delivery_status FROM deliveries WHERE order_id = :order_id LIMIT 1");
$deliveryStmt->execute(['order_id' => $order_id]);
$delivery = $deliveryStmt->fetch(PDO::FETCH_ASSOC);

$paymentStmt = $pdo->prepare("SELECT payment_method, payment_status FROM payments WHERE order_id = :order_id LIMIT 1");
$paymentStmt->execute(['order_id' => $order_id]);
$payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

$itemsStmt = $pdo->prepare("
    SELECT
        oi.quantity,
        oi.unit_price,
        p.name AS product_name,
        ps.size_label,
        img.image_url
    FROM order_items oi
    JOIN products p ON oi.product_id = p.product_id
    LEFT JOIN product_sizes ps ON oi.size_id = ps.size_id
    LEFT JOIN product_images img ON p.product_id = img.product_id AND img.is_primary = 1
    WHERE oi.order_id = :order_id
");
$itemsStmt->execute(['order_id' => $order_id]);
$order_items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$estimated_from = date('l, M j', strtotime('+3 weekdays'));
$estimated_to = date('l, M j', strtotime('+5 weekdays'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed — Celestia Gems</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/order-success.css">
</head>

<body>

    <?php include __DIR__ . '/../components/navbar.php'; ?>

    <main class="success-main">
        <div class="container">

            <!-- HERO: the seal moment -->
            <div class="success-hero">
                <div class="success-seal" aria-hidden="true">
                    <svg viewBox="0 0 120 120" fill="none">
                        <circle cx="60" cy="60" r="57" stroke="#C9A15C" stroke-width="1.5" />
                        <circle cx="60" cy="60" r="49" stroke="#C9A15C" stroke-width="1" opacity="0.5" />
                        <path d="M40 61.5L53.5 75L82 45" stroke="#e4c98d" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>

                <p class="success-kicker">Order confirmed</p>
                <h1 class="success-title">Your order is on its way to being made ready.</h1>
                <p class="success-sub">
                    Order <strong><?= htmlspecialchars($order_number) ?></strong> was placed on <?= htmlspecialchars($order_date) ?>.
                    A confirmation has been noted on your account.
                </p>

                <div class="success-eta">
                    <span>Estimated delivery</span>
                    <strong><?= htmlspecialchars($estimated_from) ?> &ndash; <?= htmlspecialchars($estimated_to) ?></strong>
                </div>
            </div>

            <!-- RECAP -->
            <div class="success-grid">

                <section class="success-panel">
                    <p class="cg-kicker">Delivery &amp; payment</p>

                    <div class="recap-row">
                        <span class="recap-label">Delivery address</span>
                        <span class="recap-value"><?= htmlspecialchars($delivery['address_line'] ?? '—') ?></span>
                    </div>
                    <div class="recap-row">
                        <span class="recap-label">Delivery status</span>
                        <span class="recap-value badge-status"><?= htmlspecialchars($delivery['delivery_status'] ?? 'Pending') ?></span>
                    </div>
                    <div class="recap-row">
                        <span class="recap-label">Payment method</span>
                        <span class="recap-value">Cash on Delivery</span>
                    </div>
                    <div class="recap-row">
                        <span class="recap-label">Payment status</span>
                        <span class="recap-value badge-status"><?= htmlspecialchars($payment['payment_status'] ?? 'Pending') ?></span>
                    </div>

                    <p class="recap-note">You'll pay <?= htmlspecialchars(number_format((float)$order['total_amount'], 2)) ?> in cash when your order arrives — nothing has been charged today.</p>
                </section>

                <section class="success-panel">
                    <p class="cg-kicker">Items ordered</p>

                    <div class="cg-item-list">
                        <?php foreach ($order_items as $item): ?>
                            <?php
                                $qty = (int) $item['quantity'];
                                $unit = (float) $item['unit_price'];
                                $lineTotal = $qty * $unit;
                                $itemImage = !empty($item['image_url']) ? '/img/' . $item['image_url'] : '/img/default-product.png';
                                $itemSize = !empty($item['size_label']) ? (string) $item['size_label'] : 'Standard';
                            ?>
                            <div class="cg-item-row">
                                <img src="<?= htmlspecialchars($itemImage) ?>" alt="<?= htmlspecialchars($item['product_name']) ?>">
                                <div class="flex-grow-1">
                                    <div class="cg-item-name"><?= htmlspecialchars($item['product_name']) ?></div>
                                    <div class="cg-item-meta">Size <?= htmlspecialchars($itemSize) ?> &nbsp;&middot;&nbsp; Qty <?= $qty ?></div>
                                </div>
                                <div class="cg-item-price">₱<?= number_format($lineTotal, 2) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="recap-total">
                        <span>Total</span>
                        <span>&#8369;<?= number_format((float) $order['total_amount'], 2) ?></span>
                    </div>
                </section>

            </div>

            <div class="success-actions">
                <a href="account.php?tab=orders" class="btn-secondary-line">View my orders</a>
                <a href="shop.php" class="btn-gold-fill">Continue shopping</a>
            </div>

        </div>
    </main>

    <?php include __DIR__ . '/../components/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>