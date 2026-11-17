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

$productId = isset($_POST['product_id']) && $_POST['product_id'] !== '' ? (int)$_POST['product_id'] : null;

function back_with_error(string $message, ?int $productId): void
{
    $target = $productId ? "product_form.php?id=$productId&error=" : "product_form.php?error=";
    header("Location: ../admin/$target" . urlencode($message));
    exit;
}

$name        = trim($_POST['name'] ?? '');
$categoryId  = (int)($_POST['category_id'] ?? 0);
$description = trim($_POST['description'] ?? '');
$price       = $_POST['price'] ?? '';

// --- Basic validation ---
if ($name === '' || $categoryId <= 0 || $price === '' || !is_numeric($price) || (float)$price < 0) {
    back_with_error('Please fill in all required fields with valid values.', $productId);
}

$categoryCheck = $pdo->prepare("SELECT category_id FROM categories WHERE category_id = ?");
$categoryCheck->execute([$categoryId]);
if (!$categoryCheck->fetch()) {
    back_with_error('Selected category does not exist.', $productId);
}

/**
 * Turns a product name into a filesystem-safe base filename — strips
 * characters that aren't valid in filenames, collapses whitespace, but
 * otherwise keeps it human-readable (matching the existing convention:
 * "Queen cut diamond ring.png", "Queen cut diamond ring 2.png", ...).
 */
function sanitize_filename_base(string $name): string
{
    $clean = preg_replace('/[\/\\\\:*?"<>|]/', '', $name);
    $clean = preg_replace('/\s+/', ' ', $clean);
    $clean = trim($clean);
    return $clean !== '' ? $clean : 'product';
}

/**
 * Moves one validated uploaded image into /img and returns the BARE
 * filename (no path) to store in image_url — matching the existing data
 * convention, since product.php / shop.php build the <img src> themselves
 * as "/img/" + image_url. Returns null if this upload slot should be
 * skipped (empty slot or failed validation).
 */
function handle_product_image_upload(array $fileError, array $fileTmpName, array $fileName, int $index, string $desiredBaseName, int &$sequence): ?string
{
    if ($fileError[$index] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($fileError[$index] !== UPLOAD_ERR_OK) {
        return null; // Skip silently rather than failing the whole save over one bad file
    }

    $tmpPath = $fileTmpName[$index];

    // Validate it's actually an image, not just an allowed extension
    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        return null;
    }

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    $mime = $imageInfo['mime'];
    if (!isset($allowedMimes[$mime])) {
        return null;
    }

    // 5MB cap per file
    if (filesize($tmpPath) > 5 * 1024 * 1024) {
        return null;
    }

    $uploadDir = __DIR__ . '/../img/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = $allowedMimes[$mime];

    // "Product Name.png" for the first image, "Product Name 2.png" for the
    // next, etc. — $sequence is passed by reference so it keeps counting up
    // across multiple files in the same upload batch.
    $sequence++;
    $filename = $sequence === 1 ? "{$desiredBaseName}.{$ext}" : "{$desiredBaseName} {$sequence}.{$ext}";
    while (file_exists($uploadDir . $filename)) {
        $sequence++;
        $filename = "{$desiredBaseName} {$sequence}.{$ext}";
    }

    $destination = $uploadDir . $filename;

    if (!move_uploaded_file($tmpPath, $destination)) {
        return null;
    }

    return $filename;
}

try {
    $pdo->beginTransaction();

    $newProductValues = [
        'name'        => $name,
        'category_id' => $categoryId,
        'description' => $description,
        'price'       => (float)$price,
    ];

    $oldProductRow = null;

    if ($productId === null) {
        // --- Create ---
        $stmt = $pdo->prepare("
            INSERT INTO products (category_id, name, description, price, created_at)
            VALUES (:category_id, :name, :description, :price, NOW())
            RETURNING product_id
        ");
        $stmt->execute([
            ':category_id' => $categoryId,
            ':name'        => $name,
            ':description' => $description,
            ':price'       => (float)$price,
        ]);
        $productId = (int)$stmt->fetchColumn();
    } else {
        // --- Update: capture the before-snapshot first, for the audit diff ---
        $oldStmt = $pdo->prepare("SELECT name, category_id, description, price FROM products WHERE product_id = ?");
        $oldStmt->execute([$productId]);
        $oldProductRow = $oldStmt->fetch(PDO::FETCH_ASSOC);

        if (!$oldProductRow) {
            $pdo->rollBack();
            back_with_error('Product not found.', null);
        }

        $stmt = $pdo->prepare("
            UPDATE products
            SET category_id = :category_id, name = :name, description = :description, price = :price
            WHERE product_id = :product_id
        ");
        $stmt->execute([
            ':category_id' => $categoryId,
            ':name'        => $name,
            ':description' => $description,
            ':price'       => (float)$price,
            ':product_id'  => $productId,
        ]);
    }

    // --- Sizes & Stock: Update existing, insert new, gracefully remove deleted ---
    $existingSizeStmt = $pdo->prepare("SELECT size_id FROM product_sizes WHERE product_id = ?");
    $existingSizeStmt->execute([$productId]);
    $existingSizeIds = $existingSizeStmt->fetchAll(PDO::FETCH_COLUMN);

    $submittedSizeIds = $_POST['size_id'] ?? [];
    $sizeLabels       = $_POST['size_label'] ?? [];
    $stockQuantities  = $_POST['stock_quantity'] ?? [];

    $updateSizeStmt = $pdo->prepare("UPDATE product_sizes SET size_label = ?, stock_quantity = ? WHERE size_id = ? AND product_id = ?");
    $insertSizeStmt = $pdo->prepare("INSERT INTO product_sizes (product_id, size_label, stock_quantity) VALUES (?, ?, ?)");

    $keptSizeIds = [];
    $newSizes = [];

    foreach ($sizeLabels as $index => $label) {
        $label = trim($label);
        $qty   = (int)($stockQuantities[$index] ?? 0);
        $sId   = (int)($submittedSizeIds[$index] ?? 0);

        if ($label !== '') {
            if ($sId > 0 && in_array($sId, $existingSizeIds)) {
                // Update existing size
                $updateSizeStmt->execute([$label, max(0, $qty), $sId, $productId]);
                $keptSizeIds[] = $sId;
                $newSizes[] = ['size_label' => $label, 'stock_quantity' => max(0, $qty)];
            } else {
                // Insert new size
                $insertSizeStmt->execute([$productId, $label, max(0, $qty)]);
                $newSizes[] = ['size_label' => $label, 'stock_quantity' => max(0, $qty)];
            }
        }
    }

    // Handle removed sizes
    $sizesToDelete = array_diff($existingSizeIds, $keptSizeIds);
    if (!empty($sizesToDelete)) {
        $delStmt = $pdo->prepare("DELETE FROM product_sizes WHERE size_id = ?");
        $zeroStockStmt = $pdo->prepare("UPDATE product_sizes SET stock_quantity = 0 WHERE size_id = ?");

        foreach ($sizesToDelete as $delId) {
            try {
                $delStmt->execute([$delId]);
            } catch (PDOException $e) {
                // Foreign key constraint failed (size is tied to an order). 
                // Fallback: Deplete stock so customers can no longer purchase it.
                $zeroStockStmt->execute([$delId]);
            }
        }
    }

    // --- Image deletions ---
    $deletedImageUrls = [];
    if (!empty($_POST['delete_image_ids']) && is_array($_POST['delete_image_ids'])) {
        $deleteIds = array_map('intval', $_POST['delete_image_ids']);
        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $fetchStmt = $pdo->prepare("SELECT image_id, image_url FROM product_images WHERE product_id = ? AND image_id IN ($placeholders)");
        $fetchStmt->execute(array_merge([$productId], $deleteIds));
        $toDelete = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

        $delStmt = $pdo->prepare("DELETE FROM product_images WHERE image_id = ?");
        foreach ($toDelete as $row) {
            $delStmt->execute([$row['image_id']]);
            $deletedImageUrls[] = $row['image_url'];

            // Remove the file from disk too — best effort, don't fail the save over it
            $diskPath = __DIR__ . '/../img/' . $row['image_url'];
            if (is_file($diskPath)) {
                @unlink($diskPath);
            }
        }
    }

    // --- Explicit primary selection (an existing image the admin picked) ---
    if (!empty($_POST['primary_image_id'])) {
        $chosenId = (int)$_POST['primary_image_id'];
        $pdo->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = ?")->execute([$productId]);
        $pdo->prepare("UPDATE product_images SET is_primary = 1 WHERE image_id = ? AND product_id = ?")->execute([$chosenId, $productId]);
    }

    // --- New uploads ---
    $newImageUrls = [];
    if (!empty($_FILES['images']) && is_array($_FILES['images']['tmp_name'])) {
        $count = count($_FILES['images']['tmp_name']);
        $insertStmt = $pdo->prepare("INSERT INTO product_images (product_id, image_url, is_primary) VALUES (?, ?, 0)");
        $baseName = sanitize_filename_base($name);
        $sequence = 0; // shared across this whole upload batch, so files come out "Name.png", "Name 2.png", "Name 3.png"...
        for ($i = 0; $i < $count; $i++) {
            $url = handle_product_image_upload($_FILES['images']['error'], $_FILES['images']['tmp_name'], $_FILES['images']['name'], $i, $baseName, $sequence);
            if ($url !== null) {
                $insertStmt->execute([$productId, $url]);
                $newImageUrls[] = $url;
            }
        }
    }

    // --- If nothing is marked primary yet but images exist, auto-promote the first one ---
    $hasPrimary = $pdo->prepare("SELECT COUNT(*) FROM product_images WHERE product_id = ? AND is_primary = 1");
    $hasPrimary->execute([$productId]);
    if ((int)$hasPrimary->fetchColumn() === 0) {
        $firstImage = $pdo->prepare("SELECT image_id FROM product_images WHERE product_id = ? ORDER BY image_id ASC LIMIT 1");
        $firstImage->execute([$productId]);
        $firstId = $firstImage->fetchColumn();
        if ($firstId) {
            $pdo->prepare("UPDATE product_images SET is_primary = 1 WHERE image_id = ?")->execute([$firstId]);
        }
    }

    $pdo->commit();

    // --- Audit log ---
    $actorId = (int)$_SESSION['user_id'];
    $actorType = admin_actor_type((int)$_SESSION['role_id']);

    $auditNewValues = $newProductValues;
    if (!empty($newSizes)) {
        $auditNewValues['sizes'] = $newSizes;
    }
    if (!empty($newImageUrls)) {
        $auditNewValues['images_added'] = $newImageUrls;
    }
    if (!empty($deletedImageUrls)) {
        $auditNewValues['images_removed'] = $deletedImageUrls;
    }

    if ($oldProductRow === null) {
        log_audit($pdo, $actorId, $actorType, 'create', 'product', $productId, null, $auditNewValues);
        header("Location: ../admin/product_management.php?saved=created");
    } else {
        log_audit($pdo, $actorId, $actorType, 'update', 'product', $productId, $oldProductRow, $auditNewValues);
        header("Location: ../admin/product_management.php?saved=updated");
    }
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    back_with_error('Something went wrong while saving the product. Please try again.', $productId);
}