<?php
require_once __DIR__ . '/../functions/permissions.php';
cg_require_page('products.manage');

require_once __DIR__ . '/../functions/db_connection.php';

$productId = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : null;
$isEdit = $productId !== null;

$current_admin_page = 'products';
$page_title = $isEdit ? 'Edit Product' : 'Add Product';
$admin_user = cg_admin_user();

$categories = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$product = [
    'product_id'  => null,
    'name'        => '',
    'category_id' => '',
    'description' => '',
    'price'       => '',
];
$sizes = [];
$images = [];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        header("Location: product_management.php?error=" . urlencode('Product not found.'));
        exit;
    }

    $sizeStmt = $pdo->prepare("SELECT size_id, size_label, stock_quantity FROM product_sizes WHERE product_id = ? ORDER BY size_id ASC");
    $sizeStmt->execute([$productId]);
    $sizes = $sizeStmt->fetchAll(PDO::FETCH_ASSOC);

    $imgStmt = $pdo->prepare("SELECT image_id, image_url, is_primary FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, image_id ASC");
    $imgStmt->execute([$productId]);
    $images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> — Celestia Gems Admin</title>
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

            <div class="admin-content max-w-4xl mx-auto">

                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_GET['error']) ?>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom border-secondary-subtle">
                    <h4 class="mb-0 text-cg" style="font-family: var(--font-display);"><?= htmlspecialchars($page_title) ?></h4>
                    <a href="product_management.php" class="btn btn-outline-muted btn-sm px-3 d-inline-flex align-items-center gap-2">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 12H5M12 19l-7-7 7-7" />
                        </svg>
                        Back to Products
                    </a>
                </div>

                <form action="../functions/products-save.php" method="post" enctype="multipart/form-data" id="productForm">
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="product_id" value="<?= (int)$product['product_id'] ?>">
                    <?php endif; ?>

                    <div class="row g-4">
                        <!-- Left Column: Primary Details -->
                        <div class="col-lg-8">
                            <!-- PRODUCT DETAILS -->
                            <div class="admin-panel shadow-sm mb-4">
                                <div class="admin-panel-title d-flex align-items-center gap-2 mb-4">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-muted">
                                        <path d="M20 16.2A2 2 0 0 0 21 14.5v-5A2 2 0 0 0 20 7.8L13.5 4a2 2 0 0 0-2 0L5 7.8A2 2 0 0 0 4 9.5v5a2 2 0 0 0 1 1.7l6.5 3.8a2 2 0 0 0 2 0l6.5-3.8z" />
                                        <polyline points="4.2 8.3 12 12.8 19.8 8.3" />
                                        <line x1="12" y1="22.5" x2="12" y2="12.8" />
                                    </svg>
                                    Primary Details
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-medium">Product Name</label>
                                    <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($product['name']) ?>" required>
                                </div>

                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-medium">Category</label>
                                        <select class="form-select" name="category_id" required>
                                            <option value="">Select a category</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?= (int)$cat['category_id'] ?>" <?= (string)$product['category_id'] === (string)$cat['category_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($cat['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-medium">Price (₱)</label>
                                        <input type="number" class="form-control" name="price" step="0.01" min="0" value="<?= htmlspecialchars($product['price']) ?>" required>
                                    </div>
                                </div>

                                <div class="mb-1">
                                    <label class="form-label fw-medium">Description</label>
                                    <textarea class="form-control" name="description" rows="5" placeholder="Detail the cut, clarity, and materials..."><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <!-- IMAGES -->
                            <div class="admin-panel shadow-sm mb-4">
                                <div class="admin-panel-title d-flex align-items-center gap-2 mb-3">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-muted">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                                        <circle cx="8.5" cy="8.5" r="1.5" />
                                        <polyline points="21 15 16 10 5 21" />
                                    </svg>
                                    Product Photos
                                </div>

                                <?php if (!empty($images)): ?>
                                    <div class="image-grid mb-4">
                                        <?php foreach ($images as $img): ?>
                                            <div class="image-tile p-2" style="background: var(--cg-surface); border: 1px solid var(--cg-hair); border-radius: 4px;">
                                                <img src="/img/<?= htmlspecialchars($img['image_url']) ?>" alt="" class="w-100 rounded mb-2" style="height: 100px; object-fit: cover;">
                                                <div class="d-flex flex-column gap-1">
                                                    <div class="form-check m-0 d-flex align-items-center gap-2">
                                                        <input class="form-check-input m-0" type="radio" name="primary_image_id" value="<?= (int)$img['image_id'] ?>" id="primary-<?= (int)$img['image_id'] ?>" <?= (int)$img['is_primary'] === 1 ? 'checked' : '' ?>>
                                                        <label class="form-check-label text-cg small" for="primary-<?= (int)$img['image_id'] ?>">Primary</label>
                                                    </div>
                                                    <div class="form-check m-0 d-flex align-items-center gap-2">
                                                        <input class="form-check-input m-0" type="checkbox" name="delete_image_ids[]" value="<?= (int)$img['image_id'] ?>" id="delete-<?= (int)$img['image_id'] ?>">
                                                        <label class="form-check-label delete-check-label small" for="delete-<?= (int)$img['image_id'] ?>">Remove</label>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div>
                                    <label class="form-label fw-medium">Upload New Photos</label>
                                    <input type="file" class="form-control" name="images[]" id="newImages" accept="image/jpeg,image/png,image/webp" multiple>
                                    <small class="form-hint text-muted d-block mt-2">
                                        JPG, PNG, or WEBP. You can select multiple files at once.
                                        <?= empty($images) ? 'The first photo uploaded will automatically be set as primary.' : '' ?>
                                    </small>
                                </div>
                                <div class="image-grid mt-3" id="newImagePreview"></div>
                            </div>
                        </div>

                        <!-- Right Column: Inventory & Publishing -->
                        <div class="col-lg-4">
                            <div class="admin-panel shadow-sm mb-4" id="sizesPanel">
                                <div class="admin-panel-title d-flex align-items-center gap-2 mb-3">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-muted">
                                        <line x1="8" y1="6" x2="21" y2="6" />
                                        <line x1="8" y1="12" x2="21" y2="12" />
                                        <line x1="8" y1="18" x2="21" y2="18" />
                                        <line x1="3" y1="6" x2="3.01" y2="6" />
                                        <line x1="3" y1="12" x2="3.01" y2="12" />
                                        <line x1="3" y1="18" x2="3.01" y2="18" />
                                    </svg>
                                    Inventory & Sizes
                                </div>

                                <div id="sizeList" class="d-flex flex-column gap-2 mb-3">
                                    <?php if (empty($sizes)): ?>
                                        <div class="empty-state py-4 text-center rounded border" id="sizeEmptyState" style="background: var(--cg-surface);">
                                            <div class="text-cg small mb-1">No sizes yet</div>
                                            <div class="text-muted" style="font-size: .75rem;">Add a size to track stock.</div>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($sizes as $index => $size): ?>
                                            <div class="d-flex align-items-center gap-2 p-2 rounded" style="background: var(--cg-surface); border: 1px solid var(--cg-hair);">
                                                <div class="flex-grow-1">
                                                    <label class="form-label text-muted mb-1" style="font-size: .65rem; text-transform: uppercase;">Size</label>
                                                    <input type="hidden" name="size_id[]" value="<?= (int)$size['size_id'] ?>">
                                                    <input type="text" class="form-control form-control-sm border-secondary-subtle" name="size_label[]" value="<?= htmlspecialchars($size['size_label']) ?>" placeholder="e.g. 7">
                                                </div>
                                                <div class="flex-grow-1" style="max-width: 80px;">
                                                    <label class="form-label text-muted mb-1" style="font-size: .65rem; text-transform: uppercase;">Stock</label>
                                                    <input type="number" class="form-control form-control-sm border-secondary-subtle" name="stock_quantity[]" min="0" value="<?= (int)$size['stock_quantity'] ?>">
                                                </div>
                                                <div class="pt-4">
                                                    <button type="button" class="btn btn-outline-danger-soft btn-sm size-remove p-1" title="Remove Size">
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M18 6L6 18M6 6l12 12" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <button type="button" class="btn btn-outline-muted btn-sm w-100 py-2 d-flex align-items-center justify-content-center gap-2" id="addSizeBtn">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <line x1="12" y1="5" x2="12" y2="19" />
                                        <line x1="5" y1="12" x2="19" y2="12" />
                                    </svg>
                                    Add Size Option
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Sticky Action Footer -->
                    <div class="d-flex justify-content-end gap-3 mt-2 mb-5 pt-4 border-top border-secondary-subtle">
                        <a href="product_management.php" class="btn btn-outline-muted px-4 py-2">Cancel</a>
                        <button type="submit" class="btn btn-gold px-5 py-2 fw-bold text-dark">Save Product</button>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sizeList = document.getElementById('sizeList');
        let sizeCount = <?= count($sizes) ?>;

        function addSizeItem() {
            const currentEmptyState = document.getElementById('sizeEmptyState');
            if (currentEmptyState) currentEmptyState.remove();

            sizeCount++;
            const wrap = document.createElement('div');
            wrap.className = 'd-flex align-items-center gap-2 p-2 rounded';
            wrap.style.background = 'rgba(var(--cg-text-rgb), 0.015)';
            wrap.style.border = '1px solid var(--cg-hair)';
            wrap.innerHTML = `
                <div class="flex-grow-1">
                    <label class="form-label text-muted mb-1" style="font-size: .65rem; text-transform: uppercase;">Size</label>
                    <input type="hidden" name="size_id[]" value="">
                    <input type="text" class="form-control form-control-sm border-secondary-subtle" name="size_label[]" placeholder="e.g. 7">
                </div>
                <div class="flex-grow-1" style="max-width: 80px;">
                    <label class="form-label text-muted mb-1" style="font-size: .65rem; text-transform: uppercase;">Stock</label>
                    <input type="number" class="form-control form-control-sm border-secondary-subtle" name="stock_quantity[]" min="0" value="0">
                </div>
                <div class="pt-4">
                    <button type="button" class="btn btn-outline-danger-soft btn-sm size-remove p-1" title="Remove Size">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                </div>
            `;
            attachSizeRemoveEvent(wrap.querySelector('.size-remove'), wrap);
            sizeList.appendChild(wrap);
        }

        function createSizeEmptyState() {
            const div = document.createElement('div');
            div.className = 'empty-state py-4 text-center rounded border';
            div.id = 'sizeEmptyState';
            div.style.background = 'rgba(var(--cg-text-rgb), 0.01)';
            div.innerHTML = `
                <div class="text-cg small mb-1">No sizes yet</div>
                <div class="text-muted" style="font-size: .75rem;">Add a size to track stock.</div>
            `;
            return div;
        }

        function attachSizeRemoveEvent(button, wrap) {
            button.addEventListener('click', () => {
                wrap.remove();
                if (sizeList.children.length === 0) {
                    sizeList.appendChild(createSizeEmptyState());
                }
            });
        }

        document.querySelectorAll('.size-remove').forEach(btn => {
            attachSizeRemoveEvent(btn, btn.closest('.d-flex'));
        });

        document.getElementById('addSizeBtn').addEventListener('click', addSizeItem);

        const newImagesInput = document.getElementById('newImages');
        const newImagePreview = document.getElementById('newImagePreview');

        newImagesInput.addEventListener('change', () => {
            newImagePreview.innerHTML = '';
            Array.from(newImagesInput.files).forEach(file => {
                const url = URL.createObjectURL(file);
                const tile = document.createElement('div');
                tile.className = 'image-tile p-2 border border-secondary-subtle rounded';
                tile.innerHTML = `<img src="${url}" alt="" class="w-100 rounded mb-1" style="height: 70px; object-fit: cover;"><div class="text-truncate text-muted" style="font-size:.65rem;" title="${file.name}">${file.name}</div>`;
                newImagePreview.appendChild(tile);
            });
        });

        <?php if ($isEdit): ?>
            document.getElementById('productForm').addEventListener('submit', function(e) {
                if (!confirm('Save changes to this product?')) {
                    e.preventDefault();
                }
            });
        <?php endif; ?>
    </script>
</body>

</html>