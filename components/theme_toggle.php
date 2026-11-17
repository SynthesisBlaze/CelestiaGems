<?php
/*
 * Light / dark theme toggle button.
 * Used by components/navbar.php (storefront) and components/admin_topbar.php (admin).
 *
 * - Styles live in styles/theme.css (.cg-theme-toggle).
 * - The saved choice is applied before paint by components/theme_init.php.
 * - Switching fires a "cg-themechange" event on document, so scripts that
 *   draw with colors (e.g. the dashboard chart) can redraw:
 *       document.addEventListener('cg-themechange', function (e) { e.detail.theme; });
 */
?>
<button
    type="button"
    class="cg-theme-toggle"
    aria-label="Switch to light mode"
    title="Switch to light mode"
    aria-pressed="false"
>
    <svg class="icon-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4L6 18M18 6l1.4-1.4" />
    </svg>
    <svg class="icon-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z" />
    </svg>
</button>
<script>
    (function () {
        if (window.cgThemeToggleReady) return; // set up once per page
        window.cgThemeToggleReady = true;

        var root = document.documentElement;

        function isLight() {
            return root.getAttribute('data-theme') === 'light';
        }

        function syncButtons() {
            var label = isLight() ? 'Switch to dark mode' : 'Switch to light mode';
            document.querySelectorAll('.cg-theme-toggle').forEach(function (btn) {
                btn.setAttribute('aria-label', label);
                btn.setAttribute('title', label);
                btn.setAttribute('aria-pressed', isLight() ? 'true' : 'false');
            });
        }

        document.addEventListener('click', function (event) {
            var btn = event.target.closest('.cg-theme-toggle');
            if (!btn) return;

            var goLight = !isLight();
            root.classList.add('cg-theme-switching');
            if (goLight) {
                root.setAttribute('data-theme', 'light');
            } else {
                root.removeAttribute('data-theme');
            }
            try { localStorage.setItem('cg-theme', goLight ? 'light' : 'dark'); } catch (e) {}
            syncButtons();
            document.dispatchEvent(new CustomEvent('cg-themechange', { detail: { theme: goLight ? 'light' : 'dark' } }));
            setTimeout(function () { root.classList.remove('cg-theme-switching'); }, 350);
        });

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', syncButtons);
        } else {
            syncButtons();
        }
    })();
</script>
