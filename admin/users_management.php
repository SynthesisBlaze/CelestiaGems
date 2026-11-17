<?php
require_once __DIR__ . '/../functions/permissions.php';
cg_require_page('users.view');

// Include your database connection
require_once __DIR__ . '/../functions/db_connection.php'; // Adjust this path based on your folder structure

$current_admin_page = 'users';
$page_title = 'Users';
$admin_user = cg_admin_user();

// --- Fetch Roles (for the dropdown + label lookup) ---
$stmtRoles = $pdo->prepare("SELECT role_id, role_name FROM roles ORDER BY role_id ASC");
$stmtRoles->execute();
$roles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

$roleLookup = [];
foreach ($roles as $r) {
    $roleLookup[$r['role_id']] = $r['role_name'];
}

// --- Fetch Users ---
$stmtUsers = $pdo->prepare("
    SELECT user_id, first_name, last_name, email, phone, role_id, is_email_verified, created_at
    FROM users
    ORDER BY created_at DESC
");
$stmtUsers->execute();
$users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// --- What the signed-in user is allowed to do here ---
$actorRole       = cg_current_role();
$actorId         = (int) $_SESSION['user_id'];
$assignableRoles = cg_assignable_roles($actorRole);
$manageableNames = implode(', ', array_map('cg_role_name', $assignableRoles));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users — Celestia Gems Admin</title>
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

                <?php if (isset($_GET['saved'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= $_GET['saved'] === 'created' ? 'User created successfully!' : 'User updated successfully!' ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['deleted'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        User deleted.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_GET['error']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="admin-panel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="admin-panel-title mb-0">All Users</div>
                        <button type="button" class="btn btn-gold px-3 py-2" id="addUserBtn">+ Add User</button>
                    </div>
                    <p class="text-muted mb-3" style="font-size:.82rem;">
                        As <?= htmlspecialchars(cg_role_name($actorRole)) ?>, you can create and manage
                        <?= htmlspecialchars($manageableNames) ?> accounts. Other accounts are shown read-only.
                    </p>

                    <?php if (empty($users)): ?>
                        <div class="empty-state">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                                <circle cx="9" cy="8" r="3.2" />
                                <path d="M2.5 20c.7-3.6 3.4-6 6.5-6s5.8 2.4 6.5 6" />
                                <circle cx="17.5" cy="9" r="2.4" />
                                <path d="M15.5 13.2c2.4.4 4.3 2.4 4.8 5" />
                            </svg>
                            <div class="title">No users yet</div>
                            <div class="hint">Click "Add User" above to create the first account.</div>
                        </div>
                    <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Role</th>
                                    <th>Verified</th>
                                    <th>Joined</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <?php
                                    $isSelf     = (int) $u['user_id'] === $actorId;
                                    $canManage  = cg_can_manage_role($actorRole, (int) $u['role_id']);
                                    $canEdit    = $isSelf || $canManage;
                                    $canDelete  = !$isSelf && $canManage;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars(trim($u['first_name'] . ' ' . $u['last_name'])) ?></td>
                                        <td><?= htmlspecialchars($u['email']) ?></td>
                                        <td><?= htmlspecialchars($u['phone'] ?? '—') ?></td>
                                        <td>
                                            <?= htmlspecialchars($roleLookup[$u['role_id']] ?? cg_role_name((int) $u['role_id'])) ?>
                                            <?php if ($isSelf): ?><span class="text-muted" style="font-size:.75rem;">(you)</span><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($u['is_email_verified']): ?>
                                                <span class="status-badge delivered">Verified</span>
                                            <?php else: ?>
                                                <span class="status-badge pending">Unverified</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime($u['created_at']))) ?></td>
                                        <td class="text-end text-nowrap">
                                            <?php if (!$canEdit): ?>
                                                <span class="text-muted" style="font-size:.72rem; letter-spacing:.04em; text-transform:uppercase;" title="Only a Super Admin can change this account">Protected</span>
                                            <?php else: ?>
                                            <button type="button"
                                                class="btn btn-outline-muted btn-sm px-2 py-1 edit-user-btn"
                                                data-user-id="<?= (int)$u['user_id'] ?>"
                                                data-first-name="<?= htmlspecialchars($u['first_name'], ENT_QUOTES) ?>"
                                                data-last-name="<?= htmlspecialchars($u['last_name'], ENT_QUOTES) ?>"
                                                data-email="<?= htmlspecialchars($u['email'], ENT_QUOTES) ?>"
                                                data-phone="<?= htmlspecialchars($u['phone'] ?? '', ENT_QUOTES) ?>"
                                                data-role-id="<?= (int)$u['role_id'] ?>"
                                                data-verified="<?= (int)$u['is_email_verified'] ?>"
                                                data-self="<?= $isSelf ? '1' : '0' ?>"
                                                style="border-radius:3px; font-size:.72rem; letter-spacing:.04em; text-transform:uppercase;">
                                                Edit
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($canDelete): ?>
                                                <form action="../functions/users-delete.php" method="post" class="d-inline delete-user-form">
                                                    <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger-soft btn-sm px-2 py-1"
                                                        style="border-radius:3px; font-size:.72rem; letter-spacing:.04em; text-transform:uppercase;">
                                                        Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <!-- ============ ADD / EDIT USER MODAL ============ -->
    <div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="../functions/users-save.php" method="post" class="modal-content"
                style="background:var(--cg-bg-alt); border:1px solid var(--cg-hair); color:var(--cg-text);">
                <div class="modal-header" style="border-bottom-color:var(--cg-hair);">
                    <h5 class="modal-title" id="userModalTitle">Add User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="fUserId" value="">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" name="first_name" id="fFirstName" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" name="last_name" id="fLastName" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" id="fEmail" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Phone Number</label>
                        <input type="text" class="form-control" name="phone" id="fPhone">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select class="form-select" name="role_id" id="fRoleId" required>
                            <?php foreach ($roles as $r): ?>
                                <?php if (in_array((int) $r['role_id'], $assignableRoles, true)): ?>
                                    <option value="<?= (int)$r['role_id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <!-- Used instead of the select when editing your own account (your role can't change) -->
                        <input type="hidden" name="role_id" id="fRoleLocked" disabled>
                        <div class="form-text" id="fRoleHint" hidden>You can't change your own role.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" class="form-control" name="password" id="fPassword" minlength="8" autocomplete="new-password">
                        <small class="text-muted" id="fPasswordHint">Required for new users. Leave blank when editing to keep the current password.</small>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_email_verified" id="fVerified" value="1">
                        <label class="form-check-label" for="fVerified" style="font-size:.85rem; color:var(--cg-muted);">
                            Mark email as verified
                        </label>
                    </div>
                </div>
                <div class="modal-footer" style="border-top-color:var(--cg-hair);">
                    <button type="button" class="btn btn-outline-muted px-3 py-2" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gold px-3 py-2">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const userModalEl = document.getElementById('userModal');
        const userModal = new bootstrap.Modal(userModalEl);
        const modalTitle = document.getElementById('userModalTitle');
        const fUserId = document.getElementById('fUserId');
        const fFirstName = document.getElementById('fFirstName');
        const fLastName = document.getElementById('fLastName');
        const fEmail = document.getElementById('fEmail');
        const fPhone = document.getElementById('fPhone');
        const fRoleId = document.getElementById('fRoleId');
        const fRoleLocked = document.getElementById('fRoleLocked');
        const fRoleHint = document.getElementById('fRoleHint');
        const ROLE_NAMES = <?= json_encode(CG_ROLE_NAMES) ?>;

        function lockRole(roleId) {
            // Show your own role as a fixed, disabled option and submit it via the hidden field.
            let opt = fRoleId.querySelector('option[data-locked]');
            if (!opt) {
                opt = document.createElement('option');
                opt.dataset.locked = '1';
                fRoleId.appendChild(opt);
            }
            opt.value = roleId;
            opt.textContent = ROLE_NAMES[roleId] || 'Current role';
            fRoleId.value = roleId;
            fRoleId.disabled = true;
            fRoleLocked.value = roleId;
            fRoleLocked.disabled = false;
            fRoleHint.hidden = false;
        }

        function unlockRole() {
            const opt = fRoleId.querySelector('option[data-locked]');
            if (opt) opt.remove();
            fRoleId.disabled = false;
            fRoleLocked.disabled = true;
            fRoleHint.hidden = true;
        }
        const fPassword = document.getElementById('fPassword');
        const fPasswordHint = document.getElementById('fPasswordHint');
        const fVerified = document.getElementById('fVerified');

        function resetForm() {
            fUserId.value = '';
            fFirstName.value = '';
            fLastName.value = '';
            fEmail.value = '';
            fPhone.value = '';
            unlockRole();
            fRoleId.selectedIndex = 0;
            fPassword.value = '';
            fVerified.checked = false;
        }

        document.getElementById('addUserBtn').addEventListener('click', () => {
            resetForm();
            modalTitle.textContent = 'Add User';
            fPassword.required = true;
            fPasswordHint.textContent = 'Required for new users.';
            userModal.show();
        });

        document.querySelectorAll('.edit-user-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                resetForm();
                modalTitle.textContent = 'Edit User';
                fPassword.required = false;
                fPasswordHint.textContent = 'Leave blank to keep the current password.';

                fUserId.value = btn.dataset.userId;
                fFirstName.value = btn.dataset.firstName;
                fLastName.value = btn.dataset.lastName;
                fEmail.value = btn.dataset.email;
                fPhone.value = btn.dataset.phone;
                if (btn.dataset.self === '1') {
                    lockRole(btn.dataset.roleId);
                } else {
                    fRoleId.value = btn.dataset.roleId;
                }
                fVerified.checked = btn.dataset.verified === '1';

                userModal.show();
            });
        });

        // Confirm before deleting
        document.querySelectorAll('.delete-user-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!confirm('Delete this user? This cannot be undone.')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>

</html>