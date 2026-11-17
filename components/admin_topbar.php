<?php

/**
 * Admin topbar.
 * Set before including:
 *   $page_title  (string) e.g. 'Dashboard', 'Content'
 *   $admin_user  (array)  ['name' => ..., 'role' => ...]
 */
$page_title = $page_title ?? 'Dashboard';
$admin_user = $admin_user ?? ['name' => 'Admin', 'role' => 'Admin'];
?>
<header class="admin-topbar">
    <div class="admin-topbar-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
            <rect x="3" y="3" width="7" height="9" rx="1" />
            <rect x="14" y="3" width="7" height="5" rx="1" />
            <rect x="14" y="12" width="7" height="9" rx="1" />
            <rect x="3" y="16" width="7" height="5" rx="1" />
        </svg>
        <?= htmlspecialchars($page_title) ?>
    </div>

    <div class="admin-user">
        <?php include __DIR__ . '/theme_toggle.php'; ?>
        <span class="divider"></span>
        <span><?= htmlspecialchars($admin_user['name']) ?>, <?= htmlspecialchars($admin_user['role']) ?></span>
        <span class="divider"></span>
        <span class="avatar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <circle cx="12" cy="8" r="4" />
                <path d="M4 21c0-4 4-7 8-7s8 3 8 7" />
            </svg>
        </span>
    </div>
</header>