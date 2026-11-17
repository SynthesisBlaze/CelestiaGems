<?php
session_start();

require_once __DIR__ . '/permissions.php';
cg_require_page('products.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../admin/product_management.php");
    exit;
}

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/audit_logger.php';

$productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;

if ($productId <= 0) {
    header("Location: ../admin/product_management.php?error=" . urlencode('Invalid product.'));
    exit;
}

try {
    $pdo->beginTransaction();

    // Snapshot everything before it's gone — nothing left to query afterward.
    $productStmt = $pdo->prepare("SELECT name, category_id, description, price FROM products WHERE product_id = ?");
    $productStmt->execute([$productId]);
    $productRow = $productStmt->fetch(PDO::FETCH_ASSOC);

    if (!$productRow) {
        $pdo->rollBack();
        header("Location: ../admin/product_management.php?error=" . urlencode('Product not found.'));
        exit;
    }

    $sizesStmt = $pdo->prepare("SELECT size_label, stock_quantity FROM product_sizes WHERE product_id = ?");
    $sizesStmt->execute([$productId]);
    $sizes = $sizesStmt->fetchAll(PDO::FETCH_ASSOC);

    $imagesStmt = $pdo->prepare("SELECT image_url FROM product_images WHERE product_id = ?");
    $imagesStmt->execute([$productId]);
    $images = $imagesStmt->fetchAll(PDO::FETCH_COLUMN);

    // Delete child rows first (sizes/images), then the product itself.
    $pdo->prepare("DELETE FROM product_sizes WHERE product_id = ?")->execute([$productId]);
    $pdo->prepare("DELETE FROM product_images WHERE product_id = ?")->execute([$productId]);
    $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$productId]);

    $pdo->commit();

    // Remove the image files from disk — best effort, after the DB commit succeeds.
    foreach ($images as $imageUrl) {
        $diskPath = __DIR__ . '/../img/' . $imageUrl;
        if (is_file($diskPath)) {
            @unlink($diskPath);
        }
    }

    $oldValues = $productRow;
    if (!empty($sizes)) {
        $oldValues['sizes'] = $sizes;
    }
    if (!empty($images)) {
        $oldValues['images'] = $images;
    }

    log_audit(
        $pdo,
        (int)$_SESSION['user_id'],
        admin_actor_type((int)$_SESSION['role_id']),
        'delete',
        'product',
        $productId,
        $oldValues,
        null
    );

    header("Location: ../admin/product_management.php?deleted=1");
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Most likely a foreign key constraint (product has order_items or reviews on record)
    header("Location: ../admin/product_management.php?error=" . urlencode('This product could not be deleted — it may have related orders or reviews on record.'));
    exit;
}