<?php
require_once __DIR__ . '/../functions/permissions.php';
cg_require_page('settings.manage');

require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/settings_helper.php';

$current_admin_page = 'settings';
$page_title = 'Settings';
$admin_user = cg_admin_user();

$s = get_all_settings($pdo);
$freeShippingEnabled = ($s['free_shipping_enabled'] ?? '0') === '1';
$freeShippingThreshold = $s['free_shipping_threshold'] ?? '0';
$shippingFee = $s['shipping_fee'] ?? '0';
$codEnabled = ($s['payment_cod_enabled'] ?? '0') === '1';
$paymongoEnabled = ($s['payment_paymongo_enabled'] ?? '0') === '1';
$announcementEnabled = ($s['announcement_enabled'] ?? '0') === '1';
$announcementText = $s['announcement_text'] ?? '';
$twoFaEnabled = ($s['two_fa_enabled'] ?? '0') === '1';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — Celestia Gems Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/admin.css">
</head>

<body>

    <div class="admin-shell">
        <?php include __DIR__ . '/../components/admin_sidebar.php'; ?>

        <div class="admin-main">
            <?php include __DIR__ . '/../components/admin_topbar.php'; ?>

            <div class="admin-content">

                <?php if (isset($_GET['saved'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        Settings saved.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['sent'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        Notification sent to <?= (int) $_GET['sent'] ?> user<?= (int) $_GET['sent'] === 1 ? '' : 's' ?>.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_GET['error']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form action="../functions/settings-save.php" method="post" id="settingsForm">

                    <!-- ============ SHIPPING ============ -->
                    <div class="admin-panel mb-4">
                        <div class="admin-panel-title">Shipping</div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" name="free_shipping_enabled" id="freeShippingEnabled" value="1" <?= $freeShippingEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="freeShippingEnabled">Enable free shipping above a threshold</label>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Free shipping threshold (₱)</label>
                                <input type="number" class="form-control" name="free_shipping_threshold" min="0" step="0.01" value="<?= htmlspecialchars($freeShippingThreshold) ?>">
                                <small class="form-hint">Orders at or above this subtotal ship free.</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Standard shipping fee (₱)</label>
                                <input type="number" class="form-control" name="shipping_fee" min="0" step="0.01" value="<?= htmlspecialchars($shippingFee) ?>">
                                <small class="form-hint">Charged when the order is below the threshold, or free shipping is off entirely.</small>
                            </div>
                        </div>
                    </div>

                    <!-- ============ PAYMENT METHODS ============ -->
                    <div class="admin-panel mb-4">
                        <div class="admin-panel-title">Payment Methods</div>
                        <p class="form-hint mb-3">At least one method must stay enabled, or customers won't be able to check out.</p>

                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="payment_cod_enabled" id="codEnabled" value="1" <?= $codEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="codEnabled">Cash on Delivery</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="payment_paymongo_enabled" id="paymongoEnabled" value="1" <?= $paymongoEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="paymongoEnabled">PayMongo (GCash / card / GrabPay / Maya)</label>
                        </div>
                    </div>

                    <!-- ============ ANNOUNCEMENT ============ -->
                    <div class="admin-panel mb-4">
                        <div class="admin-panel-title">Topbar Announcement</div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" name="announcement_enabled" id="announcementEnabled" value="1" <?= $announcementEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="announcementEnabled">Show announcement in the site topbar</label>
                        </div>

                        <label class="form-label">Announcement text</label>
                        <textarea class="form-control" name="announcement_text" rows="2" maxlength="200" placeholder="e.g. Free shipping on orders over ₱5,000 this week only!"><?= htmlspecialchars($announcementText) ?></textarea>
                        <small class="form-hint">Turning this on (or changing the text while it's on) notifies every customer on their account page.</small>
                    </div>

                    <!-- ============ SECURITY ============ -->
                    <div class="admin-panel mb-4">
                        <div class="admin-panel-title">Security</div>

                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="two_fa_enabled" id="twoFaEnabled" value="1" <?= $twoFaEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="twoFaEnabled">Require email verification code (2FA) at login</label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-gold px-4 py-2">Save Settings</button>
                </form>

                <!-- ============ SEND NOTIFICATION ============ -->
                <div class="admin-panel mt-4" style="max-width: 640px;">
                    <div class="admin-panel-title">Send Notification</div>
                    <p class="form-hint mb-3">Sends a one-time message to every customer's Notifications tab. This isn't a setting — it fires immediately and isn't remembered anywhere else.</p>

                    <form action="../functions/notifications-broadcast.php" method="post" id="broadcastForm">
                        <div class="mb-3">
                            <label class="form-label">Header</label>
                            <input type="text" class="form-control" name="title" maxlength="150" placeholder="e.g. Hello User" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Message</label>
                            <textarea class="form-control" name="message" rows="3" placeholder="e.g. Lorem ipsum..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-gold px-4 py-2">Send to Notifications</button>
                    </form>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('settingsForm').addEventListener('submit', function(e) {
            const codChecked = document.getElementById('codEnabled').checked;
            const paymongoChecked = document.getElementById('paymongoEnabled').checked;
            if (!codChecked && !paymongoChecked) {
                e.preventDefault();
                alert("At least one payment method must stay enabled — customers need a way to pay.");
            }
        });

        document.getElementById('broadcastForm').addEventListener('submit', function(e) {
            if (!confirm('Send this notification to every customer? This goes out immediately and can\'t be recalled.')) {
                e.preventDefault();
            }
        });
    </script>
</body>

</html>