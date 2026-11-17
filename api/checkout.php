<?php
/**
 * POST /api/checkout.php   (Authorization: Bearer <token>)
 * Body: { address_line, city, postal_code, latitude?, longitude?,
 *         payment_method: "cod"|"paymongo" }
 *
 * This is functions/checkout-process.php's exact logic (same transaction,
 * same FOR UPDATE stock lock, same shipping/free-shipping math, same audit
 * log, same PayMongo checkout session) rewritten to return JSON instead of
 * doing header() redirects.
 *
 * The one real difference from the web flow: the PayMongo success/cancel
 * URLs can't point at functions/order-payment-return.php, because that page
 * checks $_SESSION['user_id'] — and a browser opened from the app has no PHP
 * session. They point at api/payment-return.php instead, which does the same
 * PayMongo verification without needing a session.
 *
 * Responses:
 *   cod success      -> { success: true, order_id, order_number, requires_payment: false }
 *   paymongo success -> { success: true, order_id, order_number, requires_payment: true, checkout_url }
 *                        (open checkout_url with expo-web-browser, then read
 *                         order-detail.php for the final status)
 *   error            -> { success: false, message }
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../functions/paymongo.php';
require_once __DIR__ . '/../functions/settings_helper.php';
require_once __DIR__ . '/../functions/audit_logger.php';
require_once __DIR__ . '/../functions/notifier.php';
require_once __DIR__ . '/../functions/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

$user = require_auth($pdo);
$userId = (int) $user['user_id'];
$in = read_input();

$address_line = trim($in['address_line'] ?? '');
$city         = trim($in['city'] ?? '');
$postal_code  = trim($in['postal_code'] ?? '');
$latitude     = isset($in['latitude']) ? (float) $in['latitude'] : null;
$longitude    = isset($in['longitude']) ? (float) $in['longitude'] : null;

$codEnabled      = get_setting_bool($pdo, 'payment_cod_enabled', true);
$paymongoEnabled = get_setting_bool($pdo, 'payment_paymongo_enabled', true);

$payment_method = $in['payment_method'] ?? '';
$methodIsValid = ($payment_method === 'cod' && $codEnabled)
    || ($payment_method === 'paymongo' && $paymongoEnabled);

if (!$methodIsValid) {
    json_error('That payment method is not available right now. Please choose another.');
}
if ($address_line === '' || $city === '' || $postal_code === '') {
    json_error('Please provide a complete delivery address.');
}

$full_address = $address_line . ', ' . $city . ' ' . $postal_code;

try {
    $pdo->beginTransaction();

    // Re-fetch the cart server-side rather than trusting anything from the
    // client. Postgres refuses FOR UPDATE on the nullable side of a LEFT
    // JOIN ("FOR UPDATE cannot be applied to the nullable side of an outer
    // join"), so the cart rows are locked here and the stock rows are
    // locked and read in a separate query below — the same split the web's
    // checkout-process.php uses.
    $cartStmt = $pdo->prepare("
        SELECT ci.cart_item_id, ci.product_id, ci.size_id, ci.quantity,
               p.price
        FROM cart_items ci
        JOIN products p ON ci.product_id = p.product_id
        WHERE ci.user_id = :user_id
        FOR UPDATE OF ci
    ");
    $cartStmt->execute(['user_id' => $userId]);
    $cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cartItems)) {
        throw new Exception('Your cart is empty.');
    }

    // Lock the stock rows for these sizes (ordered by size_id so concurrent
    // checkouts always lock in the same order and can't deadlock), then
    // attach the fresh, locked stock figure to each cart item.
    $sizeIds = array_values(array_unique(array_filter(
        array_column($cartItems, 'size_id'),
        fn($v) => $v !== null
    )));
    $stockBySize = [];
    if (!empty($sizeIds)) {
        $placeholders = implode(',', array_fill(0, count($sizeIds), '?'));
        $lockStmt = $pdo->prepare("
            SELECT size_id, stock_quantity FROM product_sizes
            WHERE size_id IN ($placeholders)
            ORDER BY size_id
            FOR UPDATE
        ");
        $lockStmt->execute($sizeIds);
        foreach ($lockStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stockBySize[(int) $row['size_id']] = (int) $row['stock_quantity'];
        }
    }
    foreach ($cartItems as &$item) {
        $item['stock_quantity'] = $item['size_id'] !== null
            ? ($stockBySize[(int) $item['size_id']] ?? 0)
            : null;
    }
    unset($item);

    foreach ($cartItems as $item) {
        if ($item['size_id'] !== null && (int) $item['stock_quantity'] < (int) $item['quantity']) {
            throw new Exception('One or more items in your cart are no longer available in the requested quantity.');
        }
    }

    $subtotal = 0;
    foreach ($cartItems as $item) {
        $subtotal += (float) $item['price'] * (int) $item['quantity'];
    }

    $freeShippingEnabled = get_setting_bool($pdo, 'free_shipping_enabled', true);
    $freeShippingThreshold = get_setting_float($pdo, 'free_shipping_threshold', 0);
    $shippingFeeAmount = get_setting_float($pdo, 'shipping_fee', 0);
    $shipping_fee = ($freeShippingEnabled && $subtotal >= $freeShippingThreshold) ? 0.00 : $shippingFeeAmount;
    $total_amount = $subtotal + $shipping_fee;

    $orderStmt = $pdo->prepare("
        INSERT INTO orders (user_id, total_amount, order_status, created_at)
        VALUES (:user_id, :total_amount, 'Pending', NOW()) RETURNING order_id
    ");
    $orderStmt->execute(['user_id' => $userId, 'total_amount' => $total_amount]);
    $order_id = (int) $orderStmt->fetchColumn();

    $itemStmt = $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, size_id, quantity, unit_price)
        VALUES (:order_id, :product_id, :size_id, :quantity, :unit_price)
    ");
    $stockStmt = $pdo->prepare("UPDATE product_sizes SET stock_quantity = stock_quantity - :qty WHERE size_id = :size_id");
    foreach ($cartItems as $item) {
        $itemStmt->execute([
            'order_id' => $order_id, 'product_id' => $item['product_id'],
            'size_id' => $item['size_id'], 'quantity' => $item['quantity'], 'unit_price' => $item['price'],
        ]);
        if ($item['size_id'] !== null) {
            $stockStmt->execute(['qty' => $item['quantity'], 'size_id' => $item['size_id']]);
        }
    }

    $pdo->prepare("
        INSERT INTO deliveries (order_id, address_line, latitude, longitude, delivery_status)
        VALUES (:order_id, :address_line, :latitude, :longitude, 'Pending')
    ")->execute(['order_id' => $order_id, 'address_line' => $full_address, 'latitude' => $latitude, 'longitude' => $longitude]);

    $pdo->prepare("
        INSERT INTO payments (order_id, payment_method, payment_status, transaction_id, paid_at)
        VALUES (:order_id, :payment_method, 'Pending', NULL, NULL)
    ")->execute(['order_id' => $order_id, 'payment_method' => $payment_method]);

    $pdo->prepare("DELETE FROM cart_items WHERE user_id = :user_id")->execute(['user_id' => $userId]);

    $pdo->commit();

    log_audit($pdo, $userId, 'customer', 'create', 'order', $order_id, null, [
        'subtotal' => $subtotal, 'shipping_fee' => $shipping_fee, 'total_amount' => $total_amount,
        'payment_method' => $payment_method, 'item_count' => count($cartItems),
        'address_line' => $full_address, 'source' => 'mobile',
    ]);

    if ($payment_method === 'cod') {
        notify_user($pdo, $userId, 'order_placed', 'Order Placed',
            'Your order ' . format_order_number($order_id) . ' has been placed. Thank you for shopping with us!',
            'order', $order_id);

        json_out([
            'success' => true,
            'order_id' => $order_id,
            'order_number' => format_order_number($order_id),
            'requires_payment' => false,
        ]);
    }

    // payment_method === 'paymongo'
    $returnBase = APP_BASE_URL . '/api/payment-return.php';
    $itemCount = count($cartItems);

    $checkout = create_paymongo_checkout_session(
        $total_amount,
        format_order_number($order_id) . ' (' . $itemCount . ' item' . ($itemCount === 1 ? '' : 's') . ')',
        'ORD-' . $order_id,
        $returnBase . '?order_id=' . $order_id . '&result=success',
        $returnBase . '?order_id=' . $order_id . '&result=cancel'
    );

    if ($checkout && !empty($checkout['checkout_url'])) {
        // Stash the checkout session ID so payment-return.php can verify this
        // specific attempt with PayMongo later — never trust the redirect's
        // query string alone.
        $pdo->prepare("UPDATE payments SET transaction_id = ? WHERE order_id = ?")
            ->execute([$checkout['id'], $order_id]);

        notify_user($pdo, $userId, 'order_placed', 'Order Placed',
            'Your order ' . format_order_number($order_id) . ' has been placed. Complete payment via PayMongo to confirm it.',
            'order', $order_id);

        json_out([
            'success' => true,
            'order_id' => $order_id,
            'order_number' => format_order_number($order_id),
            'requires_payment' => true,
            'checkout_url' => $checkout['checkout_url'],
        ]);
    }

    // PayMongo didn't give us a checkout session — cancel the order and
    // release its stock, exactly as the web flow does.
    if (cancel_unpaid_order($pdo, $order_id)) {
        log_audit($pdo, $userId, 'customer', 'update', 'order', $order_id,
            ['order_status' => 'Pending'], ['order_status' => 'Cancelled']);
    }

    json_error('We could not start your PayMongo payment. Please try again, or choose Cash on Delivery.', 502);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_error('We could not place your order: ' . $e->getMessage(), 400);
}
