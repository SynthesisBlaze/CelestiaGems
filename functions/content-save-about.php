<?php
session_start();

require_once __DIR__ . '/permissions.php';
cg_require_page('content.manage');

require_once __DIR__ . '/db_connection.php'; // Adjust this path if db_connection.php is located elsewhere
require_once __DIR__ . '/audit_logger.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $about_heading = $_POST['about_heading'] ?? '';
    $about_body = $_POST['about_body'] ?? '';
    $vision_heading = $_POST['vision_heading'] ?? '';
    $vision_text = $_POST['vision_text'] ?? '';
    $mission_heading = $_POST['mission_heading'] ?? '';
    $mission_text = $_POST['mission_text'] ?? '';

    // Check if a record already exists — fetch the FULL row so we have a
    // real "before" snapshot for the audit log, not just the id.
    $stmtCheck = $pdo->query("SELECT * FROM about_page LIMIT 1");
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    $newValues = [
        'about_heading'   => $about_heading,
        'about_body'      => $about_body,
        'vision_heading'  => $vision_heading,
        'vision_text'     => $vision_text,
        'mission_heading' => $mission_heading,
        'mission_text'    => $mission_text,
    ];

    $actorId   = (int)$_SESSION['user_id'];
    $actorType = admin_actor_type((int)$_SESSION['role_id']);

    if ($existing) {
        // Update the existing record
        $stmt = $pdo->prepare("UPDATE about_page SET 
            about_heading = ?, about_body = ?, 
            vision_heading = ?, vision_text = ?, 
            mission_heading = ?, mission_text = ? 
            WHERE id = ?");
        $stmt->execute([$about_heading, $about_body, $vision_heading, $vision_text, $mission_heading, $mission_text, $existing['id']]);

        log_audit($pdo, $actorId, $actorType, 'update', 'about_page', (int)$existing['id'], $existing, $newValues);
    } else {
        // Insert a new record if the table is empty
        $stmt = $pdo->prepare("INSERT INTO about_page (about_heading, about_body, vision_heading, vision_text, mission_heading, mission_text) VALUES (?, ?, ?, ?, ?, ?) RETURNING id");
        $stmt->execute([$about_heading, $about_body, $vision_heading, $vision_text, $mission_heading, $mission_text]);
        $newAboutId = (int) $stmt->fetchColumn();

        log_audit($pdo, $actorId, $actorType, 'create', 'about_page', $newAboutId, null, $newValues);
    }

    // Redirect back with success parameter
    header("Location: ../admin/content.php?saved=1");
    exit;
}
?>