<?php
session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/audit_logger.php';
require_once __DIR__ . '/notifier.php';
require_once __DIR__ . '/mailer.php';

header('Content-Type: application/json');

require_once __DIR__ . '/permissions.php';
cg_require_json('feedback.view');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$action   = $_POST['action'] ?? '';
$actor_id = (int) $_SESSION['user_id'];
$actor_type = admin_actor_type((int) $_SESSION['role_id']);

// Per-action permissions: replying is open to Staff; deleting reviews is Admin+.
cg_require_json($action === 'delete_review' ? 'feedback.delete_review' : 'feedback.reply');

try {
    if ($action === 'update_inquiry_status') {
        $INQUIRY_STATUSES = ['Unread', 'Read', 'Resolved'];

        $inquiryId = isset($_POST['inquiry_id']) ? (int) $_POST['inquiry_id'] : 0;
        $status = $_POST['status'] ?? '';

        if ($inquiryId <= 0 || !in_array($status, $INQUIRY_STATUSES, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }

        $oldStmt = $pdo->prepare("SELECT status FROM contact_inquiries WHERE inquiry_id = ?");
        $oldStmt->execute([$inquiryId]);
        $oldStatus = $oldStmt->fetchColumn();

        if ($oldStatus === false) {
            echo json_encode(['success' => false, 'message' => 'Inquiry not found.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE contact_inquiries SET status = ? WHERE inquiry_id = ?");
        $stmt->execute([$status, $inquiryId]);

        log_audit(
            $pdo,
            $actor_id,
            $actor_type,
            'update',
            'contact_inquiry',
            $inquiryId,
            ['status' => $oldStatus],
            ['status' => $status]
        );

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_review') {
        $reviewId = isset($_POST['review_id']) ? (int) $_POST['review_id'] : 0;

        if ($reviewId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }

        $reviewStmt = $pdo->prepare(
            "SELECT product_id, user_id, rating, comment FROM product_reviews WHERE review_id = ?"
        );
        $reviewStmt->execute([$reviewId]);
        $reviewRow = $reviewStmt->fetch(PDO::FETCH_ASSOC);

        if (!$reviewRow) {
            echo json_encode(['success' => false, 'message' => 'Review not found.']);
            exit;
        }

        $pdo->prepare("DELETE FROM product_reviews WHERE review_id = ?")->execute([$reviewId]);

        log_audit(
            $pdo,
            $actor_id,
            $actor_type,
            'delete',
            'product_review',
            $reviewId,
            $reviewRow,
            null
        );

        echo json_encode(['success' => true]);
        exit;
    }

    /* -----------------------------------------------------------------
     * Contact inquiry replies (emailed to the sender, kept as a thread)
     * ----------------------------------------------------------------- */
    if ($action === 'get_inquiry_replies') {
        $inquiryId = isset($_POST['inquiry_id']) ? (int) $_POST['inquiry_id'] : 0;
        if ($inquiryId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT r.reply_id, r.message, r.email_sent, r.created_at,
                   u.first_name, u.last_name
            FROM contact_inquiry_replies r
            LEFT JOIN users u ON u.user_id = r.admin_user_id
            WHERE r.inquiry_id = ?
            ORDER BY r.created_at ASC, r.reply_id ASC
        ");
        $stmt->execute([$inquiryId]);

        $replies = array_map(fn($row) => [
            'id'      => (int) $row['reply_id'],
            'message' => $row['message'],
            'by'      => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Admin',
            'date'    => date('M j, Y g:i A', strtotime($row['created_at'])),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode(['success' => true, 'replies' => $replies]);
        exit;
    }

    if ($action === 'reply_inquiry') {
        $inquiryId    = isset($_POST['inquiry_id']) ? (int) $_POST['inquiry_id'] : 0;
        $message      = trim($_POST['message'] ?? '');
        $markResolved = ($_POST['mark_resolved'] ?? '') === '1';

        if ($inquiryId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }
        if ($message === '') {
            echo json_encode(['success' => false, 'message' => 'Write a reply before sending.']);
            exit;
        }
        if (mb_strlen($message) > 5000) {
            echo json_encode(['success' => false, 'message' => 'Replies can be up to 5,000 characters.']);
            exit;
        }

        $inqStmt = $pdo->prepare("SELECT inquiry_id, full_name, email, subject, message, status FROM contact_inquiries WHERE inquiry_id = ?");
        $inqStmt->execute([$inquiryId]);
        $inquiry = $inqStmt->fetch(PDO::FETCH_ASSOC);

        if (!$inquiry) {
            echo json_encode(['success' => false, 'message' => 'Inquiry not found.']);
            exit;
        }

        $adminFirst = trim($_SESSION['first_name'] ?? '');
        $signature  = $adminFirst !== '' ? $adminFirst . ', Celestia Gems' : 'The Celestia Gems team';
        $subjectLine = 'Re: ' . ($inquiry['subject'] !== '' ? $inquiry['subject'] : 'Your message to Celestia Gems');

        $html = celestia_email_layout('
            <p style="margin:0 0 16px;">Hi ' . htmlspecialchars($inquiry['full_name']) . ',</p>
            <div style="margin:0 0 20px;">' . nl2br(htmlspecialchars($message)) . '</div>
            <p style="margin:0 0 24px;">— ' . htmlspecialchars($signature) . '</p>
            <div style="border-left:3px solid #EADFCB; padding:4px 0 4px 14px; color:#6B6457; font-size:13px;">
                <div style="margin-bottom:6px; font-weight:bold;">Your original message</div>
                ' . nl2br(htmlspecialchars($inquiry['message'])) . '
            </div>
            <p style="margin:20px 0 0; color:#8A8378; font-size:13px;">You can reply to this email to continue the conversation.</p>
        ');

        // Send first: if the email doesn't go out, nothing is saved, so the
        // admin can simply try again without a phantom "sent" reply.
        if (!send_brevo_email($inquiry['email'], $inquiry['full_name'], $subjectLine, $html)) {
            echo json_encode(['success' => false, 'message' => 'The email could not be sent, so the reply was not saved. Please try again in a moment.']);
            exit;
        }

        $ins = $pdo->prepare("
            INSERT INTO contact_inquiry_replies (inquiry_id, admin_user_id, message, email_sent, created_at)
            VALUES (?, ?, ?, 'true', NOW())
        ");
        $ins->execute([$inquiryId, $actor_id, $message]);

        $newStatus = $markResolved ? 'Resolved' : ($inquiry['status'] === 'Unread' ? 'Read' : $inquiry['status']);
        if ($newStatus !== $inquiry['status']) {
            $pdo->prepare("UPDATE contact_inquiries SET status = ? WHERE inquiry_id = ?")->execute([$newStatus, $inquiryId]);
        }

        // If the sender has an account, also drop it in their Notifications tab.
        $userStmt = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $userStmt->execute([$inquiry['email']]);
        $customerId = $userStmt->fetchColumn();
        if ($customerId !== false) {
            notify_user(
                $pdo,
                (int) $customerId,
                'inquiry_reply',
                'We replied to your message',
                'Re: ' . $inquiry['subject'] . ' — ' . mb_strimwidth($message, 0, 160, '…'),
                'contact_inquiry',
                $inquiryId
            );
        }

        log_audit(
            $pdo,
            $actor_id,
            $actor_type,
            'reply',
            'contact_inquiry',
            $inquiryId,
            ['status' => $inquiry['status']],
            ['status' => $newStatus, 'reply' => $message]
        );

        echo json_encode(['success' => true, 'status' => $newStatus]);
        exit;
    }

    /* -----------------------------------------------------------------
     * Product review replies (one public reply per review)
     * ----------------------------------------------------------------- */
    if ($action === 'save_review_reply') {
        $reviewId = isset($_POST['review_id']) ? (int) $_POST['review_id'] : 0;
        $reply    = trim($_POST['reply'] ?? '');

        if ($reviewId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }
        if ($reply === '') {
            echo json_encode(['success' => false, 'message' => 'Write a reply before saving.']);
            exit;
        }
        if (mb_strlen($reply) > 2000) {
            echo json_encode(['success' => false, 'message' => 'Replies can be up to 2,000 characters.']);
            exit;
        }

        $revStmt = $pdo->prepare("
            SELECT r.review_id, r.product_id, r.user_id, r.admin_reply,
                   p.name AS product_name, u.email, u.first_name, u.last_name
            FROM product_reviews r
            JOIN products p ON p.product_id = r.product_id
            JOIN users u ON u.user_id = r.user_id
            WHERE r.review_id = ?
        ");
        $revStmt->execute([$reviewId]);
        $review = $revStmt->fetch(PDO::FETCH_ASSOC);

        if (!$review) {
            echo json_encode(['success' => false, 'message' => 'Review not found.']);
            exit;
        }

        $isNewReply = $review['admin_reply'] === null || trim($review['admin_reply']) === '';

        $pdo->prepare("
            UPDATE product_reviews
            SET admin_reply = ?, admin_reply_at = NOW(), admin_reply_by = ?
            WHERE review_id = ?
        ")->execute([$reply, $actor_id, $reviewId]);

        // Let the reviewer know the first time we respond (not on later edits).
        if ($isNewReply) {
            notify_user(
                $pdo,
                (int) $review['user_id'],
                'review_reply',
                'We replied to your review',
                'Celestia Gems responded to your review of ' . $review['product_name'] . ': ' . mb_strimwidth($reply, 0, 160, '…'),
                'product',
                (int) $review['product_id']
            );

            if (!empty($review['email'])) {
                $productUrl = celestia_base_url() . '/pages/product.php?id=' . (int) $review['product_id'];
                $html = celestia_email_layout('
                    <p style="margin:0 0 16px;">Hi ' . htmlspecialchars($review['first_name']) . ',</p>
                    <p style="margin:0 0 16px;">Thank you for reviewing <strong>' . htmlspecialchars($review['product_name']) . '</strong>. Here is our response:</p>
                    <div style="border-left:3px solid #8E6A2C; padding:4px 0 4px 14px; margin:0 0 20px;">' . nl2br(htmlspecialchars($reply)) . '</div>
                    <p style="margin:0;">
                        <a href="' . htmlspecialchars($productUrl) . '" style="display:inline-block; padding:10px 22px; background:#8E6A2C; color:#FFFFFF; text-decoration:none; border-radius:3px;">View your review</a>
                    </p>
                ');
                // Best effort — the reply is already public on the product page.
                send_brevo_email($review['email'], trim($review['first_name'] . ' ' . $review['last_name']), 'We replied to your review of ' . $review['product_name'], $html);
            }
        }

        log_audit(
            $pdo,
            $actor_id,
            $actor_type,
            $isNewReply ? 'reply' : 'update',
            'product_review',
            $reviewId,
            ['admin_reply' => $review['admin_reply']],
            ['admin_reply' => $reply]
        );

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_review_reply') {
        $reviewId = isset($_POST['review_id']) ? (int) $_POST['review_id'] : 0;
        if ($reviewId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }

        $oldStmt = $pdo->prepare("SELECT admin_reply FROM product_reviews WHERE review_id = ?");
        $oldStmt->execute([$reviewId]);
        $oldReply = $oldStmt->fetchColumn();

        if ($oldReply === false) {
            echo json_encode(['success' => false, 'message' => 'Review not found.']);
            exit;
        }

        $pdo->prepare("
            UPDATE product_reviews
            SET admin_reply = NULL, admin_reply_at = NULL, admin_reply_by = NULL
            WHERE review_id = ?
        ")->execute([$reviewId]);

        log_audit(
            $pdo,
            $actor_id,
            $actor_type,
            'delete',
            'product_review_reply',
            $reviewId,
            ['admin_reply' => $oldReply],
            null
        );

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (PDOException $e) {
    error_log('feedback-action error: ' . $e->getMessage());
    $missingTable = preg_match('/contact_inquiry_replies|admin_reply/i', $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $missingTable
            ? 'Replies are not set up in the database yet. Run sql/2026-10-04_feedback_replies.sql in Supabase first.'
            : 'Something went wrong. Please try again.',
    ]);
}
