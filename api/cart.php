<?php
/**
 * /api/cart.php   (Authorization: Bearer <token> required for all verbs)
 *
 * GET     -> current cart contents
 * POST    -> add an item  { product_id, quantity?, size_id? }
 * PUT     -> change quantity  { cart_item_id, quantity }
 * DELETE  -> remove an item   { cart_item_id }
 *
 * Unlike functions/cart.php (which mixes a $_SESSION['cart'] guest cart with
 * the cart_items table), the app always has a logged-in token, so this
 * talks to cart_items directly — the same table checkout-process.php reads
 * from, and the same table the web cart page reads from once you're logged
 * in there too.
 */
require_once __DIR__ . '/_bootstrap.php';

$user = require_auth($pdo);
$userId = (int) $user['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

function fetch_cart(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT ci.cart_item_id, ci.product_id, ci.size_id, ci.quantity,
               p.name, p.price, ps.size_label, ps.stock_quantity,
               img.image_url
        FROM cart_items ci
        JOIN products p ON ci.product_id = p.product_id
        LEFT JOIN product_sizes ps ON ci.size_id = ps.size_id
        LEFT JOIN product_images img ON p.product_id = img.product_id AND img.is_primary = 1
        WHERE ci.user_id = ?
        ORDER BY ci.cart_item_id DESC
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = array_map(function ($r) {
        return [
            'cart_item_id'   => (int) $r['cart_item_id'],
            'product_id'     => (int) $r['product_id'],
            'name'           => $r['name'],
            'price'          => (float) $r['price'],
            'image'          => product_image_url($r['image_url']),
            'size_id'        => $r['size_id'] !== null ? (int) $r['size_id'] : null,
            'size_label'     => $r['size_label'] ?? 'Standard',
            'quantity'       => (int) $r['quantity'],
            'stock_quantity' => $r['stock_quantity'] !== null ? (int) $r['stock_quantity'] : null,
            'line_total'     => (float) $r['price'] * (int) $r['quantity'],
        ];
    }, $rows);

    $subtotal = array_sum(array_column($items, 'line_total'));

    return ['items' => $items, 'subtotal' => $subtotal, 'item_count' => count($items)];
}

if ($method === 'GET') {
    json_out(array_merge(['success' => true], fetch_cart($pdo, $userId)));
}

if ($method === 'POST') {
    $in = read_input();
    $productId = (int) ($in['product_id'] ?? 0);
    $quantity  = max(1, (int) ($in['quantity'] ?? 1));
    $sizeId    = (int) ($in['size_id'] ?? 0);

    if ($productId <= 0) {
        json_error('A valid product_id is required.');
    }

    $prodCheck = $pdo->prepare("SELECT product_id FROM products WHERE product_id = ?");
    $prodCheck->execute([$productId]);
    if (!$prodCheck->fetch()) {
        json_error('Product not found.', 404);
    }

    if ($sizeId > 0) {
        $sizeCheck = $pdo->prepare("SELECT stock_quantity FROM product_sizes WHERE size_id = ? AND product_id = ?");
        $sizeCheck->execute([$sizeId, $productId]);
        $sizeRow = $sizeCheck->fetch(PDO::FETCH_ASSOC);
        if (!$sizeRow) {
            json_error('Selected size does not exist for this product.');
        }
    }

    $existingStmt = $pdo->prepare("
        SELECT cart_item_id, quantity FROM cart_items
        WHERE user_id = ? AND product_id = ? AND COALESCE(size_id, 0) = COALESCE(?, 0)
    ");
    $existingStmt->execute([$userId, $productId, $sizeId > 0 ? $sizeId : null]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $pdo->prepare("UPDATE cart_items SET quantity = quantity + ? WHERE cart_item_id = ?")
            ->execute([$quantity, $existing['cart_item_id']]);
    } else {
        $pdo->prepare("INSERT INTO cart_items (user_id, product_id, size_id, quantity) VALUES (?, ?, ?, ?)")
            ->execute([$userId, $productId, $sizeId > 0 ? $sizeId : null, $quantity]);
    }

    json_out(array_merge(['success' => true, 'message' => 'Item added to bag.'], fetch_cart($pdo, $userId)));
}

if ($method === 'PUT') {
    $in = read_input();
    $cartItemId = (int) ($in['cart_item_id'] ?? 0);
    $quantity   = (int) ($in['quantity'] ?? 0);

    if ($cartItemId <= 0 || $quantity <= 0) {
        json_error('cart_item_id and a positive quantity are required.');
    }

    $stmt = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE cart_item_id = ? AND user_id = ?");
    $stmt->execute([$quantity, $cartItemId, $userId]);

    if ($stmt->rowCount() === 0) {
        json_error('Cart item not found.', 404);
    }

    json_out(array_merge(['success' => true, 'message' => 'Cart updated.'], fetch_cart($pdo, $userId)));
}

if ($method === 'DELETE') {
    $in = read_input();
    $cartItemId = (int) ($in['cart_item_id'] ?? 0);

    if ($cartItemId <= 0) {
        json_error('cart_item_id is required.');
    }

    $pdo->prepare("DELETE FROM cart_items WHERE cart_item_id = ? AND user_id = ?")
        ->execute([$cartItemId, $userId]);

    json_out(array_merge(['success' => true, 'message' => 'Item removed.'], fetch_cart($pdo, $userId)));
}

json_error('Method not allowed.', 405);
