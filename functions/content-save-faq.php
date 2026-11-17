<?php
session_start();

require_once __DIR__ . '/permissions.php';
cg_require_page('content.manage');

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/audit_logger.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Capture the full list as it stood before the replace, for the audit log.
    $oldFaqs = $pdo->query("SELECT question, answer, sort_order FROM faqs ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Clear out old FAQs to replace with the newly ordered list
    $pdo->query("DELETE FROM faqs");

    $newFaqs = [];

    // Check if any questions were submitted
    if (!empty($_POST['faq_question'])) {
        
        $stmt = $pdo->prepare("INSERT INTO faqs (question, answer, sort_order, is_published) VALUES (?, ?, ?, 1)");
        
        // Loop through the arrays sent by the form
        foreach ($_POST['faq_question'] as $index => $question) {
            $answer = $_POST['faq_answer'][$index] ?? '';
            
            // Only insert if it isn't blank
            if (trim($question) !== '' && trim($answer) !== '') {
                // sort_order is based on the array index so they stay in order
                $stmt->execute([$question, $answer, $index]);
                $newFaqs[] = ['question' => $question, 'answer' => $answer, 'sort_order' => $index];
            }
        }
    }

    // One aggregate entry for the whole replace, rather than one per row —
    // this is a delete-and-reinsert operation, not per-row edits.
    log_audit(
        $pdo,
        (int)$_SESSION['user_id'],
        admin_actor_type((int)$_SESSION['role_id']),
        'update',
        'faq',
        null,
        ['faqs' => $oldFaqs],
        ['faqs' => $newFaqs]
    );

    // Redirect back to the content page with a success flag
    header("Location: /../admin/content.php?saved=1");
    exit;
}
?>