<?php
session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/paymongo.php';
require_once __DIR__ . '/helpers.php';

$order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;

if ($order_id <= 0 || !isset($_SESSION['user_id'])) {
    header("Location: ../pages/checkout.php");
    exit;
}

// Confirm this order actually belongs to the logged-in user before doing anything.
$orderStmt = $pdo->prepare("
    SELECT o.user_id, p.transaction_id AS checkout_session_id
    FROM orders o
    JOIN payments p ON p.order_id = o.order_id
    WHERE o.order_id = :order_id
");
$orderStmt->execute(['order_id' => $order_id]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order || (int) $order['user_id'] !== (int) $_SESSION['user_id']) {
    header("Location: ../pages/account.php?tab=orders");
    exit;
}

// Never trust the ?result= query string from the redirect alone — ask
// PayMongo directly what actually happened, using the secret key server-side,
// against the checkout session ID we stored when this checkout was created.
$result = $_GET['result'] ?? '';
$status = $order['checkout_session_id']
    ? paymongo_checkout_status($order['checkout_session_id'])
    : 'unknown';

if ($status === 'paid') {
    $pdo->prepare("
        UPDATE payments SET payment_status = 'Paid', paid_at = NOW()
        WHERE order_id = :order_id
    ")->execute(['order_id' => $order_id]);

    $pdo->prepare("
        UPDATE orders SET order_status = 'Processing'
        WHERE order_id = :order_id AND order_status = 'Pending'
    ")->execute(['order_id' => $order_id]);

    header("Location: ../pages/order-success.php?order_id=" . $order_id);
    exit;
}

// The customer says they finished paying, but PayMongo didn't confirm it
// (API hiccup, or the payment is still settling). Do NOT cancel — they may
// well have paid. Leave the order Pending with its stock reserved so it can
// be checked in Orders Management instead of being wrongly thrown away.
if ($result === 'success') {
    $_SESSION['error_msg'] = 'We received your return from PayMongo but could not confirm the payment yet. '
        . 'Your order is being held — if you were charged, it will be confirmed shortly.';
    header("Location: ../pages/account.php?tab=orders");
    exit;
}

// If PayMongo couldn't be reached at all, we don't actually know the outcome —
// hold the order instead of cancelling on a guess.
if ($status !== 'unpaid') {
    $_SESSION['error_msg'] = 'We could not confirm your payment status right now. Your order is being held.';
    header("Location: ../pages/account.php?tab=orders");
    exit;
}

// Either the customer cancelled/abandoned checkout, or PayMongo doesn't
// actually show a paid payment yet. Release the stock and cancel — but only
// if the order is still Pending. A refresh or Back press lands here again,
// and without this guard every visit would add the stock back once more.
if (!cancel_unpaid_order($pdo, $order_id)) {
    header("Location: ../pages/account.php?tab=orders");
    exit;
}

$_SESSION['error_msg'] = 'Your PayMongo payment was not completed. '
    . 'The items have been released back into stock — please re-add them to your cart and try again, or choose Cash on Delivery.';
header("Location: ../pages/shop.php");
exit;
