<?php
session_start();
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/google_maps_config.php';
require_once __DIR__ . '/../functions/settings_helper.php';

$current_page = 'checkout';
$error = '';

if (isset($_SESSION['error_msg'])) {
    $error = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}

// Must be logged in to check out
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = $_SESSION['user_id'];

// Basic user info for the contact fields
$userStmt = $pdo->prepare("SELECT first_name, last_name, email, phone FROM users WHERE user_id = ?");
$userStmt->execute([$user_id]);
$userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$userRow) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

// Default / most recent saved address, same pattern as account.php
$addressStmt = $pdo->prepare("SELECT address_line, city, postal_code, latitude, longitude FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, address_id DESC LIMIT 1");
$addressStmt->execute([$user_id]);
$savedAddress = $addressStmt->fetch(PDO::FETCH_ASSOC);

$shipping_lat = (isset($savedAddress['latitude']) && (float)$savedAddress['latitude'] !== 0.0) ? $savedAddress['latitude'] : 14.5995;
$shipping_lng = (isset($savedAddress['longitude']) && (float)$savedAddress['longitude'] !== 0.0) ? $savedAddress['longitude'] : 120.9842;

// Pull the cart the same way cart.php does
$stmt = $pdo->prepare("
    SELECT
        ci.cart_item_id,
        ci.product_id,
        ci.size_id,
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

// Nothing to check out — send them back to the cart
if (empty($cart_items)) {
    header("Location: cart.php");
    exit;
}

$subtotal = 0;
$cart_count = 0;
foreach ($cart_items as $item) {
    $subtotal += (float)$item['price'] * (int)$item['quantity'];
    $cart_count += (int)$item['quantity'];
}
$freeShippingEnabled = get_setting_bool($pdo, 'free_shipping_enabled', true);
$freeShippingThreshold = get_setting_float($pdo, 'free_shipping_threshold', 0);
$shippingFeeAmount = get_setting_float($pdo, 'shipping_fee', 0);

$shipping_fee = ($freeShippingEnabled && $subtotal >= $freeShippingThreshold) ? 0.00 : $shippingFeeAmount;
$total = $subtotal + $shipping_fee;

$codEnabled = get_setting_bool($pdo, 'payment_cod_enabled', true);
$paymongoEnabled = get_setting_bool($pdo, 'payment_paymongo_enabled', true);
$defaultPaymentMethod = $codEnabled ? 'cod' : ($paymongoEnabled ? 'paymongo' : null);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout — Celestia Gems</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/checkout.css">
</head>

<body>

    <?php include __DIR__ . '/../components/navbar.php'; ?>

    <main class="checkout-main">
        <div class="container">

            <p class="checkout-breadcrumb">
                <a href="/index.php">Home</a> <span class="sep">/</span> <a href="cart.php">Cart</a> <span class="sep">/</span> <span class="current">Checkout</span>
            </p>

            <h1 class="checkout-title">Checkout</h1>

            <!-- JOURNEY PROGRESS -->
            <div class="cg-progress">
                <div class="cg-progress-step done">
                    <span class="dot"></span>
                    <span class="label">Cart</span>
                </div>
                <div class="cg-progress-line filled"></div>
                <div class="cg-progress-step current">
                    <span class="dot"></span>
                    <span class="label">Checkout</span>
                </div>
                <div class="cg-progress-line"></div>
                <div class="cg-progress-step">
                    <span class="dot"></span>
                    <span class="label">Confirmation</span>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="cg-alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form action="../functions/checkout-process.php" method="POST" id="checkoutForm">
                <div class="cg-checkout-card">

                    <!-- SUMMARY PANE -->
                    <div class="cg-pane cg-pane-summary">
                        <div class="cg-pane-topline">
                            <span class="cg-kicker">Order details</span>
                            <span class="cg-pill"><?= count($cart_items) ?> item<?= count($cart_items) === 1 ? '' : 's' ?></span>
                        </div>
                        <h2 class="cg-pane-heading">Summary</h2>

                        <div class="cg-item-list">
                            <?php foreach ($cart_items as $item): ?>
                                <?php
                                    $itemPrice = (float)$item['price'];
                                    $itemQty = (int)$item['quantity'];
                                    $itemSubtotal = $itemPrice * $itemQty;
                                    $itemImage = !empty($item['image_url']) ? '/img/' . $item['image_url'] : '/img/default-product.png';
                                    $itemSize = !empty($item['size_label']) ? (string)$item['size_label'] : 'Standard';
                                ?>
                                <div class="cg-item-row">
                                    <img src="<?= htmlspecialchars($itemImage) ?>" alt="<?= htmlspecialchars($item['product_name']) ?>">
                                    <div class="flex-grow-1">
                                        <div class="cg-item-name"><?= htmlspecialchars($item['product_name']) ?></div>
                                        <div class="cg-item-meta">Size <?= htmlspecialchars($itemSize) ?> &nbsp;&middot;&nbsp; Qty <?= $itemQty ?></div>
                                    </div>
                                    <div class="cg-item-price">₱<?= number_format($itemSubtotal, 2) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="cg-box-divider"></div>

                        <div class="cg-box-line">
                            <span>Subtotal</span>
                            <span>&#8369;<?= number_format($subtotal, 2) ?></span>
                        </div>
                        <div class="cg-box-line">
                            <span>Standard shipping</span>
                            <span class="<?= $shipping_fee > 0 ? '' : 'is-free' ?>"><?= $shipping_fee > 0 ? '₱' . number_format($shipping_fee, 2) : 'Complimentary' ?></span>
                        </div>

                        <div class="cg-box-total">
                            <span>Total</span>
                            <span>&#8369;<?= number_format($total, 2) ?></span>
                        </div>
                    </div>

                    <!-- CHECKOUT FORM PANE -->
                    <div class="cg-pane cg-pane-form">
                        <h2 class="cg-pane-heading">Checkout</h2>
                        <p class="cg-pane-sub">Secure, encrypted, and swift processing.</p>

                        <!-- CONTACT INFO -->
                        <section class="cg-section">
                            <p class="cg-kicker">Contact</p>
                            <div class="cg-contact-row">
                                <div>
                                    <span class="cg-contact-name"><?= htmlspecialchars($userRow['first_name'] . ' ' . $userRow['last_name']) ?></span>
                                    <span class="cg-contact-meta"><?= htmlspecialchars($userRow['email']) ?><?= !empty($userRow['phone']) ? ' &nbsp;&middot;&nbsp; ' . htmlspecialchars($userRow['phone']) : '' ?></span>
                                </div>
                                <a href="account.php?tab=details" class="cg-edit-link">Edit in account</a>
                            </div>
                        </section>

                        <!-- DELIVERY ADDRESS -->
                        <section class="cg-section">
                            <p class="cg-kicker">Shipping destination</p>

                            <div id="addressSearchContainer" class="cg-address-search"></div>

                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="cg-label">Street address</label>
                                    <input type="text" class="cg-input" name="address_line" id="addressLine"
                                        value="<?= htmlspecialchars($savedAddress['address_line'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="cg-label">City</label>
                                    <input type="text" class="cg-input" name="city" id="addressCity"
                                        value="<?= htmlspecialchars($savedAddress['city'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="cg-label">Postal code</label>
                                    <input type="text" class="cg-input" name="postal_code" id="addressPostal"
                                        value="<?= htmlspecialchars($savedAddress['postal_code'] ?? '') ?>" required>
                                </div>
                            </div>

                            <div id="addressMap" class="cg-map"></div>
                            <p class="cg-hint">Drag the pin or search above to set your exact delivery location.</p>

                            <input type="hidden" name="latitude" id="shippingLat" value="<?= htmlspecialchars($shipping_lat) ?>">
                            <input type="hidden" name="longitude" id="shippingLng" value="<?= htmlspecialchars($shipping_lng) ?>">

                            <div class="row g-3 mt-1">
                                <div class="col-12">
                                    <label class="cg-label">Delivery notes <span>(optional)</span></label>
                                    <textarea class="cg-input" name="delivery_notes" rows="2" placeholder="Landmark, gate code, preferred time&hellip;"></textarea>
                                </div>
                            </div>
                        </section>

                        <!-- PAYMENT METHOD -->
                        <section class="cg-section">
                            <p class="cg-kicker">Payment</p>

                            <?php if (!$codEnabled && !$paymongoEnabled): ?>
                                <div class="cg-alert">Checkout is temporarily unavailable — no payment methods are enabled right now. Please check back soon.</div>
                            <?php endif; ?>

                            <?php if ($codEnabled): ?>
                            <label class="cg-payment-row" for="paymentCod">
                                <input type="radio" name="payment_method" id="paymentCod" value="cod" <?= $defaultPaymentMethod === 'cod' ? 'checked' : '' ?>>
                                <span class="cg-payment-glyph" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                                        <rect x="2.5" y="5.5" width="19" height="13" rx="2" />
                                        <path d="M2.5 9.5h19" />
                                        <path d="M6 14.5h5" />
                                    </svg>
                                </span>
                                <div class="flex-grow-1">
                                    <span class="cg-payment-title">Cash on Delivery</span>
                                    <span class="cg-payment-desc">Pay in cash when your order arrives.</span>
                                </div>
                                <span class="cg-payment-check" aria-hidden="true">&#10003;</span>
                            </label>
                            <?php endif; ?>

                            <?php if ($paymongoEnabled): ?>
                            <label class="cg-payment-row" for="paymentPaymongo">
                                <input type="radio" name="payment_method" id="paymentPaymongo" value="paymongo" <?= $defaultPaymentMethod === 'paymongo' ? 'checked' : '' ?>>
                                <span class="cg-payment-glyph" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                                        <circle cx="12" cy="12" r="9.5" />
                                        <path d="M8 12.5l2.5 2.5L16 9" />
                                    </svg>
                                </span>
                                <div class="flex-grow-1">
                                    <span class="cg-payment-title">Pay Online via PayMongo</span>
                                    <span class="cg-payment-desc">GCash, card, GrabPay, or Maya via PayMongo's secure checkout. (Sandbox &mdash; test mode)</span>
                                </div>
                                <span class="cg-payment-check" aria-hidden="true">&#10003;</span>
                            </label>
                            <?php endif; ?>
                        </section>

                        <button type="submit" class="cg-place-order" id="placeOrderBtn" <?= $defaultPaymentMethod === null ? 'disabled' : '' ?>>
                            <span class="btn-label-cod">Confirm order &mdash; &#8369;<?= number_format($total, 2) ?> <span class="arrow">&rarr;</span></span>
                            <span class="btn-label-paymongo" style="display:none;">Continue to PayMongo &mdash; &#8369;<?= number_format($total, 2) ?> <span class="arrow">&rarr;</span></span>
                        </button>
                        <a href="cart.php" class="cg-back-link">Back to cart</a>
                        <p class="cg-box-note" id="paymentNote">You'll pay in cash when your order is delivered — nothing is charged today.</p>
                    </div>

                </div>
            </form>

        </div>
    </main>

    <?php include __DIR__ . '/../components/footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(function() {
            function syncPaymentUI() {
                const isPaymongo = $('#paymentPaymongo').is(':checked');
                $('.btn-label-cod').toggle(!isPaymongo);
                $('.btn-label-paymongo').toggle(isPaymongo);
                $('#paymentNote').text(
                    isPaymongo
                        ? "You'll be redirected to PayMongo's secure checkout to complete payment."
                        : "You'll pay in cash when your order is delivered — nothing is charged today."
                );
                $('.cg-payment-row').removeClass('is-selected');
                $('input[name="payment_method"]:checked').closest('.cg-payment-row').addClass('is-selected');
            }

            $('input[name="payment_method"]').on('change', syncPaymentUI);
            syncPaymentUI();

            $('#checkoutForm').on('submit', function(e) {
                const isPaymongo = $('#paymentPaymongo').is(':checked');
                const message = isPaymongo
                    ? 'Continue to PayMongo to complete your payment?'
                    : 'Place this order with Cash on Delivery?';
                if (!confirm(message)) {
                    e.preventDefault();
                }
            });
        });

        // --- Delivery address map (same pattern as account.php) ---
        const GOOGLE_MAPS_KEY = "<?= GOOGLE_MAPS_API_KEY ?>";

        function initMap() {
            const defaultLat = <?= (float)$shipping_lat ?>;
            const defaultLng = <?= (float)$shipping_lng ?>;
            const startPos = { lat: defaultLat, lng: defaultLng };

            const map = new google.maps.Map(document.getElementById('addressMap'), {
                center: startPos,
                zoom: 15,
                disableDefaultUI: true,
                zoomControl: true,
            });

            const marker = new google.maps.Marker({
                position: startPos,
                map: map,
                draggable: true,
            });

            function fillFromLatLng(latLng) {
                document.getElementById('shippingLat').value = latLng.lat();
                document.getElementById('shippingLng').value = latLng.lng();

                const url = `https://geocode.googleapis.com/v4/geocode/location`
                    + `?location.latitude=${latLng.lat()}`
                    + `&location.longitude=${latLng.lng()}`
                    + `&key=${GOOGLE_MAPS_KEY}`;

                fetch(url)
                    .then(res => res.json())
                    .then(data => {
                        if (!data.results || !data.results.length) return;

                        let streetNumber = '', route = '', city = '', postal = '';
                        let formattedAddress = data.results[0].formattedAddress || '';

                        data.results.forEach(result => {
                            (result.addressComponents || []).forEach(c => {
                                if (!streetNumber && c.types.includes('street_number')) streetNumber = c.longText;
                                if (!route && c.types.includes('route')) route = c.longText;
                                if (!city && (c.types.includes('locality') || c.types.includes('administrative_area_level_2'))) city = c.longText;
                                if (!postal && c.types.includes('postal_code')) postal = c.longText;
                            });
                        });

                        document.getElementById('addressLine').value = (streetNumber + ' ' + route).trim() || formattedAddress;
                        if (city) document.getElementById('addressCity').value = city;
                        if (postal) document.getElementById('addressPostal').value = postal;
                    })
                    .catch(err => console.error('Reverse geocoding failed:', err));
            }

            marker.addListener('dragend', () => fillFromLatLng(marker.getPosition()));

            map.addListener('click', (e) => {
                marker.setPosition(e.latLng);
                fillFromLatLng(e.latLng);
            });

            const autocompleteElement = new google.maps.places.PlaceAutocompleteElement();
            autocompleteElement.id = 'addressSearch';
            document.getElementById('addressSearchContainer').appendChild(autocompleteElement);

            autocompleteElement.addEventListener('gmp-select', async ({ placePrediction }) => {
                const place = placePrediction.toPlace();
                await place.fetchFields({ fields: ['location', 'formattedAddress'] });
                if (!place.location) return;
                map.setCenter(place.location);
                map.setZoom(17);
                marker.setPosition(place.location);
                fillFromLatLng(place.location);
            });
        }
    </script>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?= GOOGLE_MAPS_API_KEY ?>&libraries=places&callback=initMap" async defer></script>
</body>

</html>