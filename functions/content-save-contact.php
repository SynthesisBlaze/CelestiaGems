<?php
session_start();

require_once __DIR__ . '/permissions.php';
cg_require_page('content.manage');

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/audit_logger.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Fetch the current row first so we have a real "before" snapshot
    // for the audit log (this is an UPSERT, so id=1 may or may not exist yet).
    $stmtExisting = $pdo->prepare("SELECT * FROM contact_page WHERE id = 1");
    $stmtExisting->execute();
    $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC);

    // We use an UPSERT (INSERT ... ON CONFLICT DO UPDATE) assuming id=1 is your single contact page record
    $sql = "INSERT INTO contact_page 
            (id, heading, subtext, store_name, store_address, phone, email_general, email_custom, hours_weekday, hours_sunday) 
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (id) DO UPDATE SET 
            heading=EXCLUDED.heading, subtext=EXCLUDED.subtext, store_name=EXCLUDED.store_name, 
            store_address=EXCLUDED.store_address, phone=EXCLUDED.phone, email_general=EXCLUDED.email_general, 
            email_custom=EXCLUDED.email_custom, hours_weekday=EXCLUDED.hours_weekday, hours_sunday=EXCLUDED.hours_sunday";

    $stmt = $pdo->prepare($sql);

    $newValues = [
        'heading'        => $_POST['heading'] ?? '',
        'subtext'        => $_POST['subtext'] ?? '',
        'store_name'     => $_POST['store_name'] ?? '',
        'store_address'  => $_POST['store_address'] ?? '',
        'phone'          => $_POST['phone'] ?? '',
        'email_general'  => $_POST['email_general'] ?? '',
        'email_custom'   => $_POST['email_custom'] ?? '',
        'hours_weekday'  => $_POST['hours_weekday'] ?? '',
        'hours_sunday'   => $_POST['hours_sunday'] ?? '',
    ];

    $stmt->execute([
        $newValues['heading'],
        $newValues['subtext'],
        $newValues['store_name'],
        $newValues['store_address'],
        $newValues['phone'],
        $newValues['email_general'],
        $newValues['email_custom'],
        $newValues['hours_weekday'],
        $newValues['hours_sunday'],
    ]);

    log_audit(
        $pdo,
        (int)$_SESSION['user_id'],
        admin_actor_type((int)$_SESSION['role_id']),
        $existing ? 'update' : 'create',
        'contact_page',
        1,
        $existing ?: null,
        $newValues
    );

    // Redirect back to the content page with a success flag
    header("Location: /../admin/content.php?saved=1");
    exit;
}
?>