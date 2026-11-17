<?php
/**
 * GET /api/products.php
 * Optional query params:
 *   ?category_id=6        filter by (sub)category
 *   ?search=ruby           matches name / description / category name
 *   ?page=1&per_page=20    pagination (defaults: page 1, 20 per page)
 *
 * Public endpoint — no auth needed to browse the catalog. If a token IS
 * sent, wishlisted is filled in accurately; otherwise it's just false for
 * everything (same graceful behavior as shop.php for a guest).
 */
require_once __DIR__ . '/_bootstrap.php';

$categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;
$search     = trim($_GET['search'] ?? '');
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage    = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
$offset     = ($page - 1) * $perPage;

$conditions = [];
$params = [];

if ($categoryId > 0) {
    $conditions[] = 'p.category_id = :category_id';
    $params[':category_id'] = $categoryId;
}
if ($search !== '') {
    $conditions[] = '(p.name LIKE :search OR p.description LIKE :search OR sub.name LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}
$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM products p
    INNER JOIN categories sub ON p.category_id = sub.category_id
    $where
");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT p.product_id, p.name, p.price, p.description, p.category_id,
           sub.name AS category_name, main.name AS main_category_name,
           img.image_url
    FROM products p
    INNER JOIN categories sub ON p.category_id = sub.category_id
    LEFT JOIN categories main ON sub.parent_id = main.category_id
    LEFT JOIN product_images img ON p.product_id = img.product_id AND img.is_primary = 1
    $where
    ORDER BY p.product_id ASC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Optional: fill in accurate wishlist flags if a valid token was sent
$wishlisted = [];
$token = get_bearer_token();
if ($token) {
    $userStmt = $pdo->prepare("SELECT user_id FROM api_tokens WHERE token = ? AND expires_at > NOW()");
    $userStmt->execute([$token]);
    $userId = $userStmt->fetchColumn();
    if ($userId) {
        $wStmt = $pdo->prepare("SELECT product_id FROM wishlist_items WHERE user_id = ?");
        $wStmt->execute([$userId]);
        $wishlisted = $wStmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

$products = array_map(function ($row) use ($wishlisted) {
    return [
        'product_id'   => (int) $row['product_id'],
        'name'         => $row['name'],
        'price'        => (float) $row['price'],
        'description'  => $row['description'],
        'category_id'  => (int) $row['category_id'],
        'category'     => $row['category_name'],
        'main_category'=> $row['main_category_name'],
        'image'        => product_image_url($row['image_url']),
        'wishlisted'   => in_array((int) $row['product_id'], $wishlisted, true),
    ];
}, $rows);

json_out([
    'success' => true,
    'products' => $products,
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => (int) ceil($total / $perPage),
    ],
]);
