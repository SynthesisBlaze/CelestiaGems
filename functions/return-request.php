<?php
session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/audit_logger.php';
require_once __DIR__ . '/notifier.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
$reason = trim($_POST['reason'] ?? '');

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order.']);
    exit;
}
if ($reason === '') {
    echo json_encode(['success' => false, 'message' => 'Please tell us why you\'d like to return this order.']);
    exit;
}
if (mb_strlen($reason) > 1000) {
    echo json_encode(['success' => false, 'message' => 'That reason is a bit long — please keep it under 1000 characters.']);
    exit;
}

try {
    // Confirm this order actually belongs to the requester and is eligible
    $orderStmt = $pdo->prepare("SELECT order_id, order_status FROM orders WHERE order_id = ? AND user_id = ?");
    $orderStmt->execute([$order_id, $user_id]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }
    if ($order['order_status'] !== 'Delivered') {
        echo json_encode(['success' => false, 'message' => 'Returns can only be requested for delivered orders.']);
        exit;
    }

    // 30-day return window (as promised on the homepage and in the Terms).
    // The delivery date comes from the audit log entry that marked the order
    // Delivered; older orders without one fall back to the order date.
    $deliveredStmt = $pdo->prepare("
        SELECT MAX(created_at) FROM audit_logs
        WHERE entity_type = 'order' AND entity_id = ?
          AND new_values::text LIKE '%\"order_status\"%\"Delivered\"%'
    ");
    $deliveredStmt->execute([$order_id]);
    $deliveredAt = $deliveredStmt->fetchColumn();
    if (!$deliveredAt) {
        $createdStmt = $pdo->prepare("SELECT created_at FROM orders WHERE order_id = ?");
        $createdStmt->execute([$order_id]);
        $deliveredAt = $createdStmt->fetchColumn();
    }
    if ($deliveredAt && strtotime($deliveredAt) < strtotime('-30 days')) {
        echo json_encode(['success' => false, 'message' => 'Returns can be requested within 30 days of delivery. This order was delivered on ' . date('M j, Y', strtotime($deliveredAt)) . '.']);
        exit;
    }

    // Don't allow stacking a new request on top of one already in play
    $existingStmt = $pdo->prepare("SELECT status FROM return_requests WHERE order_id = ? AND status IN ('Pending', 'Approved') ORDER BY requested_at DESC LIMIT 1");
    $existingStmt->execute([$order_id]);
    $existing = $existingStmt->fetchColumn();
    if ($existing) {
        echo json_encode(['success' => false, 'message' => 'A return request for this order already exists (' . $existing . ').']);
        exit;
    }

    $insertStmt = $pdo->prepare("INSERT INTO return_requests (order_id, user_id, reason, status, requested_at) VALUES (?, ?, ?, 'Pending', NOW()) RETURNING request_id");
    $insertStmt->execute([$order_id, $user_id, $reason]);
    $requestId = (int) $insertStmt->fetchColumn();

    log_audit(
        $pdo,
        $user_id,
        'customer',
        'create',
        'return_request',
        $requestId,
        null,
        ['order_id' => $order_id, 'reason' => $reason]
    );

    notify_user(
        $pdo,
        $user_id,
        'return_requested',
        'Return Request Received',
        'We\'ve received your return request for order ' . format_order_number($order_id) . ' and will review it shortly.',
        'order',
        $order_id
    );

    echo json_encode(['success' => true, 'message' => 'Your return request has been submitted.', 'status' => 'Pending']);
} catch (PDOException $e) {
    error_log('return-request.php failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
}