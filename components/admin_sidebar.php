<?php
require_once __DIR__ . '/../functions/permissions.php';
$current_admin_page = $current_admin_page ?? '';
?>
<aside class="admin-sidebar">
    <div class="admin-brand">
        <img
            src="/img/logo.png"
            class="admin-brand-mark"
            alt="Celestia Gems Logo"
            width="32"
            height="32"
            style="object-fit: contain;">Celestia Gems
    </div>

    <nav class="admin-nav">
        <?php if (cg_can('dashboard.view')): ?>
        <a href="/admin/dashboard.php" class="admin-nav-link <?= $current_admin_page === 'dashboard' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <rect x="3" y="3" width="7" height="9" rx="1" />
                <rect x="14" y="3" width="7" height="5" rx="1" />
                <rect x="14" y="12" width="7" height="9" rx="1" />
                <rect x="3" y="16" width="7" height="5" rx="1" />
            </svg>
            Dashboard
        </a>
        <?php endif; ?>

        <?php if (cg_can('products.view')): ?>
        <a href="/admin/product_management.php" class="admin-nav-link <?= $current_admin_page === 'products' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M21 8l-9-5-9 5 9 5 9-5z" />
                <path d="M3 8v8l9 5 9-5V8" />
                <path d="M12 13v8" />
            </svg>
            Products
        </a>
        <?php endif; ?>

        <?php if (cg_can('users.view')): ?>
        <a href="/admin/users_management.php" class="admin-nav-link <?= $current_admin_page === 'users' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <circle cx="9" cy="8" r="3.2" />
                <path d="M2.5 20c.7-3.6 3.4-6 6.5-6s5.8 2.4 6.5 6" />
                <circle cx="17.5" cy="9" r="2.4" />
                <path d="M15.5 13.2c2.4.4 4.3 2.4 4.8 5" />
            </svg>
            Users
        </a>
        <?php endif; ?>

        <?php if (cg_can('orders.view')): ?>
        <a href="/admin/orders_management.php" class="admin-nav-link <?= $current_admin_page === 'orders' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M4 7l8-4 8 4v10l-8 4-8-4z" />
                <path d="M4 7l8 4 8-4" />
                <path d="M12 11v10" />
            </svg>
            Orders
        </a>
        <?php endif; ?>

        <?php if (cg_can('bookings.view')): ?>
        <a href="/admin/bookings_management.php" class="admin-nav-link <?= $current_admin_page === 'bookings' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <rect x="3" y="4" width="18" height="17" rx="2" />
                <path d="M3 9h18" />
                <path d="M8 2v4M16 2v4" />
                <path d="M8 13h2m4 0h2M8 17h2" />
            </svg>
            Bookings
        </a>
        <?php endif; ?>

        <?php if (cg_can('feedback.view')): ?>
        <a href="/admin/feedback.php" class="admin-nav-link <?= $current_admin_page === 'feedback' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M21 12a8 8 0 11-3.6-6.7" />
                <path d="M21 4v6h-6" />
            </svg>
            Feedback
        </a>
        <?php endif; ?>

        <?php if (cg_can('content.manage')): ?>
        <a href="/admin/content.php" class="admin-nav-link <?= $current_admin_page === 'content' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M14 3H6a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V9z" />
                <path d="M14 3v6h6" />
                <path d="M9 13h6M9 17h6" />
            </svg>
            Content
        </a>
        <?php endif; ?>

        <?php if (cg_can('audit.view')): ?>
        <a href="/admin/audit_logs.php" class="admin-nav-link <?= $current_admin_page === 'audit' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M9 3H5a2 2 0 00-2 2v4m18-6h-4a2 2 0 00-2 2v4m0 6v4a2 2 0 002 2h4m-18-6v4a2 2 0 002 2h4" />
                <path d="M8 12h8" />
            </svg>
            Audit Logs
        </a>
        <?php endif; ?>

        <?php if (cg_can('settings.manage')): ?>
        <a href="/admin/settings.php" class="admin-nav-link <?= $current_admin_page === 'settings' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <circle cx="12" cy="12" r="3" />
                <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09a1.65 1.65 0 00-1-1.51 1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09a1.65 1.65 0 001.51-1 1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" />
            </svg>
            Settings
        </a>
        <?php endif; ?>

        <div class="admin-nav-divider"></div>
        <!-- Added ID here -->
        <a href="/functions/logout.php" class="admin-nav-link admin-logout" id="adminSignOutLink">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4" />
                <path d="M16 17l5-5-5-5" />
                <path d="M21 12H9" />
            </svg>
            Log Out
        </a>
    </nav>
</aside>

<!-- Added confirmation script here -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const signOutLink = document.getElementById('adminSignOutLink');
        if (signOutLink) {
            signOutLink.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to sign out?')) {
                    e.preventDefault();
                }
            });
        }
    });
</script>