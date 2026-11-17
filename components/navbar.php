<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/settings_helper.php';

$current_page = $current_page ?? 'home';

// Always computed here, never trusted from the including page — several
// pages (login.php, register.php, etc.) hardcode $cart_count = 2 before
// including this file, which is why the badge showed a fake number.
$cart_count = 0;
if (!empty($_SESSION['user_id'])) {
    try {
        $cartCountStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE user_id = :user_id");
        $cartCountStmt->execute(['user_id' => $_SESSION['user_id']]);
        $cart_count = (int) $cartCountStmt->fetchColumn();
    } catch (Throwable $e) {
        $cart_count = 0;
    }
}

$announcementEnabled = get_setting_bool($pdo, 'announcement_enabled', false);
$announcementText = get_setting($pdo, 'announcement_text', '');

$nav_links = [
    'home'     => ['label' => 'Home',     'href' => '/..'],
    'shop'     => ['label' => 'Shop',     'href' => '/pages/shop.php'],
    'services' => ['label' => 'Services', 'href' => '/pages/service.php'],
    'about'    => ['label' => 'About Us', 'href' => '/pages/aboutus.php'],
];

$navbarSearchProducts = [];
try {
    $navProductStmt = $pdo->query("SELECT p.product_id AS id, p.name, p.description, img.image_url AS image FROM products p LEFT JOIN product_images img ON p.product_id = img.product_id AND img.is_primary = 1 ORDER BY p.name ASC");
    $navbarSearchProducts = $navProductStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $navbarSearchProducts = [];
}

foreach ($navbarSearchProducts as &$navProduct) {
    $navProduct['id'] = (int)($navProduct['id'] ?? 0);
    $navProduct['name'] = (string)($navProduct['name'] ?? '');
    $navProduct['image'] = $navProduct['image'] ?? '';
}
unset($navProduct);
?>

<style>
    /* ===== Header component styles ===== */
    .cg-header-wrapper {
        background: var(--cg-bg);
        border-bottom: 1px solid var(--cg-hair);
    }

    /* ===== Announcement Bar ===== */
    .cg-announce {
        font-family: var(--font-body);
        font-size: 0.7rem;
        letter-spacing: 0.1em;
        color: var(--cg-text);
        border-bottom: 1px solid var(--cg-hair);
        text-transform: uppercase;
    }

    .cg-announce-links a {
        color: var(--cg-text);
        text-decoration: none;
        margin-left: 2rem;
        text-transform: none;
        letter-spacing: 0.05em;
    }

    .cg-announce-links a:hover {
        color: var(--cg-gold);
    }

    .cg-announce-text {
        color: var(--cg-gold);
        font-weight: 500;
    }

    /* ===== Main Navbar ===== */
    .cg-navbar {
        background: transparent !important;
    }

    .cg-navbar-brand {
        font-family: var(--font-display);
        font-weight: 500;
        font-size: 1.6rem;
        letter-spacing: 0.02em;
        color: var(--cg-text) !important;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .cg-navbar .nav-link {
        font-family: var(--font-display);
        font-size: 1.15rem;
        letter-spacing: 0.02em;
        color: var(--cg-text) !important;
        padding: 0.5rem 1.5rem !important;
        transition: color 0.3s ease;
    }

    .cg-navbar .nav-link:hover,
    .cg-navbar .nav-link.active {
        color: var(--cg-gold) !important;
    }

    /* ===== Mobile Toggler ===== */
    .cg-navbar-toggler {
        border-color: var(--cg-hair);
    }

    .cg-navbar-toggler-icon {
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(244,239,230,0.85)' stroke-width='2' stroke-linecap='round' stroke-miterlimit='10' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
    }

    :root[data-theme="light"] .cg-navbar-toggler-icon {
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(30,27,22,0.85)' stroke-width='2' stroke-linecap='round' stroke-miterlimit='10' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
    }

    /* ===== Navbar Icons ===== */
    .cg-btn-icon,
    .cg-icon-link {
        color: var(--cg-text);
        opacity: 0.9;
        transition: color 0.3s ease, opacity 0.3s ease;
    }

    .cg-btn-icon {
        background: none;
        border: none;
    }

    .cg-icon-link {
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .cg-btn-icon:hover,
    .cg-icon-link:hover,
    .cg-icon-link.active {
        color: var(--cg-gold);
        opacity: 1;
    }

    /* ===== Cart Badge ===== */
    .cg-cart-badge {
        font-family: var(--font-body);
        font-size: 0.6rem;
        font-weight: 600;
        background: var(--cg-gold) !important;
        color: var(--cg-ink-on-gold);
        transform: translate(-4px, -4px);
        padding: 0.25em 0.5em;
    }

    .cg-header-search {
        width: min(220px, 100%);
    }

    .cg-header-search .form-control {
        background: var(--cg-surface);
        border: 1px solid var(--cg-faint);
        color: var(--cg-text);
        padding-left: 2.5rem;
        height: 40px;
        border-radius: 999px;
        font-size: 0.9rem;
    }

    .cg-header-search .form-control::placeholder {
        color: var(--cg-muted);
    }

    .cg-header-search .form-control:focus {
        border-color: rgba(var(--cg-gold-rgb), 0.7);
        box-shadow: 0 0 0 0.2rem var(--cg-gold-soft);
        background: var(--cg-divider);
        color: var(--cg-text);
    }

    .cg-header-search .search-icon {
        position: absolute;
        left: 0.9rem;
        top: 50%;
        transform: translateY(-50%);
        width: 16px;
        height: 16px;
        color: rgba(var(--cg-text-rgb), 0.8);
    }

    .cg-search-results {
        position: absolute;
        top: calc(100% + 0.75rem);
        right: 0;
        width: min(360px, calc(100vw - 2rem));
        background: rgba(var(--cg-bg-rgb), 0.98);
        border: 1px solid var(--cg-hair);
        border-radius: 16px;
        box-shadow: 0 18px 40px rgba(0, 0, 0, calc(0.28 * var(--cg-shadow-strength)));
        overflow: hidden;
        z-index: 1060;
        display: none;
    }

    .cg-search-results.show {
        display: block;
    }

    .cg-search-result-item {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        width: 100%;
        padding: 0.7rem 0.8rem;
        border: none;
        background: transparent;
        text-align: left;
        color: var(--cg-text);
        text-decoration: none;
        transition: background 0.2s ease;
    }

    .cg-search-result-item:hover {
        background: var(--cg-surface);
        color: var(--cg-gold);
    }

    .cg-search-result-item img {
        width: 48px;
        height: 48px;
        object-fit: cover;
        border-radius: 10px;
        background: var(--cg-surface);
        border: 1px solid var(--cg-divider);
    }

    .cg-search-result-item small {
        display: block;
        color: var(--cg-text-soft);
        margin-top: 0.1rem;
    }

    .cg-search-empty {
        padding: 0.8rem 1rem;
        color: var(--cg-text-soft);
        font-size: 0.9rem;
    }
</style>

<div class="cg-header-wrapper sticky-top">

    <!-- Top Bar -->
    <div class="cg-announce py-2">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="cg-announce-text">
                <?php if ($announcementEnabled && trim($announcementText) !== ''): ?>
                    <?= htmlspecialchars($announcementText) ?>
                <?php endif; ?>
            </div>

            <div class="cg-announce-links d-none d-md-block">
                <a href="/pages/faq.php">FAQ</a>
                <a href="/pages/contactus.php">Contact Us</a>
            </div>
        </div>
    </div>

    <!-- Main Navbar -->
    <nav class="navbar cg-navbar navbar-expand-lg navbar-dark">
        <div class="container py-2">

            <!-- Brand -->
            <a class="cg-navbar-brand navbar-brand" href="/..">
                <img
                    src="/img/logo.png"
                    alt="Celestia Gems Logo"
                    width="32"
                    height="32"
                    style="object-fit: contain;"
                >
                Celestia Gems
            </a>

            <!-- Mobile Toggle -->
            <button
                class="navbar-toggler cg-navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#mainNav"
                aria-controls="mainNav"
                aria-expanded="false"
                aria-label="Toggle navigation"
            >
                <span class="navbar-toggler-icon cg-navbar-toggler-icon"></span>
            </button>

            <!-- Links & Icons -->
            <div class="collapse navbar-collapse" id="mainNav">

                <ul class="navbar-nav mx-auto gap-lg-3">
                    <?php foreach ($nav_links as $key => $link): ?>
                        <li class="nav-item">
                            <a
                                class="nav-link <?= $current_page === $key ? 'active' : '' ?>"
                                href="<?= htmlspecialchars($link['href']) ?>"
                            >
                                <?= htmlspecialchars($link['label']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="d-flex align-items-center justify-content-lg-end gap-4 mt-3 mt-lg-0">

                    <!-- Search -->
                    <form class="cg-header-search position-relative" method="GET" action="/pages/shop.php" role="search" id="navbarSearchForm">
                        <svg class="search-icon" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" fill="none" aria-hidden="true">
                            <circle cx="11" cy="11" r="7"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input
                            type="search"
                            id="navbarProductSearch"
                            name="search"
                            class="form-control"
                            placeholder="Search products"
                            aria-label="Search products"
                            autocomplete="off"
                        >
                        <div id="navbarSearchResults" class="cg-search-results" aria-live="polite"></div>
                    </form>

                    <!-- Theme toggle (shared with the admin topbar) -->
                    <?php include __DIR__ . '/theme_toggle.php'; ?>

                    <!-- Account -->
                    <a
                        href="/pages/account.php"
                        class="cg-icon-link <?= $current_page === 'account' ? 'active' : '' ?>"
                        aria-label="Account"
                    >
                        <svg
                            width="20"
                            height="20"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                        >
                            <circle cx="12" cy="8" r="4" />
                            <path d="M4 21c0-4 4-7 8-7s8 3 8 7" />
                        </svg>
                    </a>

                    <!-- Cart -->
                    <a
                        href="/pages/cart.php"
                        class="cg-icon-link position-relative <?= $current_page === 'cart' ? 'active' : '' ?>"
                        aria-label="Cart"
                    >
                        <svg
                            width="20"
                            height="20"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                        >
                            <path d="M6 6h15l-1.5 9h-12z" />
                            <path d="M6 6L4 3H2" />
                            <circle cx="9" cy="20" r="1" />
                            <circle cx="17" cy="20" r="1" />
                        </svg>

                        <?php if ($cart_count > 0): ?>
                            <span class="badge rounded-pill cg-cart-badge position-absolute top-0 start-100">
                                <?= (int) $cart_count ?>
                            </span>
                        <?php endif; ?>
                    </a>

                </div>
            </div>
        </div>
    </nav>
</div>

<script>
    (function() {
        var productData = <?php echo json_encode($navbarSearchProducts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var searchInput = document.getElementById('navbarProductSearch');
        var searchForm = document.getElementById('navbarSearchForm');
        var resultsBox = document.getElementById('navbarSearchResults');

        if (!searchInput || !resultsBox || !productData) return;

        function getProductImage(product) {
            if (!product.image) return '/img/default-product.png';
            return product.image.indexOf('/img/') === 0 || product.image.indexOf('http') === 0 ? product.image : '/img/' + product.image;
        }

        function renderResults(query) {
            var q = (query || '').trim().toLowerCase();
            if (!q) {
                resultsBox.classList.remove('show');
                resultsBox.innerHTML = '';
                return;
            }

            var matches = productData.filter(function(product) {
                var name = (product.name || '').toLowerCase();
                var description = (product.description || '').toLowerCase();
                return name.indexOf(q) !== -1 || description.indexOf(q) !== -1;
            }).slice(0, 6);

            if (!matches.length) {
                resultsBox.innerHTML = '<div class="cg-search-empty">No products match your search.</div>';
                resultsBox.classList.add('show');
                return;
            }

            resultsBox.innerHTML = matches.map(function(product) {
                return '<a class="cg-search-result-item" href="/pages/product.php?id=' + product.id + '">' +
                    '<img src="' + getProductImage(product) + '" alt="' + (product.name || 'Product') + '">' +
                    '<span><strong>' + (product.name || 'Product') + '</strong><small>View product</small></span>' +
                    '</a>';
            }).join('');
            resultsBox.classList.add('show');
        }

        searchInput.addEventListener('input', function() {
            renderResults(this.value);
        });

        searchInput.addEventListener('focus', function() {
            renderResults(this.value);
        });

        document.addEventListener('click', function(event) {
            if (!searchForm.contains(event.target)) {
                resultsBox.classList.remove('show');
            }
        });

        searchForm.addEventListener('submit', function(event) {
            if (!searchInput.value.trim()) {
                event.preventDefault();
                resultsBox.classList.remove('show');
            }
        });
    })();
</script>