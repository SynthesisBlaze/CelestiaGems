<?php
/**
 * GET /api/about.php
 * Returns the single About Us content row — the same table the web app's
 * pages/aboutus.php reads via `SELECT * FROM about_page LIMIT 1`.
 */
require_once __DIR__ . '/_bootstrap.php';

$stmt = $pdo->prepare("SELECT * FROM about_page LIMIT 1");
$stmt->execute();
$about = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$about) {
    json_error('About content not found.', 404);
}

json_out(['success' => true, 'about' => $about]);
