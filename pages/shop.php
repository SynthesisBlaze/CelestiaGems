<?php
session_start();
$initialSearch = isset($_GET['search']) ? trim((string) $_GET['search']) : (isset($_GET['cat']) ? trim((string) $_GET['cat']) : '');

// Include the database connection file
require_once __DIR__ . '/../functions/db_connection.php';

$userWishlist = [];
if (isset($_SESSION['user_id'])) {
    $wStmt = $pdo->prepare("SELECT product_id FROM wishlist_items WHERE user_id = ?");
    $wStmt->execute([$_SESSION['user_id']]);
    $userWishlist = $wStmt->fetchAll(PDO::FETCH_COLUMN);
}

// 1. Dynamic Database Category Fetch
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
$rawCategories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

$categoryTree = [];
foreach ($rawCategories as $row) {
    $mainId = $row['main_id'];
    if (!isset($categoryTree[$mainId])) {
        $mainName = ($row['main_name'] === 'Gemstones & Jewelry Services') ? 'Gemstones' : $row['main_name'];

        $categoryTree[$mainId] = [
            'id'   => (int)$mainId,
            'main' => $mainName,
            'subs' => []
        ];
    }
    $categoryTree[$mainId]['subs'][] = [
        'id'   => (int)$row['sub_id'],
        'name' => $row['sub_name']
    ];
}
$categoryTreeData = array_values($categoryTree);

// 2. Dynamic Database Product Fetch with Primary Image
$prodStmt = $pdo->query("
    SELECT 
        p.product_id AS id,
        p.name,
        p.price,
        p.description,
        p.category_id AS sub_id,
        sub.name AS sub_name,
        main.name AS main_name,
        img.image_url AS image
    FROM products p
    INNER JOIN categories sub ON p.category_id = sub.category_id
    LEFT JOIN categories main ON sub.parent_id = main.category_id
    LEFT JOIN product_images img ON p.product_id = img.product_id AND img.is_primary = 1
    ORDER BY p.product_id ASC
");
$productsData = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

// Format types and determine vector fallbacks
foreach ($productsData as &$p) {
    $p['id']         = (int)$p['id'];
    $p['sub_id']     = (int)$p['sub_id'];
    $p['price']      = (float)$p['price'];
    $p['wishlisted'] = in_array($p['id'], $userWishlist);

    $nameLower = strtolower($p['name']);
    if (strpos($nameLower, 'ring') !== false) {
        $p['icon'] = 'ring';
    } elseif (strpos($nameLower, 'necklace') !== false) {
        $p['icon'] = 'necklace';
    } elseif (strpos($nameLower, 'earring') !== false) {
        $p['icon'] = 'earring';
    } elseif (strpos($nameLower, 'set') !== false) {
        $p['icon'] = 'set';
    } else {
        $p['icon'] = 'gem';
    }
}
unset($p);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop | The Atelier Collection</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400;1,500;1,600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../styles/shop.css?v=<?php echo time(); ?>" rel="stylesheet">
</head>

<body>
    <?php $current_page = 'shop'; ?>
    <?php include __DIR__ . '/../components/navbar.php'; ?>
    <div class="shop-page">
        <div class="container py-5">

            <header class="text-center shop-header mb-4 mb-md-5">
                <span class="eyebrow d-inline-flex align-items-center gap-2 mb-3">The Atelier Collection</span>
                <h1 class="shop-title">Shop <em>Fine &amp; Bridal</em> Jewelry</h1>
                <p class="shop-subtitle mx-auto">Hand-finished rings, necklaces, and gemstones — filter by category or sort to find your piece.</p>
            </header>

            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 shop-toolbar mb-4">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <button class="btn btn-gold-outline d-lg-none d-inline-flex align-items-center gap-2" type="button"
                        data-bs-toggle="offcanvas" data-bs-target="#filterOffcanvas" aria-controls="filterOffcanvas">
                        <svg width="14" height="14" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" fill="none">
                            <line x1="4" y1="7" x2="20" y2="7" />
                            <line x1="4" y1="12" x2="20" y2="12" />
                            <line x1="4" y1="17" x2="20" y2="17" />
                            <circle cx="9" cy="7" r="1.6" fill="currentColor" stroke="none" />
                            <circle cx="16" cy="12" r="1.6" fill="currentColor" stroke="none" />
                            <circle cx="11" cy="17" r="1.6" fill="currentColor" stroke="none" />
                        </svg>
                        Filters
                        <span class="badge rounded-pill filter-badge" id="filterCountBadge" hidden>0</span>
                    </button>
                    <p class="mb-0 result-count" id="resultCount" aria-live="polite"><strong>0</strong> items</p>

                    <!-- Search Input placed directly beside the item counter -->
                    <div class="position-relative">
                        <svg class="search-icon" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" fill="none">
                            <circle cx="11" cy="11" r="7"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input type="text" class="form-control shop-search" id="searchInput" placeholder="Search collection..." aria-label="Search products">
                    </div>
                </div>

                <div class="d-flex align-items-center gap-3">
                    <select class="form-select shop-select" id="sortSelect" aria-label="Sort products">
                        <option value="featured">Sort: Featured</option>
                        <option value="price-asc">Price: Low to High</option>
                        <option value="price-desc">Price: High to Low</option>
                        <option value="name-asc">Name: A – Z</option>
                        <option value="name-desc">Name: Z – A</option>
                    </select>
                </div>
            </div>

            <div class="row g-4">
                <!-- Desktop Sidebar Filters -->
                <aside class="col-lg-3 d-none d-lg-block">
                    <div class="filters-panel">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h2 class="filters-title mb-0">Filters</h2>
                            <button class="btn btn-link clear-all p-0" id="clearAllBtn" disabled>Clear all</button>
                        </div>
                        <div class="accordion accordion-flush" id="filterAccordionDesktop"></div>
                    </div>
                </aside>

                <!-- Product Grid -->
                <section class="col-lg-9">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                        <h2 class="category-display-title mb-0" id="categoryTitle">All Collection</h2>
                    </div>
                    <div class="active-filters mb-3 d-flex flex-wrap gap-2" id="activeFiltersDesktop"></div>
                    <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-3 g-md-4" id="productsGrid"></div>
                </section>
            </div>

        </div>
    </div>

    <!-- Mobile Filter Offcanvas -->
    <div class="offcanvas offcanvas-bottom shop-offcanvas" tabindex="-1" id="filterOffcanvas" aria-labelledby="filterOffcanvasLabel">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title" id="filterOffcanvasLabel">Filter Collection</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <div class="accordion accordion-flush" id="filterAccordionMobile"></div>
        </div>
        <div class="offcanvas-footer d-flex gap-2 p-3">
            <button class="btn btn-link clear-all" id="clearAllBtnMobile" disabled>Clear all</button>
            <button class="btn btn-gold flex-fill" data-bs-dismiss="offcanvas" id="drawerApplyBtn">Show results</button>
        </div>
    </div>

    <div class="signin-modal-backdrop" id="signinPromptBackdrop" hidden>
        <div class="signin-modal" role="dialog" aria-modal="true" aria-labelledby="signinPromptTitle">
            <button type="button" class="signin-modal-close" id="signinPromptClose" aria-label="Close sign in prompt">&times;</button>
            <div class="signin-modal-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                    <circle cx="12" cy="7" r="4" />
                </svg>
            </div>
            <p class="signin-modal-eyebrow">Member access</p>
            <h3 id="signinPromptTitle">Please sign in to save this piece</h3>
            <p class="signin-modal-copy">You’ll need an account to add items to your wishlist.</p>
            <div class="signin-modal-actions">
                <button type="button" class="btn signin-modal-secondary" id="signinPromptCancel">Continue browsing</button>
                <button type="button" class="btn signin-modal-primary" id="signinPromptSignIn">Sign In</button>
            </div>
        </div>
    </div>

    <div class="added-toast" id="wishlistToast" aria-live="polite" aria-atomic="true" hidden>
        <div class="added-toast-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" fill="#C9A15C">
                <path d="M12 21s-7.5-4.8-10-9.3C0.3 8.1 2 4.5 5.6 4.1 8 3.9 10.2 5.3 12 8c1.8-2.7 4-4.1 6.4-3.9 3.6.4 5.3 4 3.6 7.6C19.5 16.2 12 21 12 21z" />
            </svg>
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

            /* Hydrate Database JSON into JS */
            var rawProducts = <?php echo json_encode($productsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var categoryTree = <?php echo json_encode($categoryTreeData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var initialSearch = <?php echo json_encode($initialSearch, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

            var isLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
            var $signinBackdrop = $('#signinPromptBackdrop');

            $('#signinPromptClose, #signinPromptCancel').on('click', function() {
                $signinBackdrop.attr('hidden', 'hidden');
            });
            $('#signinPromptSignIn').on('click', function() {
                window.location.href = 'login.php?return_url=' + encodeURIComponent(window.location.pathname + window.location.search);
            });
            var products = rawProducts;

            /* Default Vector Icons */
            var icons = {
                ring: '<svg viewBox="0 0 48 48"><circle cx="24" cy="30" r="12" stroke-width="1.6"/><path d="M24 18 L18 10 L24 5 L30 10 Z" stroke-width="1.6" stroke-linejoin="round"/><path d="M18 10 L24 14 L30 10" stroke-width="1.4"/></svg>',
                necklace: '<svg viewBox="0 0 48 48"><path d="M8 8c0 14 8 22 16 22s16-8 16-22" stroke-width="1.6"/><path d="M24 30 L18 38 L24 44 L30 38 Z" stroke-width="1.6" stroke-linejoin="round"/></svg>',
                earring: '<svg viewBox="0 0 48 48"><circle cx="24" cy="12" r="5" stroke-width="1.6"/><path d="M24 17 v8" stroke-width="1.4"/><path d="M24 25 L17 35 L24 43 L31 35 Z" stroke-width="1.6" stroke-linejoin="round"/></svg>',
                set: '<svg viewBox="0 0 48 48"><path d="M24 6 L30 15 L24 22 L18 15 Z" stroke-width="1.5" stroke-linejoin="round"/><circle cx="12" cy="34" r="7" stroke-width="1.5"/><circle cx="36" cy="34" r="7" stroke-width="1.5"/></svg>',
                gem: '<svg viewBox="0 0 48 48"><path d="M10 16 L24 6 L38 16 L24 43 Z" stroke-width="1.6" stroke-linejoin="round"/><path d="M10 16 L38 16 M17 16 L24 43 M31 16 L24 43 M17 16 L24 6 M31 16 L24 6" stroke-width="1"/></svg>'
            };

            /* Database Price Buckets in PHP */
            var priceBuckets = [{
                    key: "0-10000",
                    label: "Under ₱10,000",
                    test: function(p) {
                        return p < 10000;
                    }
                },
                {
                    key: "10000-50000",
                    label: "₱10,000 – ₱50,000",
                    test: function(p) {
                        return p >= 10000 && p <= 50000;
                    }
                },
                {
                    key: "50000-150000",
                    label: "₱50,000 – ₱150,000",
                    test: function(p) {
                        return p > 50000 && p <= 150000;
                    }
                },
                {
                    key: "150000-plus",
                    label: "Over ₱150,000",
                    test: function(p) {
                        return p > 150000;
                    }
                }
            ];

            function money(n) {
                return "₱" + n.toLocaleString("en-PH", {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function priceBucketByKey(key) {
                return priceBuckets.filter(function(b) {
                    return b.key === key;
                })[0];
            }

            function subCount(subId) {
                return products.filter(function(p) {
                    return p.sub_id === subId;
                }).length;
            }

            function getSubNameById(subId) {
                for (var i = 0; i < categoryTree.length; i++) {
                    for (var j = 0; j < categoryTree[i].subs.length; j++) {
                        if (categoryTree[i].subs[j].id === subId) return categoryTree[i].subs[j].name;
                    }
                }
                return "Category";
            }

            /* State management using numeric category IDs */
            var state = {
                subs: [],
                prices: [],
                sort: "featured",
                searchQuery: initialSearch.toLowerCase().trim()
            };

            if (initialSearch) {
                $("#searchInput").val(initialSearch);
            }

            function inArr(arr, v) {
                return arr.indexOf(v) !== -1;
            }

            function toggleArr(arr, v, on) {
                var i = arr.indexOf(v);
                if (on && i === -1) arr.push(v);
                if (!on && i !== -1) arr.splice(i, 1);
            }

            /* Accordions built dynamically from Database structure */
            function buildAccordionHtml(prefix) {
                var html = "";
                categoryTree.forEach(function(cat, ci) {
                    var collapseId = "collapse-" + prefix + "-" + cat.id;
                    html += '' +
                        '<div class="accordion-item">' +
                        '<h2 class="accordion-header">' +
                        '<button class="accordion-button' + (ci === 0 ? "" : " collapsed") + '" type="button" data-bs-toggle="collapse" data-bs-target="#' + collapseId + '" aria-expanded="' + (ci === 0 ? "true" : "false") + '" aria-controls="' + collapseId + '">' +
                        cat.main +
                        "</button>" +
                        "</h2>" +
                        '<div id="' + collapseId + '" class="accordion-collapse collapse' + (ci === 0 ? " show" : "") + '" data-bs-parent="#filterAccordion' + (prefix === "d" ? "Desktop" : "Mobile") + '">' +
                        '<div class="accordion-body">' +
                        cat.subs.map(function(sub) {
                            var id = prefix + "-sub-" + sub.id;
                            return '' +
                                '<div class="form-check">' +
                                '<input class="form-check-input" type="checkbox" data-type="sub" data-value="' + sub.id + '" id="' + id + '">' +
                                '<label class="form-check-label" for="' + id + '">' + sub.name + '<span class="form-check-count">(' + subCount(sub.id) + ")</span></label>" +
                                "</div>";
                        }).join("") +
                        "</div>" +
                        "</div>" +
                        "</div>";
                });

                var priceCollapseId = "collapse-" + prefix + "-price";
                html += '' +
                    '<div class="accordion-item">' +
                    '<h2 class="accordion-header">' +
                    '<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#' + priceCollapseId + '" aria-expanded="false" aria-controls="' + priceCollapseId + '">' +
                    "Price Range" +
                    "</button>" +
                    "</h2>" +
                    '<div id="' + priceCollapseId + '" class="accordion-collapse collapse" data-bs-parent="#filterAccordion' + (prefix === "d" ? "Desktop" : "Mobile") + '">' +
                    '<div class="accordion-body">' +
                    priceBuckets.map(function(b) {
                        var id = prefix + "-price-" + b.key;
                        return '' +
                            '<div class="form-check">' +
                            '<input class="form-check-input" type="checkbox" data-type="price" data-value="' + b.key + '" id="' + id + '">' +
                            '<label class="form-check-label" for="' + id + '">' + b.label + "</label>" +
                            "</div>";
                    }).join("") +
                    "</div>" +
                    "</div>" +
                    "</div>";

                return html;
            }

            $("#filterAccordionDesktop").html(buildAccordionHtml("d"));
            $("#filterAccordionMobile").html(buildAccordionHtml("m"));

            function syncCheckboxes() {
                $('input[data-type="sub"]').each(function() {
                    var val = parseInt($(this).data("value"), 10);
                    $(this).prop("checked", inArr(state.subs, val));
                });
                $('input[data-type="price"]').each(function() {
                    $(this).prop("checked", inArr(state.prices, $(this).data("value").toString()));
                });
            }

            $("#filterAccordionDesktop, #filterAccordionMobile").on("change", 'input[type="checkbox"]', function() {
                var $t = $(this);
                var type = $t.data("type");
                var rawVal = $t.data("value");
                var value = type === "sub" ? parseInt(rawVal, 10) : rawVal.toString();
                var arr = type === "sub" ? state.subs : state.prices;

                toggleArr(arr, value, $t.is(":checked"));
                syncCheckboxes();
                render();
            });

            $("#searchInput").on("input", function() {
                state.searchQuery = $(this).val().toLowerCase().trim();
                render();
            });

            function updateCategoryTitle() {
                if (state.subs.length === 1) {
                    $("#categoryTitle").text(getSubNameById(state.subs[0]));
                } else if (state.subs.length > 1) {
                    $("#categoryTitle").text("Selected Categories (" + state.subs.length + ")");
                } else {
                    $("#categoryTitle").text("All Collection");
                }
            }

            function renderChips() {
                var chips = [];
                state.subs.forEach(function(v) {
                    chips.push({
                        type: "sub",
                        value: v,
                        label: getSubNameById(v)
                    });
                });
                state.prices.forEach(function(v) {
                    chips.push({
                        type: "price",
                        value: v,
                        label: priceBucketByKey(v).label
                    });
                });

                var html = chips.map(function(c) {
                    return '<span class="chip">' + c.label +
                        '<button type="button" aria-label="Remove ' + c.label + ' filter" data-remove-type="' + c.type + '" data-remove-value="' + c.value + '">&times;</button>' +
                        "</span>";
                }).join("");

                $("#activeFiltersDesktop").html(html);

                var hasFilters = chips.length > 0;
                $("#clearAllBtn, #clearAllBtnMobile").prop("disabled", !hasFilters);
                $("#filterCountBadge").prop("hidden", !hasFilters).text(chips.length);
            }

            $("#activeFiltersDesktop").on("click", "button[data-remove-type]", function() {
                var type = $(this).data("remove-type");
                var rawVal = $(this).data("remove-value");
                var value = type === "sub" ? parseInt(rawVal, 10) : rawVal.toString();

                toggleArr(type === "sub" ? state.subs : state.prices, value, false);
                syncCheckboxes();
                render();
            });

            function clearAll() {
                state.subs = [];
                state.prices = [];
                $("#searchInput").val("");
                state.searchQuery = "";
                syncCheckboxes();
                render();
            }
            $("#clearAllBtn, #clearAllBtnMobile").on("click", clearAll);

            $("#sortSelect").on("change", function() {
                state.sort = $(this).val();
                render();
            });

            function applySort(list) {
                var arr = list.slice();
                switch (state.sort) {
                    case "price-asc":
                        return arr.sort(function(a, b) {
                            return a.price - b.price;
                        });
                    case "price-desc":
                        return arr.sort(function(a, b) {
                            return b.price - a.price;
                        });
                    case "name-asc":
                        return arr.sort(function(a, b) {
                            return a.name.localeCompare(b.name);
                        });
                    case "name-desc":
                        return arr.sort(function(a, b) {
                            return b.name.localeCompare(a.name);
                        });
                    default:
                        return arr;
                }
            }

            function filteredProducts() {
                return products.filter(function(p) {
                    var subOK = state.subs.length === 0 || inArr(state.subs, p.sub_id);
                    var priceOK = state.prices.length === 0 || state.prices.some(function(key) {
                        return priceBucketByKey(key).test(p.price);
                    });
                    var searchOK = state.searchQuery === "" ||
                        p.name.toLowerCase().indexOf(state.searchQuery) !== -1 ||
                        p.sub_name.toLowerCase().indexOf(state.searchQuery) !== -1;

                    return subOK && priceOK && searchOK;
                });
            }

            /* Render Dynamic Cards */
            function renderCard(p) {
                var mediaContent = p.image ?
                    '<img src="/img/' + p.image + '" alt="' + p.name + '" style="width: 100%; height: 100%; object-fit: cover; opacity: 0.95;">' :
                    icons[p.icon];

                return '' +
                    '<div class="col d-flex align-items-stretch">' +
                    '<div class="card product-card w-100" data-id="' + p.id + '" style="cursor:pointer;">' +
                    '<div class="product-media">' +
                    mediaContent +
                    '<button type="button" class="btn wishlist-btn" aria-pressed="' + p.wishlisted + '" aria-label="' + (p.wishlisted ? "Remove from wishlist" : "Add to wishlist") + '" data-wishlist="' + p.id + '">' +
                    '<svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" fill="' + (p.wishlisted ? "var(--cg-gold)" : "none") + '"><path d="M12 21s-7.5-4.8-10-9.3C0.3 8.1 2 4.5 5.6 4.1 8 3.9 10.2 5.3 12 8c1.8-2.7 4-4.1 6.4-3.9 3.6.4 5.3 4 3.6 7.6C19.5 16.2 12 21 12 21z"/></svg>' +
                    "</button>" +
                    "</div>" +
                    '<div class="card-body product-info d-flex flex-column">' +
                    '<div>' +
                    '<span class="product-sub d-block mb-1">' + p.sub_name + "</span>" +
                    '<h3 class="product-name card-title mb-2">' + p.name + "</h3>" +
                    "</div>" +
                    '<div class="mt-auto pt-2 d-flex flex-column gap-2">' +
                    '<span class="product-price">' + money(p.price) + "</span>" +
                    "</div>" +
                    "</div>" +
                    "</div>" +
                    "</div>";
            }

            function render() {
                updateCategoryTitle();
                renderChips();
                var results = applySort(filteredProducts());

                $("#resultCount").html("<strong>" + results.length + "</strong> item" + (results.length === 1 ? "" : "s"));

                if (results.length === 0) {
                    $("#productsGrid").html(
                        '<div class="col-12">' +
                        '<div class="empty-state">' +
                        '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke-width="1.4"/><line x1="18" y1="18" x2="23" y2="23" stroke-width="1.4"/></svg>' +
                        "<h3>No items match your filters</h3>" +
                        "<p>Try widening your price range, clearing a category filter, or altering your search.</p>" +
                        '<button type="button" class="btn" id="emptyResetBtn">Clear all filters</button>' +
                        "</div>" +
                        "</div>"
                    );
                    $("#emptyResetBtn").on("click", clearAll);
                    return;
                }

                $("#productsGrid").html(results.map(renderCard).join(""));
            }

            /* Navigate to product.php when a card is clicked (but not its buttons) */
            $("#productsGrid").on("click", ".product-card", function(e) {
                if ($(e.target).closest(".wishlist-btn").length) return;
                var id = $(this).data("id");
                if (id) window.location.href = "product.php?id=" + id;
            });

            $("#productsGrid").on("click", "[data-wishlist]", function(e) {
                e.preventDefault();
                e.stopPropagation(); // Prevents triggering the card link

                if (!isLoggedIn) {
                    $signinBackdrop.removeAttr('hidden');
                    return;
                }

                var id = Number($(this).data("wishlist"));
                var p = products.filter(function(p) {
                    return p.id === id;
                })[0];

                if (p) {
                    var $btn = $(this);
                    var wasWishlisted = p.wishlisted;

                    // Optimistic UI toggle
                    p.wishlisted = !wasWishlisted;
                    $btn.attr("aria-pressed", p.wishlisted);
                    $btn.attr("aria-label", p.wishlisted ? "Remove from wishlist" : "Add to wishlist");
                    $btn.find("svg").attr("fill", p.wishlisted ? "var(--cg-gold)" : "none");

                    // AJAX request
                    $.ajax({
                        url: '../functions/wishlist-toggle.php',
                        type: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({
                            product_id: id
                        }),
                        success: function(res) {
                            if (res && res.success) {
                                var msg = res.status === 'added' ? 'Added to your wishlist.' : 'Removed from your wishlist.';
                                showWishlistToast(msg);
                            }
                        },
                        error: function() {
                            // Revert on error
                            p.wishlisted = wasWishlisted;
                            $btn.attr("aria-pressed", p.wishlisted);
                            $btn.attr("aria-label", p.wishlisted ? "Remove from wishlist" : "Add to wishlist");
                            $btn.find("svg").attr("fill", p.wishlisted ? "var(--cg-gold)" : "none");
                            showWishlistToast("Unable to update wishlist.");
                        }
                    });
                }
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

            render();
        });
    </script>
</body>

</html>