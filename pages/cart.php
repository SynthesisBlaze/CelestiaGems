<?php
session_start();
// Include the database connection (similar to shop.php)
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/settings_helper.php';

$current_page = 'cart';

// Ensure the user is logged in to view their DB cart. 
// If you support guest carts, you'll need fallback logic here.
$user_id = $_SESSION['user_id'] ?? null;

$cart_items = [];
$subtotal = 0;
$cart_count = 0;

if ($user_id) {
    // Fetch cart items from the database using the schema from image_6a4804.jpg
    $stmt = $pdo->prepare("
        SELECT 
            ci.cart_item_id,
            ci.product_id,
            ci.quantity,
            p.name AS product_name,
            p.price,
            ps.size_label,
            img.image_url
        FROM cart_items ci
        JOIN products p ON ci.product_id = p.product_id
        LEFT JOIN product_sizes ps ON ci.size_id = ps.size_id
        LEFT JOIN product_images img ON p.product_id = img.product_id AND img.is_primary = 1
        WHERE ci.user_id = :user_id
    ");
    $stmt->execute(['user_id' => $user_id]);
    $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals directly from the fetched database records
    foreach ($cart_items as $item) {
        $cart_count += (int)$item['quantity'];
        $subtotal += (float)$item['price'] * (int)$item['quantity'];
    }
}

// Same shipping logic as checkout.php — shown here too so the cart page
// isn't misleadingly vague about what shipping will actually cost.
$freeShippingEnabled = get_setting_bool($pdo, 'free_shipping_enabled', true);
$freeShippingThreshold = get_setting_float($pdo, 'free_shipping_threshold', 0);
$shippingFeeAmount = get_setting_float($pdo, 'shipping_fee', 0);
$shipping_fee = ($freeShippingEnabled && $subtotal >= $freeShippingThreshold) ? 0.00 : $shippingFeeAmount;
$cart_total = $subtotal + $shipping_fee;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Cart — Celestia Gems</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/cart.css">
</head>

<body>

    <?php include __DIR__ . '/../components/navbar.php'; ?>

    <main class="py-5">
        <div class="container">

            <div class="cart-topbar">
                <a href="/pages/shop.php" class="cart-continue-link">
                    <span class="arrow">&larr;</span> Continue Shopping
                </a>
                <?php if ($user_id && !empty($cart_items)): ?>
                    <span class="cart-count-pill"><?= $cart_count ?> item<?= $cart_count === 1 ? '' : 's' ?></span>
                <?php endif; ?>
            </div>

            <div class="mb-5">
                <h1 class="cart-title">Shopping Cart</h1>
                <p class="cart-sub mb-0">Review your items before checkout.</p>
            </div>

            <?php if (empty($cart_items) || !$user_id): ?>

                <!-- EMPTY STATE -->
                <div class="cart-empty">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                        <path d="M6 6h15l-1.5 9h-12z" />
                        <path d="M6 6L4 3H2" />
                        <circle cx="9" cy="20" r="1" />
                        <circle cx="17" cy="20" r="1" />
                    </svg>
                    <div class="title"><?= !$user_id ? 'Please log in to view your cart' : 'Your cart is empty' ?></div>
                    <div class="hint">Looks like you haven't added anything yet. Start exploring the collection to find something you'll love.</div>
                    <a href="/pages/shop.php" class="btn btn-gold px-4 py-3">Continue Shopping</a>
                </div>

            <?php else: ?>

                <div class="row g-4 align-items-start">
                    <div class="col-lg-8">
                        <?php foreach ($cart_items as $item): ?>
                            <?php
                                $cartItemId = (int)$item['cart_item_id'];
                                $productId = (int)$item['product_id'];
                                $itemPrice = (float)$item['price'];
                                $itemQty = (int)$item['quantity'];
                                $itemSubtotal = $itemPrice * $itemQty;
                                $itemImage = !empty($item['image_url']) ? '/img/' . $item['image_url'] : '/img/default-product.png';
                                $itemSize = !empty($item['size_label']) ? (string)$item['size_label'] : 'Standard';
                            ?>
                            <div class="cart-item-row">
                                <a href="product.php?id=<?= $productId ?>" class="cart-item-image-wrap" aria-label="View product detail">
                                    <img src="<?= htmlspecialchars($itemImage) ?>" alt="<?= htmlspecialchars($item['product_name']) ?>" class="cart-item-image">
                                </a>
                                <div class="cart-item-copy">
                                    <a href="product.php?id=<?= $productId ?>" class="text-decoration-none">
                                        <div class="cart-item-name"><?= htmlspecialchars($item['product_name']) ?></div>
                                    </a>
                                    <div class="cart-item-meta">
                                        <span>Size: <strong><?= htmlspecialchars($itemSize) ?></strong></span>
                                        <span>₱<?= number_format($itemPrice, 2) ?></span>
                                    </div>
                                    <div class="qty-inline mt-3">
                                        <button type="button" class="qty-btn-cart qty-change" data-cart-id="<?= $cartItemId ?>" data-change="-1" aria-label="Decrease quantity">&minus;</button>
                                        <span class="qty-readout"><?= $itemQty ?></span>
                                        <button type="button" class="qty-btn-cart qty-change" data-cart-id="<?= $cartItemId ?>" data-change="1" aria-label="Increase quantity">&plus;</button>
                                    </div>
                                </div>
                                <button type="button" class="delete-cart-item cart-remove" data-cart-id="<?= $cartItemId ?>" data-action="remove" aria-label="Remove item">&times;</button>
                                <div class="cart-item-price-block">
                                    <div class="cart-item-subtotal-label">Subtotal</div>
                                    <div class="cart-item-subtotal">₱<?= number_format($itemSubtotal, 2) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="col-lg-4">
                        <div class="cart-summary">
                            <h4>Order Summary</h4>
                            <div class="row-line">
                                <span>Subtotal</span>
                                <span>&#8369;<?= number_format($subtotal, 2) ?></span>
                            </div>
                            <div class="row-line">
                                <span>Shipping</span>
                                <span><?= $shipping_fee > 0 ? '₱' . number_format($shipping_fee, 2) : 'Free' ?></span>
                            </div>
                            <?php if ($freeShippingEnabled && $shipping_fee > 0): ?>
                                <p class="cart-summary-note mb-2">
                                    Add ₱<?= number_format($freeShippingThreshold - $subtotal, 2) ?> more to get free shipping.
                                </p>
                            <?php endif; ?>
                            <div class="row-line total">
                                <span>Total</span>
                                <span>&#8369;<?= number_format($cart_total, 2) ?></span>
                            </div>
                            <a href="/pages/checkout.php" class="btn btn-gold w-100 mt-3 py-3">Proceed to Checkout</a>
                            <p class="cart-summary-note">Secure checkout &middot; pay in cash on delivery</p>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </main>

    <?php include __DIR__ . '/../components/footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(function() {
            function updateCartBadge(count) {
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

            function updateCartItem(cartItemId, change) {
                $.ajax({
                    url: '../functions/cart-update.php',
                    type: 'POST',
                    data: {
                        cart_item_id: cartItemId,
                        change: change
                    },
                    dataType: 'json'
                }).done(function(response) {
                    if (response && (response.success || response.ok)) {
                        updateCartBadge(response.count || 0);
                        window.location.reload();
                        return;
                    }

                    var message = 'Unable to update your cart.';
                    if (response && response.message) {
                        message = response.message;
                    }
                    alert(message);
                }).fail(function(xhr) {
                    var message = 'Unable to update quantity.';
                    try {
                        var json = xhr.responseJSON || {};
                        if (json.message) {
                            message = json.message;
                        }
                    } catch (e) {}
                    alert(message);
                });
            }

            $('.qty-change').on('click', function() {
                var cartItemId = $(this).data('cart-id');
                var change = Number($(this).data('change')) || 0;
                updateCartItem(cartItemId, change);
            });

            $('.cart-remove').on('click', function() {
                var cartItemId = $(this).data('cart-id');
                updateCartItem(cartItemId, -9999);
            });
        });
    </script>
</body>

</html>