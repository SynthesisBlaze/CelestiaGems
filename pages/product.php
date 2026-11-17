<?php

session_start();
require_once __DIR__ . '/../functions/db_connection.php';

$product = null;
$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($productId <= 0) {
    header('Location: shop.php');
    exit;
}

/* ---------------------------------------------------------------------
   1. Fetch the product + its category chain
--------------------------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT
        p.*,
        sub.category_id  AS sub_id,
        sub.name         AS sub_name,
        main.category_id AS main_id,
        main.name        AS main_name
    FROM products p
    INNER JOIN categories sub ON p.category_id = sub.category_id
    LEFT JOIN categories main ON sub.parent_id = main.category_id
    WHERE p.product_id = :id
    LIMIT 1
");
$stmt->execute(['id' => $productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($product) || empty($product['name'])) {
    header('Location: shop.php');
    exit;
}

$product['price'] = (float)($product['price'] ?? 0);

$isWishlisted = false;
if (isset($_SESSION['user_id'])) {
    $wChk = $pdo->prepare("SELECT 1 FROM wishlist_items WHERE user_id = ? AND product_id = ?");
    $wChk->execute([$_SESSION['user_id'], $productId]);
    $isWishlisted = (bool)$wChk->fetch();
}

/* ---------------------------------------------------------------------
   1b. Check if User can review (Purchased & Delivered, Not yet reviewed)
--------------------------------------------------------------------- */
$canReview = false;
if (isset($_SESSION['user_id'])) {
    $chkOrder = $pdo->prepare("
        SELECT 1 FROM orders o
        JOIN order_items oi ON o.order_id = oi.order_id
        WHERE o.user_id = ? AND oi.product_id = ? AND o.order_status = 'Delivered'
        LIMIT 1
    ");
    $chkOrder->execute([$_SESSION['user_id'], $productId]);
    $hasBought = (bool)$chkOrder->fetch();

    $chkRev = $pdo->prepare("SELECT 1 FROM product_reviews WHERE user_id = ? AND product_id = ? LIMIT 1");
    $chkRev->execute([$_SESSION['user_id'], $productId]);
    $hasReviewed = (bool)$chkRev->fetch();

    $canReview = $hasBought && !$hasReviewed;

    // Handle review submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review']) && $canReview) {
        $rating = (int)$_POST['rating'];
        $comment = trim((string)$_POST['comment']);
        
        if ($rating >= 1 && $rating <= 5) {
            $ins = $pdo->prepare("INSERT INTO product_reviews (product_id, user_id, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
            $ins->execute([$productId, $_SESSION['user_id'], $rating, $comment]);
            
            // Reload page to show success message and new review
            header("Location: product.php?id=$productId&reviewed=1");
            exit;
        }
    }
}

/* ---------------------------------------------------------------------
   2. Fetch up to 4 images
--------------------------------------------------------------------- */
$imgStmt = $pdo->prepare("
    SELECT image_url, is_primary
    FROM product_images
    WHERE product_id = :id
    ORDER BY is_primary DESC, image_id ASC
    LIMIT 4
");
$imgStmt->execute(['id' => $productId]);
$images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------------------------------------------------------------------
   3. Fetch reviews
--------------------------------------------------------------------- */
$reviews = [];
try {
    $revStmt = $pdo->prepare("
        SELECT u.first_name, u.last_name, r.rating, r.comment, r.created_at,
               r.admin_reply, r.admin_reply_at
        FROM product_reviews r
        JOIN users u ON r.user_id = u.user_id
        WHERE r.product_id = :id
        ORDER BY r.created_at DESC
    ");
    $revStmt->execute(['id' => $productId]);
    $reviews = $revStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    // Fallback for databases that haven't run sql/2026-10-04_feedback_replies.sql yet,
    // so reviews still show (just without store replies).
    try {
        $revStmt = $pdo->prepare("
            SELECT u.first_name, u.last_name, r.rating, r.comment, r.created_at
            FROM product_reviews r
            JOIN users u ON r.user_id = u.user_id
            WHERE r.product_id = :id
            ORDER BY r.created_at DESC
        ");
        $revStmt->execute(['id' => $productId]);
        $reviews = $revStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e2) {
        $reviews = [];
    }
}

$reviewCount = count($reviews);
$avgRating   = 0;
if ($reviewCount > 0) {
    $sum = 0;
    foreach ($reviews as $r) $sum += (float)$r['rating'];
    $avgRating = $sum / $reviewCount;
}

/* ---------------------------------------------------------------------
   3b. Fetch Product Sizes
--------------------------------------------------------------------- */
$sizes = [];
try {
    $sizeStmt = $pdo->prepare("
        SELECT size_id, size_label, gender, stock_quantity 
        FROM product_sizes 
        WHERE product_id = :id AND stock_quantity > 0 
        ORDER BY gender DESC, size_id ASC
    ");
    $sizeStmt->execute(['id' => $productId]);
    $sizes = $sizeStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $sizes = [];
}

/* ---------------------------------------------------------------------
   4. Fetch related products ("You may also like") — same sub-category
--------------------------------------------------------------------- */
$relStmt = $pdo->prepare("
    SELECT
        p.product_id AS id, p.name, p.price,
        img.image_url AS image
    FROM products p
    LEFT JOIN product_images img ON p.product_id = img.product_id AND img.is_primary = 1
    WHERE p.category_id = :sub_id
      AND p.product_id != :id
    ORDER BY p.product_id DESC
    LIMIT 8
");
$relStmt->execute(['sub_id' => $product['sub_id'], 'id' => $productId]);
$related = $relStmt->fetchAll(PDO::FETCH_ASSOC);

/* Fallback: if the category has no siblings, just show latest products */
if (empty($related)) {
    $relStmt2 = $pdo->prepare("
        SELECT p.product_id AS id, p.name, p.price, img.image_url AS image
        FROM products p
        LEFT JOIN product_images img ON p.product_id = img.product_id AND img.is_primary = 1
        WHERE p.product_id != :id
        ORDER BY p.product_id DESC
        LIMIT 8
    ");
    $relStmt2->execute(['id' => $productId]);
    $related = $relStmt2->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------------------------------------------------------------------
   Helpers
--------------------------------------------------------------------- */
function money($n)
{
    return '₱' . number_format((float)$n, 2);
}

function renderStars($rating, $px = 16)
{
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        $pct = max(0, min(100, ($rating - ($i - 1)) * 100));
        $html .= '<span class="star-wrap" style="width:' . $px . 'px;height:' . $px . 'px;">'
            . '<svg class="star-bg" viewBox="0 0 24 24" width="' . $px . '" height="' . $px . '"><path d="M12 2.5l2.9 6.6 7.1.7-5.4 4.8 1.6 7-6.2-3.8-6.2 3.8 1.6-7-5.4-4.8 7.1-.7z"/></svg>'
            . '<span class="star-fill" style="width:' . $pct . '%;">'
            . '<svg class="star-fg" viewBox="0 0 24 24" width="' . $px . '" height="' . $px . '"><path d="M12 2.5l2.9 6.6 7.1.7-5.4 4.8 1.6 7-6.2-3.8-6.2 3.8 1.6-7-5.4-4.8 7.1-.7z"/></svg>'
            . '</span></span>';
    }
    return $html;
}

/* Optional detail fields — only shown if the column exists on this product */
$detailFields = [
    'sku'        => 'SKU',
    'material'   => 'Material',
    'metal'      => 'Metal',
    'gemstone'   => 'Gemstone',
    'size'       => 'Size',
    'weight'     => 'Weight',
    'dimensions' => 'Dimensions',
];
$detailRows = [];
foreach ($detailFields as $key => $label) {
    if (!empty($product[$key])) {
        $detailRows[$key] = $label;
    }
}

$primaryImage = $images[0]['image_url'] ?? null;
$pageTitle    = htmlspecialchars($product['name']) . ' | The Atelier Collection';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../styles/product.css?v=<?php echo time(); ?>" rel="stylesheet">
</head>

<body>
    <?php $current_page = 'shop'; ?>
    <?php include __DIR__ . '/../components/navbar.php'; ?>

    <div class="product-page">
        <div class="container py-4 py-md-5">

            <nav aria-label="breadcrumb" class="product-breadcrumb mb-4">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="shop.php">Shop</a></li>
                    <?php if (!empty($product['sub_name'])): ?>
                        <li class="breadcrumb-item"><a href="shop.php"><?php echo htmlspecialchars($product['sub_name']); ?></a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($product['name']); ?></li>
                </ol>
            </nav>

            <div class="row g-4 g-lg-5">

                <!-- ============ GALLERY ============ -->
                <div class="col-lg-6">
                    <div class="product-gallery">

                        <button type="button" class="btn wishlist-btn-lg <?php echo $isWishlisted ? 'is-active' : ''; ?>" id="wishlistBtn"
                            aria-pressed="<?php echo $isWishlisted ? 'true' : 'false'; ?>" aria-label="<?php echo $isWishlisted ? 'Remove from wishlist' : 'Add to wishlist'; ?>" data-product-id="<?php echo (int)$product['product_id']; ?>">
                            <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" fill="<?php echo $isWishlisted ? 'var(--cg-gold)' : 'none'; ?>">
                                <path d="M12 21s-7.5-4.8-10-9.3C0.3 8.1 2 4.5 5.6 4.1 8 3.9 10.2 5.3 12 8c1.8-2.7 4-4.1 6.4-3.9 3.6.4 5.3 4 3.6 7.6C19.5 16.2 12 21 12 21z" />
                            </svg>
                        </button>

                        <div id="productCarousel" class="carousel slide product-carousel" data-bs-ride="false">
                            <div class="carousel-inner">
                                <?php if (!empty($images)): ?>
                                    <?php foreach ($images as $i => $img): ?>
                                        <div class="carousel-item <?php echo $i === 0 ? 'active' : ''; ?>">
                                            <div class="gallery-frame">
                                                <img src="/img/<?php echo htmlspecialchars($img['image_url']); ?>"
                                                    alt="<?php echo htmlspecialchars($product['name']); ?> — view <?php echo $i + 1; ?>"
                                                    loading="<?php echo $i === 0 ? 'eager' : 'lazy'; ?>">
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="carousel-item active">
                                        <div class="gallery-frame gallery-frame-empty">
                                            <svg viewBox="0 0 48 48" width="96" height="96">
                                                <path d="M10 16 L24 6 L38 16 L24 43 Z" stroke-width="1.4" fill="none" />
                                                <path d="M10 16 L38 16 M17 16 L24 43 M31 16 L24 43 M17 16 L24 6 M31 16 L24 6" stroke-width="0.9" fill="none" />
                                            </svg>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if (count($images) > 1): ?>
                                <button class="carousel-control-prev" type="button" data-bs-target="#productCarousel" data-bs-slide="prev">
                                    <span class="gallery-arrow" aria-hidden="true">&#8249;</span>
                                    <span class="visually-hidden">Previous</span>
                                </button>
                                <button class="carousel-control-next" type="button" data-bs-target="#productCarousel" data-bs-slide="next">
                                    <span class="gallery-arrow" aria-hidden="true">&#8250;</span>
                                    <span class="visually-hidden">Next</span>
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if (count($images) > 1): ?>
                            <div class="gallery-thumbs d-flex gap-2 mt-3" id="galleryThumbs">
                                <?php foreach ($images as $i => $img): ?>
                                    <button type="button" class="thumb-btn <?php echo $i === 0 ? 'active' : ''; ?>"
                                        data-bs-target="#productCarousel" data-bs-slide-to="<?php echo $i; ?>"
                                        aria-label="View image <?php echo $i + 1; ?>">
                                        <img src="/img/<?php echo htmlspecialchars($img['image_url']); ?>" alt="">
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ============ PRODUCT INFO ============ -->
                <div class="col-lg-6">
                    <div class="product-info-panel">
                        <?php if (!empty($product['sub_name'])): ?>
                            <span class="eyebrow d-inline-flex align-items-center gap-2 mb-2"><?php echo htmlspecialchars($product['sub_name']); ?></span>
                        <?php endif; ?>

                        <h1 class="product-title mb-2"><?php echo htmlspecialchars($product['name']); ?></h1>

                        <div class="d-flex align-items-center gap-2 product-rating-row mb-3">
                            <span class="stars-inline"><?php echo renderStars($avgRating); ?></span>
                            <button type="button" class="review-count-link" id="reviewJumpLink">
                                <?php if ($reviewCount > 0): ?>
                                    <?php echo number_format($avgRating, 1); ?> · <?php echo $reviewCount; ?> review<?php echo $reviewCount === 1 ? '' : 's'; ?>
                                <?php else: ?>
                                    Be the first to review
                                <?php endif; ?>
                            </button>
                        </div>

                        <div class="product-price-lg mb-3"><?php echo money($product['price']); ?></div>

                        <?php if (!empty($product['description'])): ?>
                            <p class="product-short-desc mb-4"><?php echo nl2br(htmlspecialchars(mb_strimwidth($product['description'], 0, 180, '…'))); ?></p>
                        <?php endif; ?>

                        <!-- ============ SIZE SELECTOR ============ -->
                        <?php
                        // Only show sizes if they exist and aren't just 'Standard'
                        if (!empty($sizes) && (count($sizes) > 1 || $sizes[0]['size_label'] !== 'Standard')):
                            $isRingCategory = in_array($product['sub_name'], ['Rings', 'Engagement rings', 'Wedding rings']);
                        ?>
                            <div class="product-size-wrapper mb-4">
                                <div class="size-selector-head">
                                    <span class="size-label" id="sizeSelectorLabel">Select Size</span>
                                    <span class="size-selected-value" id="sizeSelectedValue" aria-live="polite">
                                        <?php echo htmlspecialchars($sizes[0]['size_label']); ?>
                                    </span>
                                </div>
                                <div class="size-options" role="radiogroup" aria-labelledby="sizeSelectorLabel">
                                    <?php foreach ($sizes as $idx => $sz): ?>
                                        <div class="size-option">
                                            <input type="radio" class="size-check" name="product_size"
                                                id="size_<?php echo $sz['size_id']; ?>"
                                                value="<?php echo $sz['size_id']; ?>"
                                                data-size-label="<?php echo htmlspecialchars($sz['size_label']); ?>"
                                                <?php echo $idx === 0 ? 'checked' : ''; ?>>
                                            <label class="size-btn" for="size_<?php echo $sz['size_id']; ?>">
                                                <span><?php echo htmlspecialchars($sz['size_label']); ?></span>

                                                <!-- Show Gender for Rings -->
                                                <?php if ($isRingCategory): ?>
                                                    <small class="size-gender"><?php echo htmlspecialchars($sz['gender']); ?></small>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex align-items-center gap-3 purchase-row mb-4">
                            <div class="qty-selector d-flex align-items-center">
                                <button type="button" class="qty-btn" id="qtyMinus" aria-label="Decrease quantity">&minus;</button>
                                <input type="text" class="qty-input" id="qtyInput" value="1" readonly aria-label="Quantity">
                                <button type="button" class="qty-btn" id="qtyPlus" aria-label="Increase quantity">&plus;</button>
                            </div>
                            <button type="button" class="btn btn-gold flex-fill add-bag-btn-lg" id="addToBagBtn">
                                Add to Bag
                            </button>
                        </div>

                        <!-- ============ CONTENT ACCORDION ============ -->
                        <div class="accordion accordion-flush product-accordion" id="productAccordion">

                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#pdDescription" aria-expanded="true" aria-controls="pdDescription">
                                        Product Description
                                    </button>
                                </h2>
                                <div id="pdDescription" class="accordion-collapse collapse show" data-bs-parent="#productAccordion">
                                    <div class="accordion-body">
                                        <?php if (!empty($product['description'])): ?>
                                            <p><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                                        <?php else: ?>
                                            <p class="text-muted-soft">No description available for this piece yet.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#pdDetails" aria-expanded="false" aria-controls="pdDetails">
                                        Product Details
                                    </button>
                                </h2>
                                <div id="pdDetails" class="accordion-collapse collapse" data-bs-parent="#productAccordion">
                                    <div class="accordion-body">
                                        <ul class="detail-list list-unstyled mb-0">
                                            <?php if (!empty($product['sub_name'])): ?>
                                                <li><span>Category</span><span><?php echo htmlspecialchars($product['sub_name']); ?></span></li>
                                            <?php endif; ?>
                                            <?php foreach ($detailRows as $key => $label): ?>
                                                <li><span><?php echo $label; ?></span><span><?php echo htmlspecialchars($product[$key]); ?></span></li>
                                            <?php endforeach; ?>
                                            <?php if (empty($detailRows)): ?>
                                                <li class="text-muted-soft">Additional specifications will be added soon.</li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#pdDelivery" aria-expanded="false" aria-controls="pdDelivery">
                                        Delivery &amp; Returns
                                    </button>
                                </h2>
                                <div id="pdDelivery" class="accordion-collapse collapse" data-bs-parent="#productAccordion">
                                    <div class="accordion-body">
                                        <ul class="delivery-list list-unstyled mb-0">
                                            <li>Handcrafted to order — ships within 5–7 business days.</li>
                                            <li>Free insured shipping nationwide on all orders.</li>
                                            <li>Arrives in a signature Atelier presentation box.</li>
                                            <li>30-day returns on unworn pieces in original packaging.</li>
                                            <li>Complimentary resizing within 60 days of purchase.</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#pdReviews" aria-expanded="false" aria-controls="pdReviews">
                                        Reviews &amp; Feedback
                                    </button>
                                </h2>
                                <div id="pdReviews" class="accordion-collapse collapse" data-bs-parent="#productAccordion">
                                    <div class="accordion-body">
                                        
                                        <?php if (isset($_GET['reviewed'])): ?>
                                            <div class="alert alert-success py-2 mb-4 cg-notice cg-notice--info">
                                                Thank you! Your feedback has been posted.
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($canReview): ?>
                                            <div class="write-review-box mb-4">
                                                <h4 class="mb-3" style="font-family: var(--font-display); color:var(--cg-text);">Write a Review</h4>
                                                <form method="POST" action="product.php?id=<?php echo $productId; ?>">
                                                    <div class="mb-2">
                                                        <label class="form-label text-muted-soft mb-0">Your Rating</label>
                                                        <div class="star-rating-input">
                                                            <input type="radio" name="rating" id="star5" value="5" required><label for="star5" title="5 stars">★</label>
                                                            <input type="radio" name="rating" id="star4" value="4"><label for="star4" title="4 stars">★</label>
                                                            <input type="radio" name="rating" id="star3" value="3"><label for="star3" title="3 stars">★</label>
                                                            <input type="radio" name="rating" id="star2" value="2"><label for="star2" title="2 stars">★</label>
                                                            <input type="radio" name="rating" id="star1" value="1"><label for="star1" title="1 star">★</label>
                                                        </div>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label for="reviewComment" class="form-label text-muted-soft">Your Feedback</label>
                                                        <textarea name="comment" id="reviewComment" class="form-control review-textarea" rows="3" placeholder="Tell us what you loved about this piece..." required></textarea>
                                                    </div>
                                                    <button type="submit" name="submit_review" class="btn btn-gold btn-sm px-4">Submit Review</button>
                                                </form>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($reviewCount > 0): ?>
                                            <div class="review-summary d-flex align-items-center gap-3 mb-4">
                                                <span class="review-summary-score"><?php echo number_format($avgRating, 1); ?></span>
                                                <div>
                                                    <div class="stars-inline mb-1"><?php echo renderStars($avgRating, 18); ?></div>
                                                    <span class="text-muted-soft">Based on <?php echo $reviewCount; ?> review<?php echo $reviewCount === 1 ? '' : 's'; ?></span>
                                                </div>
                                            </div>
                                            <div class="review-list d-flex flex-column gap-3">
                                                <?php foreach ($reviews as $r): ?>
                                                    <div class="review-card">
                                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                                            <span class="review-name"><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></span>
                                                            <span class="review-date"><?php echo !empty($r['created_at']) ? date('M j, Y', strtotime($r['created_at'])) : ''; ?></span>
                                                        </div>
                                                        <div class="stars-inline mb-2"><?php echo renderStars((float)$r['rating'], 14); ?></div>
                                                        <p class="review-comment mb-0"><?php echo nl2br(htmlspecialchars($r['comment'] ?? '')); ?></p>
                                                        <?php if (!empty($r['admin_reply'])): ?>
                                                            <div class="review-reply">
                                                                <div class="review-reply-head">
                                                                    <span class="review-reply-label">
                                                                        <img src="/img/logo.png" alt="" width="16" height="16">
                                                                        Response from Celestia Gems
                                                                    </span>
                                                                    <?php if (!empty($r['admin_reply_at'])): ?>
                                                                        <span class="review-date"><?php echo date('M j, Y', strtotime($r['admin_reply_at'])); ?></span>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <p class="review-reply-text mb-0"><?php echo nl2br(htmlspecialchars($r['admin_reply'])); ?></p>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-muted-soft mb-0">No reviews yet — be the first to share your thoughts on this piece.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <!-- ============ YOU MAY ALSO LIKE ============ -->
            <?php if (!empty($related)): ?>
                <section class="also-like-section mt-5 pt-5">
                    <h2 class="section-title text-center mb-4">You May Also Like</h2>

                    <div class="also-like-carousel-wrap">
                        <button type="button" class="carousel-nav-btn prev" id="alsoPrev" aria-label="Scroll left">&#8249;</button>

                        <div class="also-like-track" id="alsoLikeTrack">
                            <?php foreach ($related as $rp): ?>
                                <a href="product.php?id=<?php echo (int)$rp['id']; ?>" class="also-like-card">
                                    <div class="also-like-media">
                                        <?php if (!empty($rp['image'])): ?>
                                            <img src="/img/<?php echo htmlspecialchars($rp['image']); ?>" alt="<?php echo htmlspecialchars($rp['name']); ?>" loading="lazy">
                                        <?php else: ?>
                                            <svg viewBox="0 0 48 48" width="56" height="56">
                                                <path d="M10 16 L24 6 L38 16 L24 43 Z" stroke-width="1.4" fill="none" />
                                                <path d="M10 16 L38 16 M17 16 L24 43 M31 16 L24 43 M17 16 L24 6 M31 16 L24 6" stroke-width="0.9" fill="none" />
                                            </svg>
                                        <?php endif; ?>
                                    </div>
                                    <div class="also-like-info">
                                        <span class="also-like-name"><?php echo htmlspecialchars($rp['name']); ?></span>
                                        <span class="also-like-price"><?php echo money($rp['price']); ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <button type="button" class="carousel-nav-btn next" id="alsoNext" aria-label="Scroll right">&#8250;</button>
                    </div>
                </section>
            <?php endif; ?>

        </div>
    </div>

    <div class="signin-modal-backdrop" id="signinPromptBackdrop" hidden>
        <div class="signin-modal" role="dialog" aria-modal="true" aria-labelledby="signinPromptTitle">
            <button type="button" class="signin-modal-close" id="signinPromptClose" aria-label="Close sign in prompt">&times;</button>
            <div class="signin-modal-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
            </div>
            <p class="signin-modal-eyebrow">Member access</p>
            <h3 id="signinPromptTitle">Please sign in to add this piece to your bag</h3>
            <p class="signin-modal-copy">Your selected item is ready to save, but you’ll need an account before we can place it in your bag.</p>
            <div class="signin-modal-actions">
                <button type="button" class="btn btn-light signin-modal-secondary" id="signinPromptCancel">Continue browsing</button>
                <button type="button" class="btn btn-gold signin-modal-primary" id="signinPromptSignIn">Sign In</button>
            </div>
        </div>
    </div>

    <div class="added-toast" id="addedToast" aria-live="polite" aria-atomic="true" hidden>
        <div class="added-toast-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M5 12.5 9.2 16.7 19 6.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <div>
            <div class="added-toast-title">Added to bag</div>
            <div class="added-toast-copy">Your item is now in your cart.</div>
        </div>
    </div>

    <div class="added-toast" id="wishlistToast" aria-live="polite" aria-atomic="true" hidden>
        <div class="added-toast-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" fill="#C9A15C"><path d="M12 21s-7.5-4.8-10-9.3C0.3 8.1 2 4.5 5.6 4.1 8 3.9 10.2 5.3 12 8c1.8-2.7 4-4.1 6.4-3.9 3.6.4 5.3 4 3.6 7.6C19.5 16.2 12 21 12 21z"/></svg>
        </div>
        <div>
            <div class="added-toast-title">Wishlist</div>
            <div class="added-toast-copy" id="wishlistToastMsg">Updated.</div>
        </div>
    </div>

    <?php include __DIR__ . '/../components/footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(function() {
            "use strict";

            /* Sync thumbnail active state with carousel */
            var $carousel = $('#productCarousel');
            $carousel.on('slid.bs.carousel', function(e) {
                $('#galleryThumbs .thumb-btn').removeClass('active').eq(e.to).addClass('active');
            });

            /* Wishlist toggle */
            $('#wishlistBtn').on('click', function() {
                if (!isLoggedIn) {
                    openSigninPrompt();
                    return;
                }
    
                var $btn = $(this);
                var id = $btn.data('product-id');
                var pressed = $btn.attr('aria-pressed') === 'true';
    
                // Optimistic UI toggle
                $btn.attr('aria-pressed', !pressed);
                $btn.attr('aria-label', !pressed ? 'Remove from wishlist' : 'Add to wishlist');
                $btn.find('svg').attr('fill', !pressed ? 'var(--cg-gold)' : 'none');
                $btn.toggleClass('is-active', !pressed);

                $.ajax({
                        url: '../functions/wishlist-toggle.php',
                        type: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ product_id: id }),
                        success: function(res) {
                            if (res && res.success) {
                                var msg = res.status === 'added' ? 'Added to your wishlist.' : 'Removed from your wishlist.';
                                showWishlistToast(msg);
                            }
                        },
                        error: function() {
                            p.wishlisted = wasWishlisted;
                            $btn.attr("aria-pressed", p.wishlisted);
                            showWishlistToast("Unable to update wishlist.");
                        }
                    });
            });

            /* Size selector — keep the "currently selected" readout in sync */
            $('.size-check').on('change', function() {
                $('#sizeSelectedValue').text($(this).data('size-label'));
            });

            /* Quantity stepper */
            var qty = 1;

            function setQty(v) {
                qty = Math.max(1, v);
                $('#qtyInput').val(qty);
            }
            $('#qtyMinus').on('click', function() {
                setQty(qty - 1);
            });
            $('#qtyPlus').on('click', function() {
                setQty(qty + 1);
            });

            /* Add to bag */
            var isLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
            var $signinBackdrop = $('#signinPromptBackdrop');
            var selectedSizeId = $('input[name="product_size"]:checked').val() || 0;
            var selectedSizeLabel = $('input[name="product_size"]:checked').data('size-label') || 'Standard';

            function openSigninPrompt() {
                $signinBackdrop.removeAttr('hidden');
            }

            function closeSigninPrompt() {
                $signinBackdrop.attr('hidden', 'hidden');
            }

            $('input[name="product_size"]').on('change', function() {
                selectedSizeId = $(this).val() || 0;
                selectedSizeLabel = $(this).data('size-label') || 'Standard';
                $('#sizeSelectedValue').text(selectedSizeLabel);
            });

            $('#signinPromptClose, #signinPromptCancel').on('click', closeSigninPrompt);
            $('#signinPromptSignIn').on('click', function() {
                var returnUrl = encodeURIComponent(window.location.pathname + window.location.search);
                window.location.href = 'login.php?return_url=' + returnUrl;
            });

            function syncCartBadge(count) {
                var cartCount = Number(count) || 0;
                var $badge = $('.cg-cart-badge');
                var $cartLink = $('.cg-icon-link[href="/pages/cart.php"]');

                if (cartCount > 0) {
                    if ($badge.length) {
                        $badge.text(cartCount);
                    } else if ($cartLink.length) {
                        $cartLink.append('<span class="badge rounded-pill cg-cart-badge position-absolute top-0 start-100">' + cartCount + '</span>');
                    }
                } else if ($badge.length) {
                    $badge.remove();
                }
            }

            function showAddedToast(message) {
                var $toast = $('#addedToast');
                var text = message || 'Your item is now in your cart.';
                $toast.find('.added-toast-copy').text(text);
                $toast.removeAttr('hidden');
                clearTimeout(showAddedToast.timer);
                showAddedToast.timer = setTimeout(function() {
                    $toast.attr('hidden', 'hidden');
                }, 2200);
            }

            $('#addToBagBtn').on('click', function() {
                if (!isLoggedIn) {
                    openSigninPrompt();
                    return;
                }

                var payload = {
                    action: 'add',
                    product_id: <?php echo (int) $product['product_id']; ?>,
                    quantity: Number($('#qtyInput').val() || 1),
                    size_id: Number(selectedSizeId || 0),
                    size_label: selectedSizeLabel || 'Standard'
                };

                $.ajax({
                    url: '../functions/cart.php',
                    type: 'POST',
                    contentType: 'application/json; charset=UTF-8',
                    data: JSON.stringify(payload),
                    success: function(response) {
                        if (response && response.success) {
                            var $btn = $(this);
                            var original = $btn.text();
                            $btn.prop('disabled', true).text('Added ✓');
                            setTimeout(function() {
                                $btn.prop('disabled', false).text(original);
                            }, 1400);

                            if (response.count !== undefined) {
                                syncCartBadge(response.count);
                            }

                            showAddedToast('Your item is now in your cart.');
                        }
                    }.bind(this),
                    error: function(xhr) {
                        var msg = 'Unable to add this item to your bag.';
                        try {
                            var data = JSON.parse(xhr.responseText);
                            if (data && data.message) msg = data.message;
                        } catch (e) {}

                        showAddedToast(msg);
                    }
                });
            });

            /* Jump to & open the Reviews accordion */
            $('#reviewJumpLink').on('click', function() {
                var $target = $('#pdReviews');
                var collapse = bootstrap.Collapse.getOrCreateInstance($target[0]);
                collapse.show();
                $('html, body').animate({
                    scrollTop: $('#pdReviews').closest('.accordion-item').offset().top - 100
                }, 400);
            });

            /* "You may also like" horizontal carousel */
            var $track = $('#alsoLikeTrack');

            function scrollAmount() {
                var $card = $track.find('.also-like-card').first();
                return $card.length ? $card.outerWidth(true) * 2 : 300;
            }
            $('#alsoNext').on('click', function() {
                $track.animate({
                    scrollLeft: '+=' + scrollAmount()
                }, 350);
            });
            $('#alsoPrev').on('click', function() {
                $track.animate({
                    scrollLeft: '-=' + scrollAmount()
                }, 350);
            });
        });

        function showWishlistToast(message) {
            var $toast = $('#wishlistToast');
            $('#wishlistToastMsg').text(message);
            $toast.removeAttr('hidden');
            clearTimeout(showWishlistToast.timer);
            showWishlistToast.timer = setTimeout(function() {
                $toast.attr('hidden', 'hidden');
            }, 2500); 
        }
    </script>
</body>

</html>