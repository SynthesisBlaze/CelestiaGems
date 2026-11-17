<?php
/**
 * GET /api/recommended.php?limit=6      (Authorization optional)
 *
 * Pieces to suggest on the app's home screen.
 *
 * Signed in, it recommends from the categories the customer has actually
 * shown interest in — things they wishlisted or bought — and leaves out
 * anything already in their wishlist, bag or order history, because
 * recommending what someone already owns is noise dressed as personalisation.
 *
 * Signed out, or signed in with no history yet, it falls back to the
 * best-rated pieces, then the newest. That fallback is the common case for
 * a new customer, so it has to look deliberate rather than empty.
 *
 * `basis` says which of the two happened, so the screen can label the
 * section honestly instead of claiming everything is personalised.
 */
require_once __DIR__ . '/_bootstrap.php';

$limit = (int) ($_GET['limit'] ?? 6);
$limit = max(1, min(12, $limit));

/** Resolves the caller without demanding a token — this endpoint is public. */
$userId = null;
$token = get_bearer_token();
if ($token) {
    $stmt = $pdo->prepare("SELECT user_id FROM api_tokens WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$token]);
    $found = $stmt->fetchColumn();
    if ($found) {
        $userId = (int) $found;
    }
}

/** The product columns every branch returns, with the primary image. */
function cg_product_select(): string
{
    return "
        SELECT p.product_id, p.name, p.price, p.category_id,
               c.name AS category,
               img.image_url,
               COALESCE(AVG(r.rating), 0) AS rating_average,
               COUNT(r.review_id) AS rating_count
        FROM products p
        LEFT JOIN categories c ON c.category_id = p.category_id
        LEFT JOIN product_images img
               ON img.product_id = p.product_id AND img.is_primary = 1
        LEFT JOIN product_reviews r ON r.product_id = p.product_id
    ";
}

function cg_shape(array $rows): array
{
    return array_map(fn($r) => [
        'product_id'     => (int) $r['product_id'],
        'name'           => $r['name'],
        'price'          => (float) $r['price'],
        'category'       => $r['category'],
        'image'          => product_image_url($r['image_url']),
        'rating_average' => round((float) $r['rating_average'], 1),
        'rating_count'   => (int) $r['rating_count'],
    ], $rows);
}

$products = [];
$basis = 'popular';

try {
    if ($userId !== null) {
        // What has this customer shown interest in, and what should not be
        // suggested back to them?
        $seenStmt = $pdo->prepare("
            SELECT product_id FROM wishlist_items WHERE user_id = :uid
            UNION
            SELECT oi.product_id
              FROM order_items oi
              JOIN orders o ON o.order_id = oi.order_id
             WHERE o.user_id = :uid
            UNION
            SELECT product_id FROM cart_items WHERE user_id = :uid
        ");
        $seenStmt->execute([':uid' => $userId]);
        $seen = array_map('intval', array_column($seenStmt->fetchAll(PDO::FETCH_ASSOC), 'product_id'));

        if (!empty($seen)) {
            $catPlaceholders = implode(',', array_fill(0, count($seen), '?'));
            $catStmt = $pdo->prepare("
                SELECT DISTINCT category_id FROM products
                WHERE product_id IN ($catPlaceholders) AND category_id IS NOT NULL
            ");
            $catStmt->execute($seen);
            $categories = array_map('intval', array_column($catStmt->fetchAll(PDO::FETCH_ASSOC), 'category_id'));

            if (!empty($categories)) {
                $catIn  = implode(',', array_fill(0, count($categories), '?'));
                $seenIn = implode(',', array_fill(0, count($seen), '?'));

                $sql = cg_product_select() . "
                    WHERE p.category_id IN ($catIn)
                      AND p.product_id NOT IN ($seenIn)
                    GROUP BY p.product_id, p.name, p.price, p.category_id, c.name, img.image_url
                    ORDER BY COALESCE(AVG(r.rating), 0) DESC, p.product_id DESC
                    LIMIT " . $limit;

                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge($categories, $seen));
                $products = cg_shape($stmt->fetchAll(PDO::FETCH_ASSOC));

                if (!empty($products)) {
                    $basis = 'personal';
                }
            }
        }
    }

    // Nobody signed in, no history, or their categories are exhausted.
    if (empty($products)) {
        $sql = cg_product_select() . "
            GROUP BY p.product_id, p.name, p.price, p.category_id, c.name, img.image_url
            ORDER BY COALESCE(AVG(r.rating), 0) DESC, COUNT(r.review_id) DESC, p.product_id DESC
            LIMIT " . $limit;

        $products = cg_shape($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
        $basis = 'popular';
    }
} catch (PDOException $e) {
    error_log('recommended.php error: ' . $e->getMessage());
    // A home screen missing one shelf is better than a home screen that
    // fails to load, so this answers with an empty list rather than a 500.
    $products = [];
}

json_out([
    'success'  => true,
    'basis'    => $basis,
    'products' => $products,
]);
