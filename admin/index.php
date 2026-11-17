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
    <link rel="stylesheet" href="styles/homepage.css">
</head>

<body>

    <?php include __DIR__ . '/components/navbar.php'; ?>

    <main>
        <!-- Hero Section -->
        <section class="hero-section py-5 py-lg-6">
            <div class="container py-4">

                <!-- Brand wordmark banner -->
                <div class="hero-wordmark-wrap mb-5">
                    <div class="hero-rule"></div>
                    <h1 class="hero-wordmark">
                        <svg class="hero-spark hero-spark-left" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 2 14 10 22 12 14 14 12 22 10 14 2 12 10 10Z" />
                        </svg>
                        Celestia Gems
                        <svg class="hero-spark hero-spark-right" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 2 14 10 22 12 14 14 12 22 10 10Z" />
                        </svg>
                    </h1>
                    <div class="hero-rule"></div>
                </div>

                <div class="hero-content-grid">

                    <!-- Left: collection blurb -->
                    <div class="hero-col hero-col-left">

                        <p class="eyebrow">Collection 2026</p>

                        <h2 class="hero-col-heading">
                            Jewelry drawn<br>
                            from the <em>stars</em>
                        </h2>

                        <p class="hero-col-text">
                            Inspired by the beauty of the night sky, each piece captures
                            the brilliance of the stars in timeless designs.
                        </p>

                        <a
                            href="#categories"
                            class="btn btn-gold rounded-pill px-4 py-3 d-inline-flex align-items-center gap-2">
                            Discover
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M5 12h14M13 6l6 6-6 6" />
                            </svg>
                        </a>

                    </div>

                    <!-- Center: hero photograph -->
                    <div class="hero-col hero-col-center hero-image-col">
                        <div class="hero-image-wrapper">
                            <img
                                class="hero-img"
                                src="https://images.unsplash.com/photo-1778536818395-483dcb53c041?q=80&w=1200&auto=format&fit=crop"
                                alt="A hand adorned with gold rings set with sparkling gemstones">
                        </div>
                    </div>

                    <!-- Right: tagline + quick category links -->
                    <div class="hero-col hero-col-right">

                        <p class="hero-tagline">
                            A celestial touch<br>
                            for timeless moments
                        </p>

                        <ul class="hero-cat-links">
                            <li><a href="/pages/shop.php?search=ring">Rings <span>&rarr;</span></a></li>
                            <li><a href="/pages/shop.php?search=earring">Earrings <span>&rarr;</span></a></li>
                            <li><a href="/pages/shop.php?search=necklace">Necklaces <span>&rarr;</span></a></li>
                            <li><a href="/pages/shop.php?search=gemstone">Gemstones <span>&rarr;</span></a></li>
                        </ul>

                    </div>

                </div>

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

        <!-- Products: shop by category -->
        <section id="categories" class="py-5 py-lg-6">

            <div class="container">
                <div class="text-start mb-5 section-head">
                    <p class="eyebrow">Our Collections</p>
                    <h2 class="mt-2">Shop by category</h2>
                </div>
            </div>

            <div class="shop-grid">

                <!-- Earrings -->
                <a href="/pages/shop.php?search=earring" class="shop-card">
                    <div class="shop-card-head">
                        <h3>Earrings</h3>
                        <span class="shop-arrow">&rarr;</span>
                    </div>
                    <div class="shop-card-img">
                        <img
                            src="https://images.unsplash.com/photo-1619203451989-53f6d2ca0e57?q=80&w=1200&auto=format&fit=crop"
                            alt="Gold diamond hoop earrings"
                            loading="lazy">
                    </div>
                </a>

                <!-- Rings -->
                <a href="/pages/shop.php?search=ring" class="shop-card">
                    <div class="shop-card-head">
                        <h3>Rings</h3>
                        <span class="shop-arrow">&rarr;</span>
                    </div>
                    <div class="shop-card-img">
                        <img
                            src="https://images.unsplash.com/photo-1611955167811-4711904bb9f8?q=80&w=1200&auto=format&fit=crop"
                            alt="Gold ring set with a clear gemstone"
                            loading="lazy">
                    </div>
                </a>

                <!-- Necklace -->
                <a href="/pages/shop.php?search=necklace" class="shop-card">
                    <div class="shop-card-head">
                        <h3>Necklace</h3>
                        <span class="shop-arrow">&rarr;</span>
                    </div>
                    <div class="shop-card-img">
                        <img
                            src="https://images.unsplash.com/photo-1611107683227-e9060eccd846?q=80&w=1200&auto=format&fit=crop"
                            alt="Gold chain necklace"
                            loading="lazy">
                    </div>
                </a>

                <!-- Gemstone -->
                <a href="/pages/shop.php?search=gemstone" class="shop-card">
                    <div class="shop-card-head">
                        <h3>Gemstone</h3>
                        <span class="shop-arrow">&rarr;</span>
                    </div>
                    <div class="shop-card-img">
                        <img
                            src="https://images.unsplash.com/photo-1617474925133-1a91ec027ba8?q=80&w=1200&auto=format&fit=crop"
                            alt="Assorted colorful loose gemstones"
                            loading="lazy">
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

            $(function() {

                $('a[href^="#"]').on("click", function(e) {
                    var targetId = $(this).attr("href");
                    if (!targetId || targetId === "#") return;

                    var $target = $(targetId);
                    if (!$target.length) return;

                    e.preventDefault();

                    $("html, body").animate({
                            scrollTop: $target.offset().top - 20
                        },
                        prefersReducedMotion ? 0 : 600
                    );
                });

            });
        })(jQuery);
    </script>
</body>

</html>