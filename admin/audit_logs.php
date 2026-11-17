<?php
require_once __DIR__ . '/../functions/permissions.php';
cg_require_page('audit.view');

// Include your database connection
require_once __DIR__ . '/../functions/db_connection.php'; // Adjust this path based on your folder structure

$current_admin_page = 'audit';
$page_title = 'Audit Logs';
$admin_user = cg_admin_user();

/**
 * ---------------------------------------------------------------
 * Filters (all optional, all via GET so the page is bookmarkable
 * and shareable — e.g. "everything Jane did last week")
 * ---------------------------------------------------------------
 */
$f_actor       = trim($_GET['actor'] ?? '');
$f_action      = trim($_GET['action'] ?? '');
$f_entity_type = trim($_GET['entity_type'] ?? '');
$f_entity_id   = trim($_GET['entity_id'] ?? '');
$f_date_from   = trim($_GET['date_from'] ?? '');
$f_date_to     = trim($_GET['date_to'] ?? '');

$per_page = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// --- Build WHERE clause dynamically from whatever filters are set ---
// These reference the merged result set's own column names (see $unionSql
// below), not table-prefixed ones, since audit_logs and login_logs are
// combined into one normalized shape before filtering.
$where  = [];
$params = [];

if ($f_actor !== '') {
    $where[] = "(first_name ILIKE :actor OR last_name ILIKE :actor OR email ILIKE :actor)";
    $params[':actor'] = '%' . $f_actor . '%';
}
if ($f_action !== '') {
    $where[] = "action = :action";
    $params[':action'] = $f_action;
}
if ($f_entity_type !== '') {
    $where[] = "entity_type = :entity_type";
    $params[':entity_type'] = $f_entity_type;
}
if ($f_entity_id !== '') {
    $where[] = "entity_id = :entity_id";
    $params[':entity_id'] = (int)$f_entity_id;
}
if ($f_date_from !== '') {
    $where[] = "created_at >= :date_from";
    $params[':date_from'] = $f_date_from . ' 00:00:00';
}
if ($f_date_to !== '') {
    $where[] = "created_at <= :date_to";
    $params[':date_to'] = $f_date_to . ' 23:59:59';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// --- Distinct filter options ---
// Start from a fixed set of standard actions/entity types (so options like
// "Delete" or "Login" are always available in the filter even before any
// matching row exists yet), then merge in anything else already present.
$standardActions = ['create', 'update', 'delete', 'register', 'login', 'login_pending_2fa', 'login_failed', 'logout'];
$dbActions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
$actionOptions = array_unique(array_merge($standardActions, $dbActions));
sort($actionOptions);

$standardEntityTypes = ['user', 'about_page', 'contact_page', 'faq', 'login', 'product', 'order', 'booking', 'settings', 'notification', 'return_request', 'payment'];
$dbEntityTypes = $pdo->query("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type ASC")->fetchAll(PDO::FETCH_COLUMN);
$entityTypeOptions = array_unique(array_merge($standardEntityTypes, $dbEntityTypes));
sort($entityTypeOptions);

/**
 * Merges audit_logs and login_logs into one normalized shape so both show
 * up together on this page, newest first. login_logs rows don't carry an
 * actor_type directly, so it's derived here from the linked user's role_id
 * (falling back to 'unknown' when even the user link is missing, e.g. a
 * failed attempt against an email that doesn't match any account).
 */
$unionSql = "
    SELECT
        CONCAT('audit-', al.audit_id) AS log_key,
        al.actor_user_id, al.actor_type, al.action,
        al.entity_type, al.entity_id, al.old_values, al.new_values, al.created_at,
        u.first_name, u.last_name, u.email
    FROM audit_logs al
    LEFT JOIN users u ON u.user_id = al.actor_user_id

    UNION ALL

    SELECT
        CONCAT('login-', ll.login_id) AS log_key,
        ll.user_id AS actor_user_id,
        CASE u2.role_id
            WHEN 5 THEN 'super_admin'
            WHEN 4 THEN 'admin'
            WHEN 3 THEN 'staff'
            WHEN 2 THEN 'customer'
            WHEN 1 THEN 'guest'
            ELSE 'unknown'
        END AS actor_type,
        CASE ll.status
            WHEN 'success' THEN 'login'
            WHEN 'pending_2fa' THEN 'login_pending_2fa'
            ELSE 'login_failed'
        END AS action,
        'login' AS entity_type,
        NULL AS entity_id,
        NULL AS old_values,
        json_build_object(
            'user_agent', ll.user_agent,
            'failure_reason', ll.failure_reason,
            'email_attempted', ll.email_attempted
        )::text AS new_values,
        ll.created_at,
        u2.first_name, u2.last_name,
        COALESCE(u2.email, ll.email_attempted) AS email
    FROM login_logs ll
    LEFT JOIN users u2 ON u2.user_id = ll.user_id
";

// --- Total count for pagination ---
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM ($unionSql) combined $whereSql");
$stmtCount->execute($params);
$total_rows = (int)$stmtCount->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// --- Fetch the page of logs ---
$sql = "
    SELECT * FROM ($unionSql) combined
    $whereSql
    ORDER BY created_at DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Maps an action string to one of the existing .status-badge colour
 * variants, purely by keyword — keeps this page in sync automatically
 * no matter what action strings other parts of the app decide to log.
 */
function auditActionBadgeClass(string $action): string
{
    $a = strtolower($action);
    if (str_contains($a, 'delete') || str_contains($a, 'remove') || str_contains($a, 'fail')) {
        return 'cancelled'; // red
    }
    if (str_contains($a, 'pending')) {
        return 'pending'; // purple — in progress, not yet a success or failure
    }
    if (str_contains($a, 'create') || str_contains($a, 'add') || str_contains($a, 'insert') || str_contains($a, 'login') || str_contains($a, 'verify')) {
        return 'delivered'; // green
    }
    if (str_contains($a, 'update') || str_contains($a, 'edit') || str_contains($a, 'change')) {
        return 'shipped'; // gold
    }
    return 'pending'; // purple — default / neutral (logout, view, etc.)
}

/**
 * Turns the raw action + entity_type stored in the DB into a readable
 * phrase for display — e.g. action='update', entity_type='faq' becomes
 * "Update Content". Falls back to a sensible generic phrase for any
 * action/entity combo it doesn't explicitly know about, so new action
 * strings logged elsewhere in the app never show up blank or broken.
 */
function auditActionLabel(string $action, string $entityType): string
{
    $action = strtolower($action);
    $entityType = strtolower($entityType);

    // A few actions read better as their own phrase rather than "Verb + Entity"
    $overrides = [
        'register'     => 'New Account Registered',
        'login'             => 'Signed In',
        'login_pending_2fa' => 'Password Verified — 2FA Pending',
        'login_failed'      => 'Failed Login Attempt',
        'logout'       => 'Signed Out',
    ];
    if (isset($overrides[$action])) {
        return $overrides[$action];
    }

    $entityLabels = [
        'user'         => 'User',
        'about_page'   => 'Content',
        'contact_page' => 'Content',
        'faq'          => 'Content',
    ];
    $entityLabel = $entityLabels[$entityType] ?? ucwords(str_replace('_', ' ', $entityType));

    $actionVerbs = [
        'create' => 'Create',
        'update' => 'Update',
        'delete' => 'Delete',
    ];
    $actionVerb = $actionVerbs[$action] ?? ucfirst($action);

    return $actionVerb . ' ' . $entityLabel;
}

/**
 * Maps actor_type to a colour variant of .actor-type-pill so roles are
 * visually distinct at a glance — gold for admins, green for customers,
 * purple for system-generated events, red for guest/unknown actors.
 */
function auditRoleBadgeClass(string $actorType): string
{
    $t = strtolower($actorType);
    return match ($t) {
        'super_admin' => 'role-super_admin',
        'admin'       => 'role-admin',
        'customer'    => 'role-customer',
        'staff'       => 'role-staff',
        'system'      => 'role-system',
        default       => 'role-guest',
    };
}

function buildQueryString(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    // Drop empty values so the URL stays clean
    $query = array_filter($query, fn($v) => $v !== '' && $v !== null);
    return htmlspecialchars('?' . http_build_query($query));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs — Celestia Gems Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/../styles/admin.css">
</head>

<body>

    <div class="admin-shell">
        <?php include __DIR__ . '/../components/admin_sidebar.php'; ?>

        <div class="admin-main">
            <?php include __DIR__ . '/../components/admin_topbar.php'; ?>

            <div class="admin-content">

                <!-- ============ FILTER BAR ============ -->
                <form class="admin-panel filter-bar mb-4" method="get">
                    <div class="row g-3 align-items-end">
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label">Actor</label>
                            <input type="text" class="form-control" name="actor" placeholder="Name or email"
                                value="<?= htmlspecialchars($f_actor) ?>">
                        </div>
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label">Action</label>
                            <select class="form-select" name="action">
                                <option value="">All actions</option>
                                <?php foreach ($actionOptions as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= $f_action === $opt ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(ucfirst($opt)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label">Entity Type</label>
                            <select class="form-select" name="entity_type">
                                <option value="">All entities</option>
                                <?php foreach ($entityTypeOptions as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= $f_entity_type === $opt ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(ucfirst($opt)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3 col-lg-1">
                            <label class="form-label">Entity ID</label>
                            <input type="number" class="form-control" name="entity_id" placeholder="#"
                                value="<?= htmlspecialchars($f_entity_id) ?>">
                        </div>
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label">From</label>
                            <input type="date" class="form-control" name="date_from"
                                value="<?= htmlspecialchars($f_date_from) ?>">
                        </div>
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label">To</label>
                            <input type="date" class="form-control" name="date_to"
                                value="<?= htmlspecialchars($f_date_to) ?>">
                        </div>
                        <div class="col-12 col-lg-1 d-flex gap-2">
                            <button type="submit" class="btn btn-gold px-3 py-2 flex-fill">Filter</button>
                        </div>
                    </div>
                    <?php if ($f_actor || $f_action || $f_entity_type || $f_entity_id || $f_date_from || $f_date_to): ?>
                        <div class="mt-3">
                            <a href="audit_logs.php" class="filter-clear-link">Clear all filters</a>
                        </div>
                    <?php endif; ?>
                </form>

                <!-- ============ LOG TABLE ============ -->
                <div class="admin-panel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="admin-panel-title mb-0">
                            Activity
                            <span class="audit-count"><?= number_format($total_rows) ?> <?= $total_rows === 1 ? 'entry' : 'entries' ?></span>
                        </div>
                    </div>

                    <?php if (empty($logs)): ?>
                        <div class="empty-state">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                                <path d="M9 3H5a2 2 0 00-2 2v4m18-6h-4a2 2 0 00-2 2v4m0 6v4a2 2 0 002 2h4m-18-6v4a2 2 0 002 2h4" />
                                <path d="M8 12h8" />
                            </svg>
                            <div class="title">No matching log entries</div>
                            <div class="hint">
                                <?= ($f_actor || $f_action || $f_entity_type || $f_entity_id || $f_date_from || $f_date_to)
                                    ? 'Try widening or clearing your filters.'
                                    : 'Actions taken across the admin panel will show up here once they start being recorded.' ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Actor</th>
                                    <th>User ID</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Action</th>
                                    <th>Entity Type</th>
                                    <th>Entity ID</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <?php
                                    $actorName = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''));
                                    $badgeClass = auditActionBadgeClass($log['action']);
                                    ?>
                                    <tr>
                                        <td class="text-nowrap">
                                            <?= htmlspecialchars(date('M j, Y', strtotime($log['created_at']))) ?>
                                            <div class="audit-time"><?= htmlspecialchars(date('g:i A', strtotime($log['created_at']))) ?></div>
                                        </td>
                                        <td><?= $actorName !== '' ? htmlspecialchars($actorName) : '<span class="text-muted">—</span>' ?></td>
                                        <td><?= $log['actor_user_id'] !== null ? (int)$log['actor_user_id'] : '<span class="text-muted">—</span>' ?></td>
                                        <td><?= !empty($log['email']) ? htmlspecialchars($log['email']) : '<span class="text-muted">—</span>' ?></td>
                                        <td>
                                            <?php if (!empty($log['actor_type'])): ?>
                                                <span class="actor-type-pill <?= auditRoleBadgeClass($log['actor_type']) ?>">
                                                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $log['actor_type']))) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= $badgeClass ?>"><?= htmlspecialchars(auditActionLabel($log['action'], $log['entity_type'])) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars(ucfirst($log['entity_type'])) ?></td>
                                        <td><?= $log['entity_id'] !== null ? (int)$log['entity_id'] : '<span class="text-muted">—</span>' ?></td>
                                        <td class="text-end">
                                            <?php if (!empty($log['old_values']) || !empty($log['new_values'])): ?>
                                                <button type="button"
                                                    class="btn btn-outline-muted btn-sm px-2 py-1 view-log-btn"
                                                    style="border-radius:3px; font-size:.72rem; letter-spacing:.04em; text-transform:uppercase;"
                                                    data-action="<?= htmlspecialchars(auditActionLabel($log['action'], $log['entity_type']), ENT_QUOTES) ?>"
                                                    data-entity="<?= htmlspecialchars(ucfirst($log['entity_type']) . ($log['entity_id'] !== null ? ' #' . (int)$log['entity_id'] : ''), ENT_QUOTES) ?>"
                                                    data-actor="<?= htmlspecialchars($actorName !== '' ? $actorName : ucfirst($log['actor_type'] ?? 'System'), ENT_QUOTES) ?>"
                                                    data-timestamp="<?= htmlspecialchars(date('M j, Y g:i A', strtotime($log['created_at'])), ENT_QUOTES) ?>"
                                                    data-old="<?= htmlspecialchars($log['old_values'] ?? '', ENT_QUOTES) ?>"
                                                    data-new="<?= htmlspecialchars($log['new_values'] ?? '', ENT_QUOTES) ?>">
                                                    View Changes
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size:.75rem;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <!-- ============ PAGINATION ============ -->
                        <?php if ($total_pages > 1): ?>
                            <div class="audit-pagination">
                                <div class="audit-pagination-info">
                                    Page <?= $page ?> of <?= $total_pages ?>
                                </div>
                                <div class="audit-pagination-controls">
                                    <a href="<?= buildQueryString(['page' => max(1, $page - 1)]) ?>"
                                        class="btn btn-outline-muted btn-sm px-3 py-1 <?= $page <= 1 ? 'disabled' : '' ?>">‹ Prev</a>
                                    <a href="<?= buildQueryString(['page' => min($total_pages, $page + 1)]) ?>"
                                        class="btn btn-outline-muted btn-sm px-3 py-1 <?= $page >= $total_pages ? 'disabled' : '' ?>">Next ›</a>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <!-- ============ VIEW CHANGES MODAL ============ -->
    <div class="modal fade" id="logModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background:var(--cg-bg-alt); border:1px solid var(--cg-hair); color:var(--cg-text);">
                <div class="modal-header" style="border-bottom-color:var(--cg-hair);">
                    <h5 class="modal-title" id="logModalTitle">Change Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="audit-modal-meta" id="logModalMeta"></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="audit-diff-label">Before</div>
                            <pre class="audit-diff-block" id="logModalOld"></pre>
                        </div>
                        <div class="col-md-6">
                            <div class="audit-diff-label">After</div>
                            <pre class="audit-diff-block" id="logModalNew"></pre>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top-color:var(--cg-hair);">
                    <button type="button" class="btn btn-outline-muted px-3 py-2" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const logModalEl = document.getElementById('logModal');
        const logModal = new bootstrap.Modal(logModalEl);
        const logModalTitle = document.getElementById('logModalTitle');
        const logModalMeta = document.getElementById('logModalMeta');
        const logModalOld = document.getElementById('logModalOld');
        const logModalNew = document.getElementById('logModalNew');

        function prettyJson(raw) {
            if (!raw) return '(none)';
            try {
                return JSON.stringify(JSON.parse(raw), null, 2);
            } catch (e) {
                return raw; // not JSON — show as-is
            }
        }

        document.querySelectorAll('.view-log-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                logModalTitle.textContent = btn.dataset.action + ' — ' + btn.dataset.entity;
                logModalMeta.textContent = 'By ' + btn.dataset.actor + ' on ' + btn.dataset.timestamp;
                logModalOld.textContent = prettyJson(btn.dataset.old);
                logModalNew.textContent = prettyJson(btn.dataset.new);
                logModal.show();
            });
        });

        // Disabled pagination links shouldn't navigate
        document.querySelectorAll('.audit-pagination-controls .disabled').forEach(a => {
            a.addEventListener('click', e => e.preventDefault());
        });
    </script>
</body>

</html>