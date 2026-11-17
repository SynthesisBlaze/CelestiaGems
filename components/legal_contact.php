<?php
/**
 * Store contact details for the Privacy Policy and Terms pages, pulled from
 * the Contact page content (Admin → Content) so there's one place to edit them.
 */
$legalContact = [
    'store_name'    => 'Celestia Gems',
    'store_address' => '',
    'email'         => '',
    'phone'         => '',
];
try {
    $legalStmt = $pdo->query("SELECT store_name, store_address, email_general, phone FROM contact_page LIMIT 1");
    $legalRow = $legalStmt ? $legalStmt->fetch(PDO::FETCH_ASSOC) : false;
    if ($legalRow) {
        $legalContact['store_name']    = $legalRow['store_name'] ?: 'Celestia Gems';
        $legalContact['store_address'] = $legalRow['store_address'] ?? '';
        $legalContact['email']         = $legalRow['email_general'] ?? '';
        $legalContact['phone']         = $legalRow['phone'] ?? '';
    }
} catch (PDOException $e) {
    error_log('legal_contact: ' . $e->getMessage());
}
?>
<div class="legal-contact">
    <div><strong><?= htmlspecialchars($legalContact['store_name']) ?></strong></div>
    <?php if ($legalContact['store_address'] !== ''): ?>
        <div><?= htmlspecialchars($legalContact['store_address']) ?></div>
    <?php endif; ?>
    <?php if ($legalContact['email'] !== ''): ?>
        <div>Email: <a href="mailto:<?= htmlspecialchars($legalContact['email']) ?>"><?= htmlspecialchars($legalContact['email']) ?></a></div>
    <?php endif; ?>
    <?php if ($legalContact['phone'] !== ''): ?>
        <div>Phone: <?= htmlspecialchars($legalContact['phone']) ?></div>
    <?php endif; ?>
    <div>Or send us a message through our <a href="/pages/contactus.php">Contact page</a>.</div>
</div>
