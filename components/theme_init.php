<?php
/*
 * Theme bootstrapper — include this as the FIRST thing inside <head>
 * on every storefront page.
 *
 * 1. Loads styles/theme.css directly with a version number taken from the
 *    file's last-modified time, so browsers and host caches always pick up
 *    the newest colors (the @import inside each page stylesheet has no
 *    version and can stay stale for a long time).
 * 2. Runs before anything paints, reads the visitor's saved choice and sets
 *    <html data-theme="light"> so there's no flash of the dark theme.
 *
 * Dark (the original palette) is the default. The toggle lives in
 * components/navbar.php; the light palette lives in styles/theme.css.
 */
$cgThemeCssFile = __DIR__ . '/../styles/theme.css';
$cgThemeCssVersion = is_file($cgThemeCssFile) ? filemtime($cgThemeCssFile) : time();
?>
<link rel="stylesheet" href="/styles/theme.css?v=<?= (int) $cgThemeCssVersion ?>">
<script>
    (function () {
        try {
            if (localStorage.getItem('cg-theme') === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        } catch (e) { /* storage blocked — stay on dark */ }
    })();
</script>
