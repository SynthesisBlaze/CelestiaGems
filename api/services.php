<?php
/**
 * GET /api/services.php
 * Returns every service / custom-jewelry offering, joined to its category
 * name — the same query the web app's pages/service.php uses to build its
 * service cards.
 */
require_once __DIR__ . '/_bootstrap.php';

$stmt = $pdo->query("
    SELECT
        s.service_id,
        s.category_id,
        s.name,
        s.description,
        s.base_price,
        c.name AS category
    FROM services s
    JOIN categories c ON s.category_id = c.category_id
    ORDER BY s.category_id, s.service_id
");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

json_out(['success' => true, 'services' => $services]);
