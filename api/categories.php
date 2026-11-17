<?php
/**
 * GET /api/categories.php
 * Returns the two-level category tree (main category -> subcategories),
 * same shape shop.php builds for the web filter sidebar — only including
 * subcategories that actually have products, same as the web version.
 */
require_once __DIR__ . '/_bootstrap.php';

$catStmt = $pdo->query("
    SELECT DISTINCT
        parent.category_id AS main_id,
        parent.name AS main_name,
        child.category_id AS sub_id,
        child.name AS sub_name
    FROM categories parent
    INNER JOIN categories child ON child.parent_id = parent.category_id
    INNER JOIN products p ON p.category_id = child.category_id
    WHERE parent.parent_id IS NULL
    ORDER BY parent.category_id, child.category_id
");
$rows = $catStmt->fetchAll(PDO::FETCH_ASSOC);

$tree = [];
foreach ($rows as $row) {
    $mainId = (int) $row['main_id'];
    if (!isset($tree[$mainId])) {
        $mainName = ($row['main_name'] === 'Gemstones & Jewelry Services') ? 'Gemstones' : $row['main_name'];
        $tree[$mainId] = ['id' => $mainId, 'name' => $mainName, 'subcategories' => []];
    }
    $tree[$mainId]['subcategories'][] = [
        'id'   => (int) $row['sub_id'],
        'name' => $row['sub_name'],
    ];
}

json_out(['success' => true, 'categories' => array_values($tree)]);
