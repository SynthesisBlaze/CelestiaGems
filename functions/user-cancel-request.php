<?php
session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/audit_logger.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$order_id = (int)($_POST['order_id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT order_status FROM orders WHERE order_id = :order_id AND user_id = :user_id");
    $stmt->execute(['order_id' => $order_id, 'user_id' => $user_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }

    if (!in_array($order['order_status'], ['Pending', 'Processing'])) {
        echo json_encode(['success' => false, 'message' => 'This order cannot be cancelled at this stage.']);
        exit;
    }

    $updateStmt = $pdo->prepare("UPDATE orders SET order_status = 'Cancellation Requested' WHERE order_id = :order_id");
    $updateStmt->execute(['order_id' => $order_id]);

    log_audit(
        $pdo, 
        $user_id, 
        'customer', 
        'request_cancel', 
        'order', 
        $order_id, 
        ['order_status' => $order['order_status']], 
        ['order_status' => 'Cancellation Requested']
    );

    echo json_encode(['success' => true, 'message' => 'Cancellation request submitted.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'An error occurred.']);
}