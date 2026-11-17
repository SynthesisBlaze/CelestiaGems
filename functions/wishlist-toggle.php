<?php
session_start();
require_once __DIR__ . '/db_connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$productId = isset($data['product_id']) ? (int)$data['product_id'] : 0;
$userId = $_SESSION['user_id'];

if ($productId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product']);
    exit;
}

try {
    // Check if it's already in the wishlist
    $checkStmt = $pdo->prepare("SELECT wishlist_id FROM wishlist_items WHERE user_id = ? AND product_id = ?");
    $checkStmt->execute([$userId, $productId]);
    
    if ($checkStmt->fetch()) {
        // Exists, so remove it
        $delStmt = $pdo->prepare("DELETE FROM wishlist_items WHERE user_id = ? AND product_id = ?");
        $delStmt->execute([$userId, $productId]);
        echo json_encode(['success' => true, 'status' => 'removed']);
    } else {
        // Does not exist, add it
        $insStmt = $pdo->prepare("INSERT INTO wishlist_items (user_id, product_id) VALUES (?, ?)");
        $insStmt->execute([$userId, $productId]);
        echo json_encode(['success' => true, 'status' => 'added']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}