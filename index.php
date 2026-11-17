<?php
$current_page = 'home';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celestia Gems — Jewelry drawn from the stars</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3.8 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php $homepageCssVersion = @filemtime(__DIR__ . "/styles/homepage.css") ?: time(); ?>
    <link rel="stylesheet" href="styles/homepage.css?v=<?= (int) $homepageCssVersion ?>">
</head>

<body>

    <?php include __DIR__ . '/components/navbar.php'; ?>

    <script>
        /* Publish the sticky header's real height as --cg-header-h so the hero
           and the category section can size themselves to exactly fill the
           rest of the browser window. Re-measured whenever the header changes
           size (announcement bar on/off, mobile menu, window resize). */
        (function() {
            var header = document.querySelector(".cg-header-wrapper");
            if (!header) return;

            function syncHeaderHeight() {
                // Ignore the extra height while the mobile menu is open
                if (header.querySelector(".navbar-collapse.show, .navbar-collapse.collapsing")) return;
                document.documentElement.style.setProperty("--cg-header-h", header.offsetHeight + "px");
            }

            syncHeaderHeight();
            if ("ResizeObserver" in window) {
                new ResizeObserver(syncHeaderHeight).observe(header);
            } else {
                window.addEventListener("resize", syncHeaderHeight);
            }
        })();
    </script>

    <main>
        <!-- Hero Section
             Full-viewport editorial hero: wordmark across the top, collection
             copy on the left, tagline + quick category links on the right,
             and the photograph centered behind them.
             Photo: The Cleveland Museum of Art on Unsplash (CC0). -->
        <section class="hero-section" aria-labelledby="heroWordmark">

            <div class="hero-media" aria-hidden="true">
                <img
                    class="hero-img hero-img--dark"
                    src="https://images.unsplash.com/photo-1719862056532-34900b21387a?q=80&w=1600&auto=format&fit=crop"
                    srcset="https://images.unsplash.com/photo-1719862056532-34900b21387a?q=80&w=900&auto=format&fit=crop 900w,
                            https://images.unsplash.com/photo-1719862056532-34900b21387a?q=80&w=1600&auto=format&fit=crop 1600w,
                            https://images.unsplash.com/photo-1719862056532-34900b21387a?q=80&w=2400&auto=format&fit=crop 2400w"
                    sizes="(min-width: 992px) 60vw, 100vw"
                    alt=""
                    fetchpriority="high">

                <!-- Light-mode photo (only loaded when the light theme is on).
                     Photo: Unsplash, ID cgNxpfoJSJU. -->
                <img
                    class="hero-img hero-img--light"
                    src="https://images.unsplash.com/photo-1673823258465-d9160aa4ed63?q=80&w=1600&auto=format&fit=crop"
                    srcset="https://images.unsplash.com/photo-1673823258465-d9160aa4ed63?q=80&w=900&auto=format&fit=crop 900w,
                            https://images.unsplash.com/photo-1673823258465-d9160aa4ed63?q=80&w=1600&auto=format&fit=crop 1600w,
                            https://images.unsplash.com/photo-1673823258465-d9160aa4ed63?q=80&w=2400&auto=format&fit=crop 2400w"
                    sizes="(min-width: 992px) 60vw, 100vw"
                    alt=""
                    loading="lazy">
            </div>

            <div class="hero-inner container">

                <!-- Brand wordmark banner -->
                <div class="hero-wordmark-wrap">
                    <div class="hero-rule"></div>
                    <h1 class="hero-wordmark" id="heroWordmark" aria-label="Celestia Gems">
                        <span class="hw-word" aria-hidden="true"><span class="hw-letter">C<svg class="hero-spark" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C12.8 7.6 16.4 11.2 24 12 16.4 12.8 12.8 16.4 12 24 11.2 16.4 7.6 12.8 0 12 7.6 11.2 11.2 7.6 12 0Z" /></svg></span>elestia</span>
                        <span class="hw-word" aria-hidden="true"><span class="hw-letter">G<svg class="hero-spark" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C12.8 7.6 16.4 11.2 24 12 16.4 12.8 12.8 16.4 12 24 11.2 16.4 7.6 12.8 0 12 7.6 11.2 11.2 7.6 12 0Z" /></svg></span>ems</span>
                    </h1>
                    <div class="hero-rule"></div>
                </div>

                <!-- Tagline (top right) -->
                <p class="hero-tagline">
                    A celestial touch<br>
                    for timeless moments
                </p>

                <!-- Collection blurb (left) -->
                <div class="hero-col-left">
                    <h2 class="hero-col-heading">Collection<br>2026</h2>

                    <p class="hero-col-text">
                        Discover jewelry inspired by the beauty of the night sky.
                        Each piece is crafted to bring elegance and grace to your
                        most cherished occasions.
                    </p>

                    <a href="#categories" class="btn btn-gold hero-btn rounded-pill d-inline-flex align-items-center gap-2">
                        Discover
                        <svg class="long-arrow" width="30" height="12" viewBox="0 0 32 12" fill="none" stroke="currentColor" stroke-width="1.3" aria-hidden="true">
                            <path d="M0 6h30M25 1l5 5-5 5" />
                        </svg>
                    </a>
                </div>

                <!-- Quick category links (bottom right) -->
                <ul class="hero-cat-links">
                    <li><a href="/pages/shop.php?search=ring">Rings <svg class="long-arrow" width="30" height="12" viewBox="0 0 32 12" fill="none" stroke="currentColor" stroke-width="1.1" aria-hidden="true"><path d="M0 6h30M25 1l5 5-5 5" /></svg></a></li>
                    <li><a href="/pages/shop.php?search=earring">Earrings <svg class="long-arrow" width="30" height="12" viewBox="0 0 32 12" fill="none" stroke="currentColor" stroke-width="1.1" aria-hidden="true"><path d="M0 6h30M25 1l5 5-5 5" /></svg></a></li>
                    <li><a href="/pages/shop.php?search=necklace">Necklaces <svg class="long-arrow" width="30" height="12" viewBox="0 0 32 12" fill="none" stroke="currentColor" stroke-width="1.1" aria-hidden="true"><path d="M0 6h30M25 1l5 5-5 5" /></svg></a></li>
                    <li><a href="/pages/shop.php?search=gemstone">Gemstones <svg class="long-arrow" width="30" height="12" viewBox="0 0 32 12" fill="none" stroke="currentColor" stroke-width="1.1" aria-hidden="true"><path d="M0 6h30M25 1l5 5-5 5" /></svg></a></li>
                </ul>

            </div>
        </section>

        <!-- Trust strip -->
        <section class="trust-strip py-4">
            <div class="container">
                <div class="row row-cols-2 row-cols-md-4 g-4">

                    <div class="col">
                        <div class="trust-item">
                            <svg class="trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                                <circle cx="12" cy="9" r="5.5" />
                                <path d="M8.7 13.5 7 20l5-2.4 5 2.4-1.7-6.5" />
                            </svg>
                            <span>Every stone graded &amp; certified</span>
                        </div>
                    </div>

                    <div class="col">
                        <div class="trust-item">
                            <svg class="trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                                <path d="M12 3.5 19 6v6c0 4.2-3 7-7 8.5-4-1.5-7-4.3-7-8.5V6z" />
                                <path d="M9.2 12 11 13.8l3.8-4" />
                            </svg>
                            <span>Lifetime craftsmanship warranty</span>
                        </div>
                    </div>

                    <div class="col">
                        <div class="trust-item">
                            <svg class="trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                                <path d="M4 8.5 12 4l8 4.5v7L12 20l-8-4.5z" />
                                <path d="M4 8.5 12 13l8-4.5" />
                                <path d="M12 13v7" />
                            </svg>
                            <span>Fully insured worldwide shipping</span>
                        </div>
                    </div>

                    <div class="col">
                        <div class="trust-item">
                            <svg class="trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                                <path d="M4 12a8 8 0 1 1 2.6 5.9" />
                                <path d="M4 17v-4h4" />
                            </svg>
                            <span>Easy 30-day returns</span>
                        </div>
                    </div>

                </div>
            </div>
        </section>

        <!-- Products: shop by category
             Fills exactly one screen below the sticky header: a compact
             heading row, then a 2 x 2 grid of full-bleed photos with the
             category name laid over the top of each one. -->
        <section id="categories" class="shop-section">

            <div class="container shop-head">
                <div>
                    <p class="eyebrow mb-1">Our Collections</p>
                    <h2 class="m-0">Shop by category</h2>
                </div>
                <a href="/pages/shop.php" class="btn-link-underline">View all pieces &rarr;</a>
            </div>

            <div class="shop-grid">

                <a href="/pages/shop.php?search=ring" class="shop-card">
                    <img
                        class="shop-card-img"
                        src="https://images.unsplash.com/photo-1611955167811-4711904bb9f8?q=80&w=1200&auto=format&fit=crop"
                        alt="Gold ring set with a clear gemstone"
                        loading="lazy">
                    <div class="shop-card-head">
                        <h3>Rings</h3>
                        <svg class="long-arrow shop-arrow" width="34" height="13" viewBox="0 0 32 12" fill="none" stroke="currentColor" stroke-width="1.1" aria-hidden="true">
                            <path d="M0 6h30M25 1l5 5-5 5" />
                        </svg>
                    </div>
                </a>

                <a href="/pages/shop.php?search=earring" class="shop-card">
                    <img
                        class="shop-card-img"
                        src="https://images.unsplash.com/photo-1619203451989-53f6d2ca0e57?q=80&w=1200&auto=format&fit=crop"
                        alt="Gold diamond hoop earrings"
                        loading="lazy">
                    <div class="shop-card-head">
                        <h3>Earrings</h3>
                        <svg class="long-arrow shop-arrow" width="34" height="13" viewBox="0 0 32 12" fill="none" stroke="currentColor" stroke-width="1.1" aria-hidden="true">
                            <path d="M0 6h30M25 1l5 5-5 5" />
                        </svg>
                    </div>
                </a>

                <a href="/pages/shop.php?search=necklace" class="shop-card">
                    <img
                        class="shop-card-img"
                        src="https://images.unsplash.com/photo-1611107683227-e9060eccd846?q=80&w=1200&auto=format&fit=crop"
                        alt="Gold chain necklace"
                        loading="lazy">
                    <div class="shop-card-head">
                        <h3>Necklaces</h3>
                        <svg class="long-arrow shop-arrow" width="34" height="13" viewBox="0 0 32 12" fill="none" stroke="currentColor" stroke-width="1.1" aria-hidden="true">
                            <path d="M0 6h30M25 1l5 5-5 5" />
                        </svg>
                    </div>
                </a>

                <a href="/pages/shop.php?search=gemstone" class="shop-card">
                    <img
                        class="shop-card-img"
                        src="https://images.unsplash.com/photo-1617474925133-1a91ec027ba8?q=80&w=1200&auto=format&fit=crop"
                        alt="Assorted colorful loose gemstones"
                        loading="lazy">
                    <div class="shop-card-head">
                        <h3>Gemstones</h3>
                        <svg class="long-arrow shop-arrow" width="34" height="13" viewBox="0 0 32 12" fill="none" stroke="currentColor" stroke-width="1.1" aria-hidden="true">
                            <path d="M0 6h30M25 1l5 5-5 5" />
                        </svg>
                    </div>
                </a>

            </div>
        </section>

        <!-- Services -->
        <section id="services" class="py-5 py-lg-6">

            <div class="container">
                <div class="text-start mb-5 section-head">
                    <p class="eyebrow">In-House Services</p>
                    <h2 class="mt-2">Care and craftsmanship, in house</h2>
                </div>
            </div>

            <div class="services-grid">

                <!-- Jewelry Care & Professional Services -->
                <div class="service-photo-card">
                    <div class="service-img">
                        <img
                            src="https://images.unsplash.com/photo-1754598638157-271e84f55d03?q=80&w=1200&auto=format&fit=crop"
                            alt="Jeweler polishing a ring in the workshop"
                            loading="lazy">
                    </div>
                    <div class="service-body">
                        <h3>Jewelry Care &amp; Professional Services</h3>
                        <p>Cleaning, polishing, resizing, and repair from our on-site jewellers, plus insurance appraisals so every piece stays protected.</p>
                        <a href="pages/service.php?cat=12" class="btn-link-underline">View Care Services &rarr;</a>
                    </div>
                </div>

                <!-- Custom Jewelry -->
                <div class="service-photo-card">
                    <div class="service-img">
                        <img
                            src="https://images.unsplash.com/photo-1754598638154-4e84cb9cfc88?q=80&w=1200&auto=format&fit=crop"
                            alt="Designer sketching a custom jewelry piece"
                            loading="lazy">
                    </div>
                    <div class="service-body">
                        <h3>Custom Jewelry</h3>
                        <p>Work with our designers from a first sketch to the final polish, or reimagine a family heirloom into something new.</p>
                        <a href="pages/service.php?cat=11" class="btn-link-underline">View Custom Services &rarr;</a>
                    </div>
                </div>

            </div>
        </section>

        <!-- Our Story -->
        <section id="origin" class="py-5">
            <div class="container">
                <div class="row rounded-1 g-0 overflow-hidden" style="border:1px solid var(--cg-hair);">
                    <div class="col-lg-6 origin-art">
                        <svg viewBox="0 0 400 300">
                            <g stroke="#C9A15C" stroke-width="0.6" opacity="0.6">
                                <line x1="80" y1="60" x2="170" y2="110" />
                                <line x1="170" y1="110" x2="140" y2="200" />
                                <line x1="170" y1="110" x2="260" y2="90" />
                                <line x1="260" y1="90" x2="320" y2="160" />
                                <line x1="140" y1="200" x2="220" y2="230" />
                            </g>
                        </svg>
                    </div>
                    <div class="col-lg-6 p-5 d-flex flex-column justify-content-center" style="background:var(--cg-bg-alt);">
                        <p class="eyebrow">About Us</p>
                        <h3 class="mt-2 mb-3">How it all began</h3>
                        <p class="mb-4" style="color: var(--cg-text-soft);">Celestia Gems started as a small atelier obsessed with the way starlight catches on a cut stone. Every collection since has chased that same first spark — patient handwork, honest materials, and pieces meant to be worn for decades.</p>
                        <a href="/pages/aboutus.php" class="btn-link-underline" style="width:max-content;">Read the full story</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <?php include __DIR__ . '/components/footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function($) {
            "use strict";

            var prefersReducedMotion = window.matchMedia &&
                window.matchMedia("(prefers-reduced-motion: reduce)").matches;

            // Height of the sticky header (announcement bar + navbar).
            function headerHeight() {
                var header = document.querySelector(".cg-header-wrapper");
                return header ? header.offsetHeight : 0;
            }

            $(function() {

                $('a[href^="#"]').on("click", function(e) {
                    var targetId = $(this).attr("href");
                    if (!targetId || targetId === "#") return;

                    var $target = $(targetId);
                    if (!$target.length) return;

                    e.preventDefault();

                    $("html, body").animate({
                            scrollTop: $target.offset().top - headerHeight()
                        },
                        prefersReducedMotion ? 0 : 600
                    );
                });

            });
        })(jQuery);
    </script>
</body>

</html>