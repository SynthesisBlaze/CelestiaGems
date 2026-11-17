<?php
session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/paymongo.php';
require_once __DIR__ . '/settings_helper.php';
require_once __DIR__ . '/audit_logger.php';
require_once __DIR__ . '/notifier.php';
require_once __DIR__ . '/helpers.php';

// Must be logged in and must have come from the checkout form
if (!isset($_SESSION['user_id'])) {
    header("Location: ../pages/login.php");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../pages/checkout.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

$address_line = trim($_POST['address_line'] ?? '');
$city         = trim($_POST['city'] ?? '');
$postal_code  = trim($_POST['postal_code'] ?? '');
$latitude     = isset($_POST['latitude']) ? (float) $_POST['latitude'] : null;
$longitude    = isset($_POST['longitude']) ? (float) $_POST['longitude'] : null;

$codEnabled = get_setting_bool($pdo, 'payment_cod_enabled', true);
$paymongoEnabled = get_setting_bool($pdo, 'payment_paymongo_enabled', true);

$payment_method = $_POST['payment_method'] ?? '';
$methodIsValid = ($payment_method === 'cod' && $codEnabled) || ($payment_method === 'paymongo' && $paymongoEnabled);

if (!$methodIsValid) {
    // Covers: nothing selected, an unrecognized value, or a method that's
    // since been disabled in Settings after the checkout page was loaded —
    // never silently fall back to a method the admin turned off.
    $_SESSION['error_msg'] = 'That payment method is not available right now. Please choose another.';
    header("Location: ../pages/checkout.php");
    exit;
}

if ($address_line === '' || $city === '' || $postal_code === '') {
    $_SESSION['error_msg'] = 'Please provide a complete delivery address.';
    header("Location: ../pages/checkout.php");
    exit;
}

// Combine street + city + postal into the single address_line the deliveries table expects
$full_address = $address_line . ', ' . $city . ' ' . $postal_code;

try {
    $pdo->beginTransaction();

    // Re-fetch the cart server-side rather than trusting anything from the client.
    // Postgres can't lock the nullable side of a LEFT JOIN, so we lock the cart
    // rows here and lock/read the stock rows in a separate query just below.
    $cartStmt = $pdo->prepare("
        SELECT ci.cart_item_id, ci.product_id, ci.size_id, ci.quantity,
               p.price
        FROM cart_items ci
        JOIN products p ON ci.product_id = p.product_id
        WHERE ci.user_id = :user_id
        FOR UPDATE OF ci
    ");
    $cartStmt->execute(['user_id' => $user_id]);
    $cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cartItems)) {
        throw new Exception('Your cart is empty.');
    }

    // Lock the stock rows for these sizes (ordered by size_id so concurrent
    // checkouts always lock in the same order and can't deadlock), then
    // attach the fresh, locked stock figure to each cart item.
    $sizeIds = array_values(array_unique(array_filter(array_column($cartItems, 'size_id'), fn($v) => $v !== null)));
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
        $item['stock_quantity'] = $item['size_id'] !== null ? ($stockBySize[(int) $item['size_id']] ?? 0) : null;
    }
    unset($item);

    // Make sure everything is still in stock before we commit to an order
    foreach ($cartItems as $item) {
        if ($item['size_id'] !== null && (int)$item['stock_quantity'] < (int)$item['quantity']) {
            throw new Exception('One or more items in your cart are no longer available in the requested quantity.');
        }
    }

    $subtotal = 0;
    foreach ($cartItems as $item) {
        $subtotal += (float)$item['price'] * (int)$item['quantity'];
    }

    // Same shipping logic as checkout.php, computed here too (never trust
    // a client-supplied total) — this used to be left out entirely, so the
    // saved order total didn't match what the customer was shown once
    // shipping became a real, nonzero fee.
    $freeShippingEnabled = get_setting_bool($pdo, 'free_shipping_enabled', true);
    $freeShippingThreshold = get_setting_float($pdo, 'free_shipping_threshold', 0);
    $shippingFeeAmount = get_setting_float($pdo, 'shipping_fee', 0);
    $shipping_fee = ($freeShippingEnabled && $subtotal >= $freeShippingThreshold) ? 0.00 : $shippingFeeAmount;

    $total_amount = $subtotal + $shipping_fee;

    // 1) Order
    $orderStmt = $pdo->prepare("
        INSERT INTO orders (user_id, total_amount, order_status, created_at)
        VALUES (:user_id, :total_amount, 'Pending', NOW())
        RETURNING order_id
    ");
    $orderStmt->execute([
        'user_id' => $user_id,
        'total_amount' => $total_amount,
    ]);
    $order_id = (int) $orderStmt->fetchColumn();

    // 2) Order items + stock decrement
    $itemStmt = $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, size_id, quantity, unit_price)
        VALUES (:order_id, :product_id, :size_id, :quantity, :unit_price)
    ");
    $stockStmt = $pdo->prepare("
        UPDATE product_sizes SET stock_quantity = stock_quantity - :qty
        WHERE size_id = :size_id
    ");
    foreach ($cartItems as $item) {
        $itemStmt->execute([
            'order_id'   => $order_id,
            'product_id' => $item['product_id'],
            'size_id'    => $item['size_id'],
            'quantity'   => $item['quantity'],
            'unit_price' => $item['price'],
        ]);
        if ($item['size_id'] !== null) {
            $stockStmt->execute([
                'qty'     => $item['quantity'],
                'size_id' => $item['size_id'],
            ]);
        }
    }

    // 3) Delivery record
    $deliveryStmt = $pdo->prepare("
        INSERT INTO deliveries (order_id, address_line, latitude, longitude, delivery_status)
        VALUES (:order_id, :address_line, :latitude, :longitude, 'Pending')
    ");
    $deliveryStmt->execute([
        'order_id'     => $order_id,
        'address_line' => $full_address,
        'latitude'     => $latitude,
        'longitude'    => $longitude,
    ]);

    // 4) Payment record (Cash on Delivery — nothing collected yet)
    $paymentStmt = $pdo->prepare("
        INSERT INTO payments (order_id, payment_method, payment_status, transaction_id, paid_at)
        VALUES (:order_id, :payment_method, 'Pending', NULL, NULL)
    ");
    $paymentStmt->execute([
        'order_id'       => $order_id,
        'payment_method' => $payment_method,
    ]);

    // 5) Clear the cart now that the order is placed
    $clearStmt = $pdo->prepare("DELETE FROM cart_items WHERE user_id = :user_id");
    $clearStmt->execute(['user_id' => $user_id]);

    $pdo->commit();

    // Audit log: the order row now genuinely exists, regardless of what
    // happens with PayMongo below — this is a factual record of DB history,
    // not a claim that the order is "confirmed" from the customer's side.
    log_audit(
        $pdo,
        $user_id,
        'customer',
        'create',
        'order',
        $order_id,
        null,
        [
            'subtotal'       => $subtotal,
            'shipping_fee'   => $shipping_fee,
            'total_amount'   => $total_amount,
            'payment_method' => $payment_method,
            'item_count'     => count($cartItems),
            'address_line'   => $full_address,
        ]
    );

    if ($payment_method === 'cod') {
        // COD is final right here — nothing further can invalidate it.
        notify_user(
            $pdo,
            $user_id,
            'order_placed',
            'Order Placed',
            'Your order ' . format_order_number($order_id) . ' has been placed. Thank you for shopping with us!',
            'order',
            $order_id
        );
    }

    if ($payment_method === 'paymongo') {
        $checkout = create_paymongo_checkout_session(
            $total_amount,
            format_order_number($order_id) . ' (' . count($cartItems) . ' item' . (count($cartItems) === 1 ? '' : 's') . ')',
            'ORD-' . $order_id,
            cg_base_url() . '/functions/order-payment-return.php?order_id=' . $order_id . '&result=success',
            cg_base_url() . '/functions/order-payment-return.php?order_id=' . $order_id . '&result=cancel'
        );

        if ($checkout && !empty($checkout['checkout_url'])) {
            // Stash the checkout session ID so order-payment-return.php can
            // verify this specific attempt with PayMongo later — never trust
            // the redirect query string alone.
            $pdo->prepare("UPDATE payments SET transaction_id = ? WHERE order_id = ?")
                ->execute([$checkout['id'], $order_id]);

            // Only now — with a real checkout session about to open — is
            // this a standing order worth telling the customer about.
            notify_user(
                $pdo,
                $user_id,
                'order_placed',
                'Order Placed',
                'Your order ' . format_order_number($order_id) . ' has been placed. Complete payment via PayMongo to confirm it.',
                'order',
                $order_id
            );

            header("Location: " . $checkout['checkout_url']);
            exit;
        }

        // PayMongo didn't give us a checkout session — cancel the order and release its stock.
        if (cancel_unpaid_order($pdo, $order_id)) {
            // No customer notification on purpose — they're already looking at
            // the error message below. This keeps the cancellation in the audit trail.
            log_audit(
                $pdo,
                $user_id,
                'customer',
                'update',
                'order',
                $order_id,
                ['order_status' => 'Pending'],
                ['order_status' => 'Cancelled']
            );
        }

        $_SESSION['error_msg'] = 'We could not start your PayMongo payment. Please try again, or choose Cash on Delivery.';
        header("Location: ../pages/checkout.php");
        exit;
    }

    header("Location: ../pages/order-success.php?order_id=" . $order_id);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Our own validation messages (empty cart, out of stock) are safe to show;
    // database errors are logged, not shown to customers.
    if ($e instanceof PDOException) {
        error_log('checkout-process error: ' . $e->getMessage());
        $_SESSION['error_msg'] = 'We could not place your order because of a server problem. Please try again.';
    } else {
        $_SESSION['error_msg'] = 'We could not place your order: ' . $e->getMessage();
    }
    header("Location: ../pages/checkout.php");
    exit;
}