<?php
session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/audit_logger.php';
require_once __DIR__ . '/notifier.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

require_once __DIR__ . '/permissions.php';
cg_require_json('returns.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$action = $_POST['action'] ?? '';
$order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
$actor_id = (int) $_SESSION['user_id'];
$actor_type = admin_actor_type((int) ($_SESSION['role_id'] ?? 0));

if ($order_id <= 0 || !in_array($action, ['approve_return', 'deny_return'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

try {
    $requestStmt = $pdo->prepare("
        SELECT request_id, status, user_id
        FROM return_requests
        WHERE order_id = :order_id AND status = 'Pending'
        ORDER BY requested_at DESC
        LIMIT 1
    ");
    $requestStmt->execute(['order_id' => $order_id]);
    $returnRequest = $requestStmt->fetch(PDO::FETCH_ASSOC);

    if (!$returnRequest) {
        echo json_encode(['success' => false, 'message' => 'No pending return request found for this order.']);
        exit;
    }

    $newStatus = $action === 'approve_return' ? 'Approved' : 'Denied';

    $pdo->prepare("UPDATE return_requests SET status = :status, resolved_at = NOW(), resolved_by = :resolved_by WHERE request_id = :request_id")
        ->execute([
            'status'      => $newStatus,
            'resolved_by' => $actor_id,
            'request_id'  => $returnRequest['request_id'],
        ]);

    log_audit(
        $pdo,
        $actor_id,
        $actor_type,
        $action,
        'return_request',
        (int) $returnRequest['request_id'],
        ['status' => 'Pending'],
        ['status' => $newStatus]
    );

    $customerMessage = '';

    if ($action === 'approve_return') {
        $oldOrderStatusStmt = $pdo->prepare("SELECT order_status FROM orders WHERE order_id = ?");
        $oldOrderStatusStmt->execute([$order_id]);
        $oldOrderStatus = $oldOrderStatusStmt->fetchColumn();

        $pdo->prepare("UPDATE orders SET order_status = 'Refunded' WHERE order_id = ?")->execute([$order_id]);
        log_audit($pdo, $actor_id, $actor_type, 'update', 'order', $order_id, ['order_status' => $oldOrderStatus], ['order_status' => 'Refunded']);

        $oldPaymentStatusStmt = $pdo->prepare("SELECT payment_status FROM payments WHERE order_id = ?");
        $oldPaymentStatusStmt->execute([$order_id]);
        $oldPaymentStatus = $oldPaymentStatusStmt->fetchColumn();

        if ($oldPaymentStatus === 'Paid') {
            $pdo->prepare("UPDATE payments SET payment_status = 'Refunded' WHERE order_id = ?")->execute([$order_id]);
            log_audit($pdo, $actor_id, $actor_type, 'update', 'payment', $order_id, ['payment_status' => 'Paid'], ['payment_status' => 'Refunded']);
        }

        $customerMessage = 'Your return request for order ' . format_order_number($order_id) . ' has been approved. Your refund is being processed.';
    } else {
        $customerMessage = 'Your return request for order ' . format_order_number($order_id) . ' was declined. Contact support if you have questions.';
    }

    notify_user($pdo, (int) $returnRequest['user_id'], 'return_status', 'Return Update', $customerMessage, 'order', $order_id);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('return-action.php failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Something went wrong updating this return request.']);
}
