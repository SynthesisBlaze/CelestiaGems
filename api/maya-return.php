<?php
/**
 * GET /api/maya-return.php?order_id=X&status=success|failure|cancel
 *
 * Where Maya redirects the WebView after a mobile checkout (see
 * create_maya_checkout_mobile() in checkout.php). Unlike the web's
 * functions/maya-callback.php, this does NOT check $_SESSION — a WebView
 * opened from the app never has the web app's session cookie, only the
 * app's own Bearer token (which isn't sent on a browser redirect either).
 *
 * Security still holds because the actual payment status is fetched
 * directly from Maya's API with the secret key (get_maya_payment_status),
 * never trusted from the ?status= query string. The ?order_id from the
 * URL only picks *which* order to check — it can't forge a "paid" result.
 *
 * The React Native screen that opened the WebView should watch for this
 * URL (it's the redirect target) in onNavigationStateChange, and once it
 * sees a navigation to a URL containing "/api/maya-return.php", close the
 * WebView and call GET /api/order-detail.php?order_id=... to show the
 * real, current status — not by reading this page's HTML.
 */
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/../functions/maya.php';

$order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;

function render_result_page(string $title, string $message, string $color): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><meta name='viewport' content='width=device-width, initial-scale=1'>
    <title>{$title}</title></head>
    <body style='font-family:sans-serif; text-align:center; padding:60px 20px; color:{$color};'>
    <h2>{$title}</h2><p style='color:#444;'>{$message}</p>
    <p style='color:#999; font-size:0.85rem;'>You can return to the Celestia Gems app now.</p>
    </body></html>";
    exit;
}

if ($order_id <= 0) {
    render_result_page('Something went wrong', 'No order was specified.', '#dc3545');
}

$orderStmt = $pdo->prepare("SELECT user_id FROM orders WHERE order_id = :order_id");
$orderStmt->execute(['order_id' => $order_id]);
if (!$orderStmt->fetch()) {
    render_result_page('Order not found', 'This order could not be located.', '#dc3545');
}

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

    render_result_page('Payment received', 'Your order has been confirmed. Thank you for shopping with Celestia Gems!', '#28a745');
}

// Not paid — release the reserved stock and cancel, same as the web callback
try {
    $pdo->beginTransaction();
    $itemsStmt = $pdo->prepare("SELECT size_id, quantity FROM order_items WHERE order_id = :order_id AND size_id IS NOT NULL");
    $itemsStmt->execute(['order_id' => $order_id]);
    $restoreStmt = $pdo->prepare("UPDATE product_sizes SET stock_quantity = stock_quantity + :qty WHERE size_id = :size_id");
    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $restoreStmt->execute(['qty' => $item['quantity'], 'size_id' => $item['size_id']]);
    }
    $pdo->prepare("UPDATE payments SET payment_status = 'Failed' WHERE order_id = :order_id")->execute(['order_id' => $order_id]);
    $pdo->prepare("UPDATE orders SET order_status = 'Cancelled' WHERE order_id = :order_id")->execute(['order_id' => $order_id]);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
}

render_result_page('Payment not completed', 'Your payment could not be confirmed (' . htmlspecialchars($mayaStatus ?? 'unknown') . '). The items were released back into stock — please try again from the app.', '#dc3545');
