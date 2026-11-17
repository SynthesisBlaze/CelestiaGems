<?php
/**
 * Role-based access control for the admin side — the single place that
 * decides who can do what.
 *
 * Roles (role_id in the users table):
 *   1 = Guest, 2 = Customer, 3 = Staff, 4 = Admin, 5 = Super Admin
 *
 * To change what a role can do, edit CG_PERMISSIONS below. Every admin page,
 * admin endpoint, sidebar link and action button reads from it.
 *
 * Usage
 *   Admin page:      require_once __DIR__ . '/../functions/permissions.php';
 *                    cg_require_page('orders.view');
 *   JSON endpoint:   cg_require_json('orders.update_status');
 *   Form endpoint:   cg_require_page('products.manage');   // redirects on failure
 *   In templates:    <?php if (cg_can('products.manage')): ?> … <?php endif; ?>
 *
 * The role is re-read from the database on every admin request, so a
 * demoted or deleted account loses access immediately instead of keeping
 * whatever role it had when it logged in.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connection.php';

const CG_ROLE_GUEST       = 1;
const CG_ROLE_CUSTOMER    = 2;
const CG_ROLE_STAFF       = 3;
const CG_ROLE_ADMIN       = 4;
const CG_ROLE_SUPER_ADMIN = 5;

/** Roles that may enter the admin side at all. */
const CG_ADMIN_SIDE_ROLES = [CG_ROLE_STAFF, CG_ROLE_ADMIN, CG_ROLE_SUPER_ADMIN];

/**
 * permission => roles allowed.
 *
 * Staff:       day-to-day operations — orders (ship/deliver), bookings,
 *              customer messages and reviews. Read-only product list.
 * Admin:       everything Staff can do, plus products, content, cancellations,
 *              payments, returns, and managing Customer/Staff accounts.
 * Super Admin: everything, plus store settings, broadcasts, audit logs,
 *              and managing Admin/Super Admin accounts.
 */
const CG_PERMISSIONS = [
    // Dashboard
    'dashboard.view'          => [3, 4, 5],
    'dashboard.revenue'       => [4, 5],    // sales & analytics section

    // Products
    'products.view'           => [3, 4, 5],
    'products.manage'         => [4, 5],    // add, edit, delete, images, stock

    // Orders
    'orders.view'             => [3, 4, 5],
    'orders.update_status'    => [3, 4, 5], // processing → shipped → delivered
    'orders.cancel'           => [4, 5],    // cancel, approve/refuse cancellation requests
    'orders.mark_paid'        => [4, 5],
    'returns.manage'          => [4, 5],    // approve/deny returns (refunds)

    // Bookings
    'bookings.view'           => [3, 4, 5],
    'bookings.manage'         => [3, 4, 5],

    // Feedback
    'feedback.view'           => [3, 4, 5],
    'feedback.reply'          => [3, 4, 5], // reply to messages & reviews, update inquiry status
    'feedback.delete_review'  => [4, 5],

    // Site content (About, Contact, FAQ)
    'content.manage'          => [4, 5],

    // Users (who can be managed is further limited by cg_can_manage_role())
    'users.view'              => [4, 5],
    'users.manage'            => [4, 5],

    // Super Admin only
    'audit.view'              => [5],
    'settings.manage'         => [5],       // payments, shipping, 2FA, announcements, broadcasts
];

const CG_ROLE_NAMES = [
    CG_ROLE_GUEST       => 'Guest',
    CG_ROLE_CUSTOMER    => 'Customer',
    CG_ROLE_STAFF       => 'Staff',
    CG_ROLE_ADMIN       => 'Admin',
    CG_ROLE_SUPER_ADMIN => 'Super Admin',
];

function cg_role_name(int $roleId): string
{
    return CG_ROLE_NAMES[$roleId] ?? 'Unknown';
}

/**
 * The signed-in user's current role, read fresh from the database once per
 * request (and synced back into the session). Returns 0 when not signed in
 * or when the account no longer exists.
 */
function cg_current_role(): int
{
    static $role = null;
    if ($role !== null) {
        return $role;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return $role = 0;
    }

    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT role_id FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $dbRole = $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('cg_current_role() failed: ' . $e->getMessage());
        // Fall back to the session value rather than locking everyone out on a DB hiccup.
        return $role = (int) ($_SESSION['role_id'] ?? 0);
    }

    if ($dbRole === false) {
        // Account was deleted — end the session.
        $_SESSION = [];
        return $role = 0;
    }

    $role = (int) $dbRole;
    $_SESSION['role_id'] = $role;
    return $role;
}

/** Does the current user (or the given role) have this permission? */
function cg_can(string $permission, ?int $roleId = null): bool
{
    $roleId = $roleId ?? cg_current_role();
    return in_array($roleId, CG_PERMISSIONS[$permission] ?? [], true);
}

/** Guard for admin pages and form-post endpoints: redirects when not allowed. */
function cg_require_page(string $permission): void
{
    $role = cg_current_role();

    if ($role === 0) {
        header('Location: /pages/login.php');
        exit;
    }
    if (!in_array($role, CG_ADMIN_SIDE_ROLES, true)) {
        // Customers who wander into /admin go back to their account.
        header('Location: /pages/account.php');
        exit;
    }
    if (!cg_can($permission, $role)) {
        $_SESSION['admin_denied'] = 'Your role (' . cg_role_name($role) . ') does not have access to that page.';
        header('Location: /admin/dashboard.php');
        exit;
    }
}

/** Guard for AJAX/JSON endpoints: responds 401/403 JSON when not allowed. */
function cg_require_json(string $permission): void
{
    $role = cg_current_role();
    if ($role === 0 || !cg_can($permission, $role)) {
        http_response_code($role === 0 ? 401 : 403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $role === 0
                ? 'Your session has ended. Please sign in again.'
                : 'Your role (' . cg_role_name($role) . ') is not allowed to do that.',
        ]);
        exit;
    }
}

/** Name/role pair the admin topbar shows. */
function cg_admin_user(): array
{
    return [
        'name' => trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Admin',
        'role' => cg_role_name(cg_current_role()),
    ];
}

/* ---------------------------------------------------------------------
 * User-management hierarchy
 *
 *   Admin:       can manage Guest, Customer and Staff accounts, and assign
 *                those roles. Cannot touch Admins or Super Admins.
 *   Super Admin: can manage every account and assign every role.
 *   Nobody can change their own role or delete their own account here.
 * ------------------------------------------------------------------- */

/** Roles the given actor may assign to an account. */
function cg_assignable_roles(int $actorRole): array
{
    if ($actorRole === CG_ROLE_SUPER_ADMIN) {
        return [CG_ROLE_GUEST, CG_ROLE_CUSTOMER, CG_ROLE_STAFF, CG_ROLE_ADMIN, CG_ROLE_SUPER_ADMIN];
    }
    if ($actorRole === CG_ROLE_ADMIN) {
        return [CG_ROLE_GUEST, CG_ROLE_CUSTOMER, CG_ROLE_STAFF];
    }
    return [];
}

/** May the actor edit/delete an account that currently has $targetRole? */
function cg_can_manage_role(int $actorRole, int $targetRole): bool
{
    return in_array($targetRole, cg_assignable_roles($actorRole), true);
}

/** Audit-log actor_type for a role. */
function cg_actor_type(int $roleId): string
{
    return match ($roleId) {
        CG_ROLE_SUPER_ADMIN => 'super_admin',
        CG_ROLE_ADMIN       => 'admin',
        CG_ROLE_STAFF       => 'staff',
        default             => 'customer',
    };
}

/** Permissions of the current user as a JS-friendly map, for hiding buttons client-side. */
function cg_permissions_for_js(array $permissions): string
{
    $map = [];
    foreach ($permissions as $p) {
        $map[$p] = cg_can($p);
    }
    return json_encode($map);
}
