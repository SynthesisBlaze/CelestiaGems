<?php
session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/maya.php';
require_once __DIR__ . '/helpers.php';

$order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;

if ($order_id <= 0 || !isset($_SESSION['user_id'])) {
    header("Location: ../pages/checkout.php");
    exit;
}

// Confirm this order actually belongs to the logged-in user before doing anything.
$orderStmt = $pdo->prepare("SELECT user_id FROM orders WHERE order_id = :order_id");
$orderStmt->execute(['order_id' => $order_id]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order || (int) $order['user_id'] !== (int) $_SESSION['user_id']) {
    header("Location: ../pages/account.php?tab=orders");
    exit;
}

// Never trust the ?status= query string from the redirect alone — ask Maya
// directly what actually happened, using the secret key server-side.
$mayaStatus = get_maya_payment_status((string) $order_id);

if (in_array($mayaStatus, ['PAYMENT_SUCCESS', 'CAPTURED', 'AUTHORIZED'], true)) {
    $pdo->prepare("
        UPDATE payments SET payment_status = 'Paid', paid_at = NOW(), transaction_id = :ref
        WHERE order_id = :order_id
    ")->execute(['ref' => (string) $order_id, 'order_id' => $order_id]);

    $pdo->prepare("
        UPDATE orders SET order_status = 'Processing'
        WHERE order_id = :order_id AND order_status = 'Pending'
    ")->execute(['order_id' => $order_id]);

    header("Location: ../pages/order-success.php?order_id=" . $order_id);
    exit;
}

// If Maya couldn't be reached, we don't know the outcome — hold the order
// rather than cancelling one the customer may have paid for.
if ($mayaStatus === null) {
    $_SESSION['error_msg'] = 'We could not confirm your Maya payment right now. Your order is being held.';
    header("Location: ../pages/account.php?tab=orders");
    exit;
}

// A definite failure (PAYMENT_FAILED, PAYMENT_EXPIRED, PAYMENT_CANCELLED…):
// release the stock and cancel — only once, and only while still Pending.
if (!cancel_unpaid_order($pdo, $order_id)) {
    header("Location: ../pages/account.php?tab=orders");
    exit;
}

$_SESSION['error_msg'] = 'Your Maya payment was not completed (' . htmlspecialchars($mayaStatus ?? 'unknown') . '). '
    . 'The items have been released back into stock — please re-add them to your cart and try again, or choose Cash on Delivery.';
header("Location: ../pages/shop.php");
exit;
