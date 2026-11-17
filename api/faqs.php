<?php
/**
 * GET /api/faqs.php
 *
 * The published FAQs, in the admin's chosen order — the same query
 * pages/faq.php runs. Public: these are the answers people look for
 * before they have an account.
 *
 * An empty list is a normal answer, not an error. The website shows
 * "FAQs coming soon" when nothing is published yet, and the app has its
 * own version of that state, so this just reports the count honestly
 * rather than inventing filler.
 */
require_once __DIR__ . '/_bootstrap.php';

try {
    // Only the columns the website itself selects. The table's primary
    // key is never referenced anywhere in the web code, so naming it here
    // would be a guess that fails as a 500 rather than a missing field.
    $stmt = $pdo->query("
        SELECT question, answer
        FROM faqs
        WHERE is_published = 1
        ORDER BY sort_order ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('faqs.php error: ' . $e->getMessage());
    $rows = [];
}

$faqs = array_values(array_map(fn($r) => [
    'question' => $r['question'],
    'answer'   => $r['answer'],
], $rows));

json_out(['success' => true, 'faqs' => $faqs, 'count' => count($faqs)]);
