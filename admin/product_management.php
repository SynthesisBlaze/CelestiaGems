<?php
require_once __DIR__ . '/../functions/permissions.php';
cg_require_page('products.view');

require_once __DIR__ . '/../functions/db_connection.php';

$current_admin_page = 'products';
$page_title = 'Products';
$admin_user = cg_admin_user();

// Below this total stock, a product is flagged "Low Stock" instead of "In Stock"
const LOW_STOCK_THRESHOLD = 5;

// --- Fetch categories (for the filter dropdown) ---
$categories = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

/**
 * ---------------------------------------------------------------
 * Filters (all optional, all via GET so the page is bookmarkable)
 * ---------------------------------------------------------------
 */
$f_search      = trim($_GET['search'] ?? '');
$f_category_id = trim($_GET['category_id'] ?? '');

$per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

$where  = [];
$params = [];

if ($f_search !== '') {
    $where[] = "p.name ILIKE :search";
    $params[':search'] = '%' . $f_search . '%';
}
if ($f_category_id !== '') {
    $where[] = "p.category_id = :category_id";
    $params[':category_id'] = (int)$f_category_id;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// --- Total count for pagination ---
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM products p $whereSql");
$stmtCount->execute($params);
$total_rows = (int)$stmtCount->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// --- Fetch the page of products, with aggregated stock + primary image ---
$sql = "
    SELECT
        p.product_id, p.name, p.description, p.price, p.category_id,
        c.name AS category_name,
        COALESCE(SUM(ps.stock_quantity), 0) AS total_stock,
        (SELECT pi.image_url FROM product_images pi
            WHERE pi.product_id = p.product_id
            ORDER BY pi.is_primary DESC, pi.image_id ASC
            LIMIT 1) AS thumbnail_url
    FROM products p
    LEFT JOIN categories c ON c.category_id = p.category_id
    LEFT JOIN product_sizes ps ON ps.product_id = p.product_id
    $whereSql
    GROUP BY p.product_id, c.category_id
    ORDER BY p.created_at DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

function buildQueryString(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    $query = array_filter($query, fn($v) => $v !== '' && $v !== null);
    return htmlspecialchars('?' . http_build_query($query));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products — Celestia Gems Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/../styles/admin.css">
</head>

<body>

    <div class="admin-shell">
        <?php include __DIR__ . '/../components/admin_sidebar.php'; ?>

        <div class="admin-main">
            <?php include __DIR__ . '/../components/admin_topbar.php'; ?>

            <div class="admin-content">

                <?php if (isset($_GET['saved'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= $_GET['saved'] === 'created' ? 'Product created successfully!' : 'Product updated successfully!' ?>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['deleted'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        Product deleted.
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_GET['error']) ?>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- ============ FILTER BAR ============ -->
                <form class="admin-panel shadow-sm filter-bar mb-4" method="get">
                    <div class="row g-3 align-items-end">
                        <div class="col-6 col-md-4 col-lg-3">
                            <label class="form-label fw-medium d-block">Search</label>
                            <input type="text" class="form-control" name="search" placeholder="Product name"
                                value="<?= htmlspecialchars($f_search) ?>">
                        </div>
                        <div class="col-6 col-md-4 col-lg-4">
                            <label class="form-label fw-medium d-block">Category</label>
                            <select class="form-select" name="category_id">
                                <option value="">All categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= (int)$cat['category_id'] ?>" <?= $f_category_id == $cat['category_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2 d-flex gap-2">
                            <button type="submit" class="btn btn-gold px-3 py-2 flex-fill">Filter</button>
                        </div>
                    </div>
                    <?php if ($f_search || $f_category_id): ?>
                        <div class="mt-3 text-end">
                            <a href="product_management.php" class="filter-clear-link text-decoration-none">Clear all filters</a>
                        </div>
                    <?php endif; ?>
                </form>

                <!-- ============ PRODUCTS TABLE ============ -->
                <div class="admin-panel shadow-sm">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="admin-panel-title d-flex align-items-center mb-0">
                            All Products
                            <span class="audit-count ms-2">(<?= number_format($total_rows) ?> <?= $total_rows === 1 ? 'item' : 'items' ?>)</span>
                        </div>
                        <?php if (cg_can('products.manage')): ?>
                            <a href="product_form.php" class="btn btn-gold px-3 py-2 fw-medium">+ Add Product</a>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:.8rem;">View only — ask an Admin to add or edit products.</span>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($products)): ?>
                        <div class="empty-state d-flex flex-column align-items-center justify-content-center p-5">
                            <svg class="icon mb-3 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" style="width: 48px; height: 48px;">
                                <path d="M4 19.5A2.5 2.5 0 016.5 17H20" />
                                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z" />
                            </svg>
                            <div class="title fs-5 mb-2">No products found</div>
                            <div class="hint text-muted">
                                <?= ($f_search || $f_category_id)
                                    ? 'Try widening or clearing your filters.'
                                    : 'Click "Add Product" above to create the first one.' ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="admin-table table-hover align-middle w-100">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;"></th>
                                        <th>Name</th>
                                        <th>Category</th>
                                        <th>Price</th>
                                        <th>Stock</th>
                                        <?php if (cg_can('products.manage')): ?><th class="text-end">Actions</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products as $p): ?>
                                        <?php
                                        $stock = (int)$p['total_stock'];
                                        if ($stock <= 0) {
                                            $stockBadge = ['class' => 'cancelled', 'label' => 'Out of Stock'];
                                        } elseif ($stock < LOW_STOCK_THRESHOLD) {
                                            $stockBadge = ['class' => 'shipped', 'label' => 'Low Stock (' . $stock . ')'];
                                        } else {
                                            $stockBadge = ['class' => 'delivered', 'label' => 'In Stock (' . $stock . ')'];
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <?php if (!empty($p['thumbnail_url'])): ?>
                                                    <img src="/img/<?= htmlspecialchars($p['thumbnail_url']) ?>" alt="" class="product-thumb">
                                                <?php else: ?>
                                                    <span class="product-thumb-placeholder">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 19.5A2.5 2.5 0 016.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z" /></svg>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-medium text-cg"><?= htmlspecialchars($p['name']) ?></td>
                                            <td class="text-muted"><?= htmlspecialchars($p['category_name'] ?? '—') ?></td>
                                            <td style="color: var(--cg-gold-light);">₱<?= number_format((float)$p['price'], 2) ?></td>
                                            <td>
                                                <span class="status-badge <?= $stockBadge['class'] ?>"><?= $stockBadge['label'] ?></span>
                                            </td>
                                            <?php if (cg_can('products.manage')): ?>
                                            <td class="text-end">
                                                <div class="d-flex justify-content-end gap-2">
                                                    <a href="product_form.php?id=<?= (int)$p['product_id'] ?>"
                                                        class="btn btn-outline-muted btn-sm px-2 py-1"
                                                        style="border-radius:3px; font-size:.72rem; letter-spacing:.04em; text-transform:uppercase;">
                                                        Edit
                                                    </a>
                                                    <form action="../functions/products-delete.php" method="post" class="d-inline delete-product-form">
                                                        <input type="hidden" name="product_id" value="<?= (int)$p['product_id'] ?>">
                                                        <button type="submit" class="btn btn-outline-danger-soft btn-sm px-2 py-1"
                                                            style="border-radius:3px; font-size:.72rem; letter-spacing:.04em; text-transform:uppercase;">
                                                            Delete
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <div class="audit-pagination d-flex align-items-center justify-content-between mt-4">
                                <div class="audit-pagination-info">
                                    Page <span class="fw-medium"><?= $page ?></span> of <span class="fw-medium"><?= $total_pages ?></span>
                                </div>
                                <div class="audit-pagination-controls d-flex gap-2">
                                    <a href="<?= buildQueryString(['page' => max(1, $page - 1)]) ?>"
                                        class="btn btn-outline-muted btn-sm px-3 py-1 <?= $page <= 1 ? 'disabled' : '' ?>">Previous</a>
                                    <a href="<?= buildQueryString(['page' => min($total_pages, $page + 1)]) ?>"
                                        class="btn btn-outline-muted btn-sm px-3 py-1 <?= $page >= $total_pages ? 'disabled' : '' ?>">Next</a>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.delete-product-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!confirm('Delete this product? This also removes its sizes/stock and photos. This cannot be undone.')) {
                    e.preventDefault();
                }
            });
        });

        document.querySelectorAll('.audit-pagination-controls .disabled').forEach(a => {
            a.addEventListener('click', e => e.preventDefault());
        });
    </script>
</body>

</html>