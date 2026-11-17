<?php
session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/audit_logger.php';
require_once __DIR__ . '/notifier.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

require_once __DIR__ . '/permissions.php';
cg_require_json('orders.view');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$ORDER_STATUSES = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled', 'Cancellation Requested'];

$action   = $_POST['action'] ?? '';
$order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
$actor_id = (int) $_SESSION['user_id'];

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order.']);
    exit;
}

try {
    if ($action === 'update_status') {
        $status = $_POST['status'] ?? '';
        if (!in_array($status, $ORDER_STATUSES, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status.']);
            exit;
        }
        // Moving an order along (processing/shipped/delivered) is staff work;
        // cancelling one is an Admin decision.
        cg_require_json($status === 'Cancelled' ? 'orders.cancel' : 'orders.update_status');

        $oldStatusStmt = $pdo->prepare("SELECT order_status, user_id FROM orders WHERE order_id = :order_id");
        $oldStatusStmt->execute(['order_id' => $order_id]);
        $orderRow = $oldStatusStmt->fetch(PDO::FETCH_ASSOC);

        if (!$orderRow) {
            echo json_encode(['success' => false, 'message' => 'Order not found.']);
            exit;
        }
        $oldStatus = $orderRow['order_status'];

        // Cancelled and refunded orders are final: their stock has already been
        // released, so moving them back into progress would sell stock we no longer hold.
        if (in_array($oldStatus, ['Cancelled', 'Refunded'], true)) {
            echo json_encode(['success' => false, 'message' => 'This order is ' . strtolower($oldStatus) . ' and can no longer be changed.']);
            exit;
        }
        if ($status === $oldStatus) {
            echo json_encode(['success' => true]);
            exit;
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE orders SET order_status = :status WHERE order_id = :order_id");
        $stmt->execute(['status' => $status, 'order_id' => $order_id]);

        // Cancelling puts the reserved items back on the shelf.
        if ($status === 'Cancelled') {
            restore_order_stock($pdo, $order_id);
        }

        // If the order is marked delivered, reflect that on the delivery record too
        if ($status === 'Delivered') {
            $deliveryStmt = $pdo->prepare("UPDATE deliveries SET delivery_status = 'Delivered' WHERE order_id = :order_id");
            $deliveryStmt->execute(['order_id' => $order_id]);
        } elseif ($status === 'Shipped') {
            $deliveryStmt = $pdo->prepare("UPDATE deliveries SET delivery_status = 'Out for Delivery' WHERE order_id = :order_id AND delivery_status = 'Pending'");
            $deliveryStmt->execute(['order_id' => $order_id]);
        } elseif ($status === 'Cancelled') {
            $deliveryStmt = $pdo->prepare("UPDATE deliveries SET delivery_status = 'Cancelled' WHERE order_id = :order_id");
            $deliveryStmt->execute(['order_id' => $order_id]);

            // Check old payment status before updating
            $oldPaymentStmt = $pdo->prepare("SELECT payment_status FROM payments WHERE order_id = :order_id");
            $oldPaymentStmt->execute(['order_id' => $order_id]);
            $oldPaymentStatus = $oldPaymentStmt->fetchColumn();

            if ($oldPaymentStatus === 'Pending') {
                $paymentStmt = $pdo->prepare("UPDATE payments SET payment_status = 'Cancelled' WHERE order_id = :order_id");
                $paymentStmt->execute(['order_id' => $order_id]);

                // Log the payment cancellation
                log_audit(
                    $pdo,
                    $actor_id,
                    admin_actor_type((int)($_SESSION['role_id'] ?? 0)),
                    'update',
                    'payment',
                    $order_id,
                    ['payment_status' => $oldPaymentStatus],
                    ['payment_status' => 'Cancelled']
                );
            }
        }

        $pdo->commit();

        log_audit(
            $pdo,
            $actor_id,
            admin_actor_type((int)($_SESSION['role_id'] ?? 0)),
            'update',
            'order',
            $order_id,
            ['order_status' => $oldStatus],
            ['order_status' => $status]
        );

        // Guest orders (no user_id) have no account page to show a notification on
        if (!empty($orderRow['user_id'])) {
            notify_user(
                $pdo,
                (int)$orderRow['user_id'],
                'order_status',
                'Order Update',
                'Your order ' . format_order_number($order_id) . ' is now ' . $status . '.',
                'order',
                $order_id
            );
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'mark_paid') {
        cg_require_json('orders.mark_paid');
        $orderUserStmt = $pdo->prepare("SELECT user_id FROM orders WHERE order_id = :order_id");
        $orderUserStmt->execute(['order_id' => $order_id]);
        $orderUserId = $orderUserStmt->fetchColumn();

        $oldPaymentStmt = $pdo->prepare("SELECT payment_status FROM payments WHERE order_id = :order_id");
        $oldPaymentStmt->execute(['order_id' => $order_id]);
        $oldPaymentStatus = $oldPaymentStmt->fetchColumn();

        $stmt = $pdo->prepare("UPDATE payments SET payment_status = 'Paid', paid_at = NOW() WHERE order_id = :order_id");
        $stmt->execute(['order_id' => $order_id]);

        log_audit(
            $pdo,
            $actor_id,
            admin_actor_type((int)($_SESSION['role_id'] ?? 0)),
            'update',
            'order',
            $order_id,
            ['payment_status' => $oldPaymentStatus !== false ? $oldPaymentStatus : null],
            ['payment_status' => 'Paid']
        );

        if (!empty($orderUserId)) {
            notify_user(
                $pdo,
                (int)$orderUserId,
                'payment_confirmed',
                'Payment Confirmed',
                'We\'ve confirmed payment for your order ' . format_order_number($order_id) . '.',
                'order',
                $order_id
            );
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'approve_cancellation' || $action === 'refuse_cancellation') {
        cg_require_json('orders.cancel');
        $newStatus = ($action === 'approve_cancellation') ? 'Cancelled' : 'Processing';

        $oldStatusStmt = $pdo->prepare("SELECT order_status, user_id FROM orders WHERE order_id = :order_id");
        $oldStatusStmt->execute(['order_id' => $order_id]);
        $orderRow = $oldStatusStmt->fetch(PDO::FETCH_ASSOC);

        if (!$orderRow) {
            echo json_encode(['success' => false, 'message' => 'Order not found.']);
            exit;
        }
        if ($orderRow['order_status'] !== 'Cancellation Requested') {
            echo json_encode(['success' => false, 'message' => 'This order no longer has a pending cancellation request.']);
            exit;
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE orders SET order_status = :status WHERE order_id = :order_id");
        $stmt->execute(['status' => $newStatus, 'order_id' => $order_id]);

        if ($newStatus === 'Cancelled') {
            restore_order_stock($pdo, $order_id);

            $deliveryStmt = $pdo->prepare("UPDATE deliveries SET delivery_status = 'Cancelled' WHERE order_id = :order_id");
            $deliveryStmt->execute(['order_id' => $order_id]);

            // Check old payment status before updating
            $oldPaymentStmt = $pdo->prepare("SELECT payment_status FROM payments WHERE order_id = :order_id");
            $oldPaymentStmt->execute(['order_id' => $order_id]);
            $oldPaymentStatus = $oldPaymentStmt->fetchColumn();

            if ($oldPaymentStatus === 'Pending') {
                $paymentStmt = $pdo->prepare("UPDATE payments SET payment_status = 'Cancelled' WHERE order_id = :order_id");
                $paymentStmt->execute(['order_id' => $order_id]);

                // Log the payment cancellation
                log_audit(
                    $pdo,
                    $actor_id,
                    admin_actor_type((int)($_SESSION['role_id'] ?? 0)),
                    'update',
                    'payment',
                    $order_id,
                    ['payment_status' => $oldPaymentStatus],
                    ['payment_status' => 'Cancelled']
                );
            }
        }

        $pdo->commit();

        // Log the action to audit_logs
        log_audit(
            $pdo,
            $actor_id,
            admin_actor_type((int)($_SESSION['role_id'] ?? 0)),
            $action,
            'order',
            $order_id,
            ['order_status' => $orderRow['order_status']],
            ['order_status' => $newStatus]
        );

        // Notify the customer
        if (!empty($orderRow['user_id'])) {
            $msg = ($action === 'approve_cancellation')
                ? "Your cancellation request for order " . format_order_number($order_id) . " has been approved."
                : "Your cancellation request for order " . format_order_number($order_id) . " was declined. It is currently being processed.";
            notify_user($pdo, (int)$orderRow['user_id'], 'order_status', 'Cancellation Update', $msg, 'order', $order_id);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('order-action error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Something went wrong updating this order.']);
}
