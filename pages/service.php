<?php
session_status() === PHP_SESSION_NONE && session_start();

$initialCat = isset($_GET['cat']) ? $_GET['cat'] : (isset($_GET['category']) ? $_GET['category'] : 'all');
if ($initialCat !== 'all' && is_numeric($initialCat)) {
    $initialCat = (int)$initialCat;
}

// Optional keyword filter, e.g. ?q=repair or ?q=apprais|valu ("|" = or).
// Used by the footer's service links.
$initialQuery = mb_strtolower(mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60));

require_once __DIR__ . '/../functions/db_connection.php';

// Used below to decide where the Book buttons send the customer.
$isLoggedIn = isset($_SESSION['user_id']);

$products = [];

try {
    // Dynamic JOIN query replacing the hardcoded array
    $stmt = $pdo->query("
        SELECT 
            s.service_id as id, 
            s.category_id, 
            s.name, 
            s.description, 
            s.base_price as price,
            c.name as category_name
        FROM services s
        JOIN categories c ON s.category_id = c.category_id
    ");
    $dbProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dbProducts as $row) {
        $products[] = [
            "id" => (int)$row['id'],
            "category_id" => (int)$row['category_id'],
            "category_name" => $row['category_name'],
            "name" => $row['name'],
            "description" => $row['description'],
            "price" => (float)$row['price'],
            "is_service" => ($row['category_id'] == 12) ? 1 : 0
        ];
    }
} catch (Exception $e) {
    error_log("Database Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atelier Services — Custom Jewelry & Professional Care</title>

    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,500;1,600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="../styles/service.css?v=<?php echo time(); ?>" rel="stylesheet">
    <link href="../styles/booking.css?v=<?php echo time(); ?>" rel="stylesheet">
</head>

<body>
    <?php $current_page = 'services'; ?>
    <?php include __DIR__ . '/../components/navbar.php'; ?>

    <!-- Wrapper scoping all service page styles -->
    <div class="service-page-wrapper">
        <main class="container py-5">

            <!-- ============ HERO ============ -->
            <section class="row align-items-center mb-5 pb-4 border-bottom border-secondary border-opacity-25">
                <div class="col-lg-8">
                    <p class="eyebrow text-gold fw-semibold fs-5 mb-1 text-uppercase tracking-wide">Atelier Services</p>
                    <h1 class="display-4 fw-medium mb-3">Made to your hand,<br><em class="text-gold">kept</em> for a lifetime.</h1>
                    <p class="lead text-secondary mb-4 col-md-10">From a ring designed around one idea to the quiet maintenance that keeps a family piece wearable for the next generation — every service here is performed by hand, on premises, by our resident jewelers.</p>
                    <div class="d-flex gap-4 flex-wrap">
                        <div class="border-start border-2 border-warning ps-3"><strong class="d-block fs-4 text-gold">10</strong><span class="text-muted small text-uppercase">Services Offered</span></div>
                        <div class="border-start border-2 border-warning ps-3"><strong class="d-block fs-4 text-gold">1:1</strong><span class="text-muted small text-uppercase">Private Consultation</span></div>
                        <div class="border-start border-2 border-warning ps-3"><strong class="d-block fs-4 text-gold">100%</strong><span class="text-muted small text-uppercase">In-house Craftsmanship</span></div>
                    </div>
                </div>
                <div class="col-lg-4 d-none d-lg-block hero-art text-center">
                    <svg class="facet-art w-75" viewBox="0 0 320 320" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <polygon points="160,20 240,90 210,180 110,180 80,90" stroke="#C9A15C" stroke-width="1" opacity="0.55" />
                        <polygon points="160,20 210,180 160,300 110,180" stroke="#C9A15C" stroke-width="1" opacity="0.35" />
                        <line x1="160" y1="20" x2="160" y2="300" stroke="#C9A15C" stroke-width="0.6" opacity="0.3" />
                        <line x1="80" y1="90" x2="240" y2="90" stroke="#C9A15C" stroke-width="0.6" opacity="0.3" />
                        <line x1="110" y1="180" x2="210" y2="180" stroke="#C9A15C" stroke-width="0.6" opacity="0.45" />
                        <circle cx="160" cy="150" r="118" stroke="#C9A15C" stroke-width="0.5" opacity="0.15" />
                    </svg>
                </div>
            </section>

            <!-- ============ TOOLBAR ============ -->
            <div class="sticky-top bg-light bg-opacity-75 py-3 mb-4 z-2" style="backdrop-filter: blur(10px);">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">

                    <!-- Desktop Chips -->
                    <div class="d-none d-md-flex gap-2 flex-wrap" id="categoryChips"></div>

                    <!-- Mobile Filter Button -->
                    <button class="btn btn-outline-dark d-md-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#filterSheet" aria-controls="filterSheet">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-2">
                            <line x1="4" y1="7" x2="20" y2="7" />
                            <line x1="7" y1="12" x2="17" y2="12" />
                            <line x1="10" y1="17" x2="14" y2="17" />
                        </svg>
                        Filter & Sort
                    </button>

                    <div class="d-flex align-items-center gap-2 ms-auto">
                        <label for="sort" class="d-none d-sm-block text-muted small mb-0 text-uppercase tracking-wide">Sort</label>
                        <select id="sort" class="form-select form-select-sm w-auto border-secondary">
                            <option value="featured">Featured</option>
                            <option value="price-asc">Price: Low to High</option>
                            <option value="price-desc">Price: High to Low</option>
                            <option value="name-asc">Name: A–Z</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ============ RESULTS META ============ -->
            <div class="results-meta">
                <span>Showing <strong id="countShown">10</strong> of <strong id="countTotal">10</strong> services</span>
                <span id="priceFilterLabel"></span>
            </div>

            <!-- ============ GRID ============ -->
            <div class="row g-4 mb-5" id="grid" aria-live="polite">
                <!-- Populated via jQuery -->
            </div>

            <!-- Empty State -->
            <div class="text-center py-5 d-none border rounded bg-white shadow-sm" id="emptyState">
                <h3 class="fw-light text-gold font-serif mb-3">No pieces match your selection</h3>
                <p class="text-muted">Try clearing a filter to see the full collection of services.</p>
                <button class="btn btn-dark mt-2 px-4 rounded-pill" id="clearFiltersBtn">Clear filters</button>
            </div>

            <!-- ============ CONSULT BANNER ============ -->
            <section class="bg-dark text-white p-5 text-center mt-5 rounded">
                <h3 class="mb-3 text-gold font-serif fs-2">Not sure where to start?</h3>
                <p class="mb-4 text-white-50 mx-auto" style="max-width: 600px;">Book a private, no-obligation consultation and describe the piece you have in mind — or bring in what needs care.</p>
                <button class="btn btn-outline-light px-4 py-2 rounded-pill text-uppercase tracking-wide" style="font-size: 0.85rem;" id="bookConsultBtn">Book a Consultation</button>
            </section>

        </main>

        <!-- ============ MOBILE FILTER OFFCANVAS ============ -->
        <div class="offcanvas offcanvas-bottom h-75 rounded-top-4" tabindex="-1" id="filterSheet" aria-labelledby="filterSheetLabel">
            <div class="offcanvas-header border-bottom">
                <h5 class="offcanvas-title font-serif" id="filterSheetLabel">Filter & Sort</h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body">
                <h6 class="text-muted text-uppercase small mb-3 mt-2 tracking-wide">Category</h6>
                <div class="d-flex flex-wrap gap-2 mb-4" id="categoryChipsMobile"></div>

                <h6 class="text-muted text-uppercase small mb-3 tracking-wide">Price range</h6>
                <div class="d-flex flex-wrap gap-2 mb-4" id="priceChipsMobile"></div>

                <h6 class="text-muted text-uppercase small mb-3 tracking-wide">Sort by</h6>
                <div class="d-flex flex-wrap gap-2 mb-4" id="sortChipsMobile"></div>
            </div>
            <div class="offcanvas-footer p-3 border-top bg-light">
                <button class="btn btn-dark w-100 rounded-pill py-2" data-bs-dismiss="offcanvas">Show results</button>
            </div>
        </div>
    </div> <!-- /service-page-wrapper -->

    <!-- Global Footer -->
    <?php include __DIR__ . '/../components/footer.php'; ?>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        $(document).ready(function() {
            const products = <?php echo json_encode($products); ?>;
            const isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;

            $("#countTotal").text(products.length);

            function goToBooking(serviceId) {
                const target = "booking.php" + (serviceId ? ("?service_id=" + serviceId) : "");
                if (isLoggedIn) {
                    window.location.href = target;
                } else {
                    window.location.href = "login.php?redirect=" + encodeURIComponent(target);
                }
            }

            $(document).on("click", ".cta-btn", function() {
                goToBooking($(this).data("id"));
            });

            $("#bookConsultBtn").on("click", function() {
                goToBooking(null);
            });

            const CATEGORY_OPTIONS = [{
                    key: "all",
                    label: "All Services"
                },
                {
                    key: 11,
                    label: "Custom Jewelry"
                },
                {
                    key: 12,
                    label: "Care & Professional"
                }
            ];
            const PRICE_OPTIONS = [{
                    key: "all",
                    label: "Any Price"
                },
                {
                    key: "under2k",
                    label: "Under ₱2,000"
                },
                {
                    key: "2k-50k",
                    label: "₱2,000 – ₱50,000"
                },
                {
                    key: "over50k",
                    label: "₱50,000+"
                }
            ];
            const SORT_OPTIONS = [{
                    key: "featured",
                    label: "Featured"
                },
                {
                    key: "price-asc",
                    label: "Price: Low to High"
                },
                {
                    key: "price-desc",
                    label: "Price: High to Low"
                },
                {
                    key: "name-asc",
                    label: "Name: A–Z"
                }
            ];

            const state = {
                category: <?php echo json_encode($initialCat, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                price: "all",
                sort: "featured",
                q: <?php echo json_encode($initialQuery, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
            };

            function matchesQuery(p) {
                if (!state.q) return true;
                const text = ((p.name || '') + ' ' + (p.description || '')).toLowerCase();
                return state.q.split('|').map(t => t.trim()).filter(Boolean).some(t => text.includes(t));
            }

            const fmt = (n) => "₱" + parseFloat(n).toLocaleString("en-PH", {
                minimumFractionDigits: n % 1 === 0 ? 0 : 2
            });

            function matchesPrice(p, key) {
                if (key === "all") return true;
                if (key === "under2k") return p < 2000;
                if (key === "2k-50k") return p >= 2000 && p <= 50000;
                if (key === "over50k") return p > 50000;
                return true;
            }

            function getFiltered() {
                let list = products.filter(p =>
                    (state.category === "all" || p.category_id == state.category) &&
                    matchesPrice(p.price, state.price) &&
                    matchesQuery(p)
                );
                // If a keyword link matches nothing (e.g. a service was renamed),
                // fall back to the whole category instead of an empty page.
                if (state.q && list.length === 0) {
                    state.q = "";
                    return getFiltered();
                }
                if (state.sort === "price-asc") list.sort((a, b) => a.price - b.price);
                else if (state.sort === "price-desc") list.sort((a, b) => b.price - a.price);
                else if (state.sort === "name-asc") list.sort((a, b) => a.name.localeCompare(b.name));
                return list;
            }

            function cardHTML(p) {
                const badge = p.is_service ?
                    '<span class="badge bg-secondary">Professional Service</span>' :
                    '<span class="badge bg-gold">Bespoke Design</span>';

                const bookLabel = isLoggedIn ?
                    "Book" :
                    '<svg class="lock-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="1.5"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path></svg>Sign in to Book';
                const bookTitle = isLoggedIn ? "Book this service" : "Sign in to book this service";

                const imageSrc = "../img/" + encodeURIComponent(p.name) + ".jpg";

                return `
                    <div class="col-md-6 col-xl-4">
                        <article class="card h-100 service-card bg-white shadow-sm border-light">
                            <div class="card-img-wrapper">
                                <img src="${imageSrc}" alt="${p.name}" onerror="this.onerror=null; this.src='../img/image.png';">
                            </div>
                            <div class="card-body d-flex flex-column p-4">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <span class="text-uppercase text-muted small tracking-wide">${p.category_name}</span>
                                    ${badge}
                                </div>
                                <h4 class="card-title font-serif mb-3">${p.name}</h4>
                                <p class="card-text text-secondary mb-4 small lh-lg">${p.description}</p>
                                <div class="mt-auto d-flex justify-content-between align-items-center pt-3 border-top border-light">
                                    <span class="fs-5 fw-medium text-gold">${fmt(p.price)}</span>
                                    <button class="btn btn-outline-dark rounded-pill px-4 btn-sm cta-btn" data-id="${p.id}" title="${bookTitle}">${bookLabel}</button>
                                </div>
                            </div>
                        </article>
                    </div>`;
            }

            function render() {
                const list = getFiltered();
                const grid = $("#grid");
                const empty = $("#emptyState");

                $("#countShown").text(list.length);

                if (list.length === 0) {
                    grid.empty();
                    empty.removeClass("d-none");
                } else {
                    empty.addClass("d-none");
                    grid.html(list.map(cardHTML).join(""));
                }

                const priceLabel = PRICE_OPTIONS.find(o => o.key === state.price)?.label;
                $("#priceFilterLabel").text(state.price !== "all" ? `(Filtered: ${priceLabel})` : "");
                syncChipStates();
            }

            function buildChips(containerId, options, groupKey) {
                const html = options.map(o => {
                    const activeClass = String(state[groupKey]) === String(o.key) ? 'active' : '';
                    return `<button type="button" class="btn-chip ${activeClass}" data-group="${groupKey}" data-key="${o.key}">${o.label}</button>`;
                }).join("");
                $(`#${containerId}`).html(html);
            }

            function syncChipStates() {
                $('.btn-chip').each(function() {
                    const group = $(this).data('group');
                    const key = $(this).data('key');
                    if (String(state[group]) === String(key)) {
                        $(this).addClass('active');
                    } else {
                        $(this).removeClass('active');
                    }
                });
                $("#sort").val(state.sort);
            }

            function rebuildAllChipSets() {
                buildChips("categoryChips", CATEGORY_OPTIONS, "category");
                buildChips("categoryChipsMobile", CATEGORY_OPTIONS, "category");
                buildChips("priceChipsMobile", PRICE_OPTIONS, "price");
                buildChips("sortChipsMobile", SORT_OPTIONS, "sort");
            }

            $(document).on("click", ".btn-chip", function() {
                const group = $(this).data("group");
                let key = $(this).data("key");

                if (group === "category" || group === "price") {
                    key = key === "all" ? "all" : (isNaN(Number(key)) ? key : Number(key));
                }
                state[group] = key;
                if (group === 'category') state.q = ''; // picking a category clears a footer keyword filter
                render();
            });

            $("#sort").on("change", function() {
                state.sort = $(this).val();
                render();
            });

            $("#clearFiltersBtn").on("click", function() {
                state.category = "all";
                state.q = "";
                state.price = "all";
                state.sort = "featured";
                render();
            });

            rebuildAllChipSets();
            render();
        });
    </script>
</body>

</html>