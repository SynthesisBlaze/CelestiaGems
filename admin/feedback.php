<?php
require_once __DIR__ . '/../functions/permissions.php';
cg_require_page('feedback.view');

require_once __DIR__ . '/../functions/db_connection.php';

$current_admin_page = 'feedback';
$page_title = 'Feedback';
$admin_user = cg_admin_user();

$tab = ($_GET['tab'] ?? 'inquiries') === 'reviews' ? 'reviews' : 'inquiries';

$per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

// Unread count, shown on the Inquiries tab regardless of which tab is active
$unread_count = (int) $pdo->query("SELECT COUNT(*) FROM contact_inquiries WHERE status = 'Unread'")->fetchColumn();

// Replies need the tables/columns from sql/2026-10-04_feedback_replies.sql.
// Until that's been run, the page still works — reply buttons just stay hidden.
$repliesReady = false;
try {
    $repliesReady = (bool) $pdo->query("
        SELECT to_regclass('public.contact_inquiry_replies') IS NOT NULL
           AND EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_name = 'product_reviews' AND column_name = 'admin_reply'
           )
    ")->fetchColumn();
} catch (PDOException $e) {
    $repliesReady = false;
}

function buildQueryString(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    $query = array_filter($query, fn($v) => $v !== '' && $v !== null);
    return htmlspecialchars('?' . http_build_query($query));
}

if ($tab === 'inquiries') {
    /**
     * ---------------------------------------------------------------
     * Contact Inquiries
     * ---------------------------------------------------------------
     */
    $f_search = trim($_GET['search'] ?? '');
    $f_status = trim($_GET['status'] ?? '');

    $where = [];
    $params = [];

    if ($f_search !== '') {
        $where[] = "(full_name ILIKE :search OR email ILIKE :search OR subject ILIKE :search)";
        $params[':search'] = '%' . $f_search . '%';
    }
    if ($f_status !== '') {
        $where[] = "status = :status";
        $params[':status'] = $f_status;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM contact_inquiries $whereSql");
    $stmtCount->execute($params);
    $total_rows = (int) $stmtCount->fetchColumn();
    $total_pages = max(1, (int) ceil($total_rows / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    $sql = "
        SELECT inquiry_id, full_name, email, phone, subject, message, status, created_at,
            " . ($repliesReady
                ? "(SELECT COUNT(*) FROM contact_inquiry_replies cr WHERE cr.inquiry_id = contact_inquiries.inquiry_id)"
                : "0") . " AS reply_count
        FROM contact_inquiries
        $whereSql
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $inquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    /**
     * ---------------------------------------------------------------
     * Product Reviews
     * ---------------------------------------------------------------
     */
    $f_search = trim($_GET['search'] ?? '');
    $f_rating = trim($_GET['rating'] ?? '');

    $where = [];
    $params = [];

    if ($f_search !== '') {
        $where[] = "(p.name ILIKE :search OR u.first_name ILIKE :search OR u.last_name ILIKE :search)";
        $params[':search'] = '%' . $f_search . '%';
    }
    if ($f_rating !== '') {
        $where[] = "r.rating = :rating";
        $params[':rating'] = (int) $f_rating;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmtCount = $pdo->prepare("
        SELECT COUNT(*) FROM product_reviews r
        JOIN products p ON p.product_id = r.product_id
        JOIN users u ON u.user_id = r.user_id
        $whereSql
    ");
    $stmtCount->execute($params);
    $total_rows = (int) $stmtCount->fetchColumn();
    $total_pages = max(1, (int) ceil($total_rows / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    $sql = "
        SELECT
            r.review_id, r.rating, r.comment, r.created_at,
            " . ($repliesReady ? "r.admin_reply" : "NULL") . " AS admin_reply,
            p.product_id, p.name AS product_name,
            (SELECT pi.image_url FROM product_images pi
                WHERE pi.product_id = p.product_id
                ORDER BY pi.is_primary DESC, pi.image_id ASC
                LIMIT 1) AS thumbnail_url,
            u.first_name, u.last_name
        FROM product_reviews r
        JOIN products p ON p.product_id = r.product_id
        JOIN users u ON u.user_id = r.user_id
        $whereSql
        ORDER BY r.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback — Celestia Gems Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/../styles/admin.css">
    <style>
        .feedback-tabs { display: flex; gap: .5rem; margin-bottom: 1.5rem; }
        .feedback-tab {
            padding: .55rem 1.1rem; border-radius: 6px; font-size: .85rem; font-weight: 500;
            text-decoration: none; color: var(--cg-muted);
            border: 1px solid var(--cg-divider); background: transparent;
        }
        .feedback-tab.active { color: var(--cg-text); background: var(--cg-gold-soft); border-color: var(--cg-gold); }
        .feedback-tab .badge-count {
            display: inline-block; margin-left: .4rem; padding: .05rem .4rem; border-radius: 10px;
            font-size: .72rem; background: var(--cg-gold); color: var(--cg-ink-on-gold); font-weight: 600;
        }
        .msg-preview { max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .review-stars { color: var(--cg-gold); letter-spacing: 1px; }

        /* ---- Replies ---- */
        .reply-count { display: block; margin-top: .3rem; font-size: .72rem; color: var(--cg-muted); }
        .reply-count svg { width: 12px; height: 12px; vertical-align: -1px; margin-right: 2px; }
        .replied-tag {
            display: inline-block; margin-top: .3rem; padding: .05rem .5rem; border-radius: 10px;
            font-size: .68rem; letter-spacing: .04em; text-transform: uppercase;
            color: var(--cg-gold); background: var(--cg-gold-wash); border: 1px solid var(--cg-hair);
        }
        .setup-note {
            margin-bottom: 1.5rem; padding: .8rem 1rem; border-radius: 6px; font-size: .85rem;
            color: var(--cg-text-soft); background: var(--cg-gold-wash); border: 1px solid var(--cg-hair);
        }
        .setup-note code { color: var(--cg-gold); }
        .thread { display: flex; flex-direction: column; gap: .75rem; }
        .thread-empty { font-size: .85rem; color: var(--cg-muted); }
        .thread-item {
            border: 1px solid var(--cg-hair); border-left: 3px solid var(--cg-gold);
            background: var(--cg-gold-wash); border-radius: 4px; padding: .7rem .9rem;
        }
        .thread-item .thread-head { display: flex; justify-content: space-between; gap: 1rem; font-size: .75rem; color: var(--cg-muted); margin-bottom: .3rem; }
        .thread-item .thread-head strong { color: var(--cg-text); font-weight: 500; }
        .thread-item .thread-body { font-size: .88rem; white-space: pre-wrap; color: var(--cg-text-soft); margin: 0; }
        .reply-form textarea { min-height: 130px; resize: vertical; }
        .reply-form .reply-hint { font-size: .78rem; color: var(--cg-muted); }
        .reply-form .form-check-label { font-size: .85rem; color: var(--cg-text-soft); }
        .reply-form .form-check-input:checked { background-color: var(--cg-gold); border-color: var(--cg-gold); }
        .reply-form .form-check-input:focus { box-shadow: 0 0 0 .2rem var(--cg-gold-glow); border-color: var(--cg-gold); }
        .reply-error { font-size: .82rem; color: var(--cg-danger); }
        .review-quote {
            border-left: 3px solid var(--cg-divider); padding: .2rem 0 .2rem .9rem; margin: 0;
            font-size: .9rem; color: var(--cg-text-soft); white-space: pre-wrap;
        }
    </style>
</head>

<body>

    <div class="admin-shell">
        <?php include __DIR__ . '/../components/admin_sidebar.php'; ?>

        <div class="admin-main">
            <?php include __DIR__ . '/../components/admin_topbar.php'; ?>

            <div class="admin-content">

                <div class="feedback-tabs">
                    <a href="?tab=inquiries" class="feedback-tab <?= $tab === 'inquiries' ? 'active' : '' ?>">
                        Contact Inquiries
                        <?php if ($unread_count > 0): ?>
                            <span class="badge-count"><?= $unread_count ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="?tab=reviews" class="feedback-tab <?= $tab === 'reviews' ? 'active' : '' ?>">
                        Product Reviews
                    </a>
                </div>

                <?php if (!$repliesReady): ?>
                    <div class="setup-note">
                        Replying to messages and reviews is almost ready. Run <code>sql/2026-10-04_feedback_replies.sql</code>
                        in the Supabase SQL Editor once, then reload this page.
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'inquiries'): ?>

                    <!-- ============ INQUIRIES FILTER BAR ============ -->
                    <form class="admin-panel shadow-sm filter-bar mb-4" method="get">
                        <input type="hidden" name="tab" value="inquiries">
                        <div class="row g-3 align-items-end">
                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-label fw-medium d-block">Search</label>
                                <input type="text" class="form-control" name="search" placeholder="Name, email, subject"
                                    value="<?= htmlspecialchars($f_search) ?>">
                            </div>
                            <div class="col-6 col-md-3 col-lg-2">
                                <label class="form-label fw-medium d-block">Status</label>
                                <select class="form-select" name="status">
                                    <option value="">All</option>
                                    <?php foreach (['Unread', 'Read', 'Resolved'] as $s): ?>
                                        <option value="<?= $s ?>" <?= $f_status === $s ? 'selected' : '' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3 col-lg-2">
                                <button type="submit" class="btn btn-outline-muted w-100">Filter</button>
                            </div>
                        </div>
                    </form>

                    <div class="admin-panel shadow-sm p-4">
                        <?php if (empty($inquiries)): ?>
                            <div class="empty-state d-flex flex-column align-items-center justify-content-center p-5">
                                <div class="title fs-5 mb-2">No inquiries found</div>
                                <div class="hint text-muted">
                                    <?= ($f_search || $f_status) ? 'Try widening or clearing your filters.' : 'Nothing has come in through the contact form yet.' ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="admin-table table-hover align-middle w-100">
                                    <thead>
                                        <tr>
                                            <th>From</th>
                                            <th>Subject</th>
                                            <th>Message</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($inquiries as $i): ?>
                                            <?php
                                            $statusClass = match ($i['status']) {
                                                'Unread' => 'pending',
                                                'Read' => 'processing',
                                                'Resolved' => 'delivered',
                                                default => '',
                                            };
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-medium text-cg"><?= htmlspecialchars($i['full_name']) ?></div>
                                                    <div class="text-muted" style="font-size:.8rem;"><?= htmlspecialchars($i['email']) ?></div>
                                                </td>
                                                <td><?= htmlspecialchars($i['subject']) ?></td>
                                                <td class="msg-preview text-muted"><?= htmlspecialchars($i['message']) ?></td>
                                                <td>
                                                    <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($i['status']) ?></span>
                                                    <?php if ((int) $i['reply_count'] > 0): ?>
                                                        <span class="reply-count">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 14L4 9l5-5" /><path d="M4 9h10a6 6 0 016 6v5" /></svg>
                                                            <?= (int) $i['reply_count'] ?> repl<?= (int) $i['reply_count'] === 1 ? 'y' : 'ies' ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-muted"><?= date('M j, Y', strtotime($i['created_at'])) ?></td>
                                                <td class="text-end">
                                                    <button type="button"
                                                        class="btn btn-outline-muted btn-sm px-2 py-1 view-inquiry-btn"
                                                        style="border-radius:3px; font-size:.72rem; letter-spacing:.04em; text-transform:uppercase;"
                                                        data-id="<?= (int) $i['inquiry_id'] ?>"
                                                        data-name="<?= htmlspecialchars($i['full_name']) ?>"
                                                        data-email="<?= htmlspecialchars($i['email']) ?>"
                                                        data-phone="<?= htmlspecialchars($i['phone'] ?? '') ?>"
                                                        data-subject="<?= htmlspecialchars($i['subject']) ?>"
                                                        data-message="<?= htmlspecialchars($i['message']) ?>"
                                                        data-status="<?= htmlspecialchars($i['status']) ?>"
                                                        data-date="<?= date('M j, Y g:i A', strtotime($i['created_at'])) ?>">
                                                        <?= $repliesReady ? 'View &amp; Reply' : 'View' ?>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($total_pages > 1): ?>
                                <div class="audit-pagination d-flex align-items-center justify-content-between mt-4">
                                    <div class="audit-pagination-info">
                                        Page <span class="fw-medium"><?= $page ?></span> of <span class="fw-medium"><?= $total_pages ?></span>
                                    </div>
                                    <div class="audit-pagination-controls d-flex gap-2">
                                        <a href="<?= buildQueryString(['page' => max(1, $page - 1)]) ?>"
                                            class="btn btn-outline-muted btn-sm px-3 py-1 <?= $page <= 1 ? 'disabled' : '' ?>">Previous</a>
                                        <a href="<?= buildQueryString(['page' => min($total_pages, $page + 1)]) ?>"
                                            class="btn btn-outline-muted btn-sm px-3 py-1 <?= $page >= $total_pages ? 'disabled' : '' ?>">Next</a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                <?php else: ?>

                    <!-- ============ REVIEWS FILTER BAR ============ -->
                    <form class="admin-panel shadow-sm filter-bar mb-4" method="get">
                        <input type="hidden" name="tab" value="reviews">
                        <div class="row g-3 align-items-end">
                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-label fw-medium d-block">Search</label>
                                <input type="text" class="form-control" name="search" placeholder="Product or reviewer"
                                    value="<?= htmlspecialchars($f_search) ?>">
                            </div>
                            <div class="col-6 col-md-3 col-lg-2">
                                <label class="form-label fw-medium d-block">Rating</label>
                                <select class="form-select" name="rating">
                                    <option value="">All</option>
                                    <?php for ($r = 5; $r >= 1; $r--): ?>
                                        <option value="<?= $r ?>" <?= $f_rating === (string)$r ? 'selected' : '' ?>><?= $r ?> star<?= $r > 1 ? 's' : '' ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3 col-lg-2">
                                <button type="submit" class="btn btn-outline-muted w-100">Filter</button>
                            </div>
                        </div>
                    </form>

                    <div class="admin-panel shadow-sm p-4">
                        <?php if (empty($reviews)): ?>
                            <div class="empty-state d-flex flex-column align-items-center justify-content-center p-5">
                                <div class="title fs-5 mb-2">No reviews found</div>
                                <div class="hint text-muted">
                                    <?= ($f_search || $f_rating) ? 'Try widening or clearing your filters.' : 'No product reviews have been submitted yet.' ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="admin-table table-hover align-middle w-100">
                                    <thead>
                                        <tr>
                                            <th style="width: 60px;"></th>
                                            <th>Product</th>
                                            <th>Reviewer</th>
                                            <th>Rating</th>
                                            <th>Comment</th>
                                            <th>Date</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($reviews as $r): ?>
                                            <tr>
                                                <td>
                                                    <?php if (!empty($r['thumbnail_url'])): ?>
                                                        <img src="/img/<?= htmlspecialchars($r['thumbnail_url']) ?>" alt="" class="product-thumb">
                                                    <?php else: ?>
                                                        <span class="product-thumb-placeholder">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 19.5A2.5 2.5 0 016.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z" /></svg>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="fw-medium text-cg"><?= htmlspecialchars($r['product_name']) ?></td>
                                                <td class="text-muted"><?= htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
                                                <td class="review-stars"><?= str_repeat('★', (int) $r['rating']) . str_repeat('☆', 5 - (int) $r['rating']) ?></td>
                                                <td>
                                                    <div class="msg-preview text-muted"><?= htmlspecialchars($r['comment'] ?? '') ?></div>
                                                    <?php if (!empty($r['admin_reply'])): ?>
                                                        <span class="replied-tag">Replied</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-muted"><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
                                                <td class="text-end text-nowrap">
                                                    <?php if ($repliesReady): ?>
                                                        <button type="button"
                                                            class="btn btn-outline-muted btn-sm px-2 py-1 me-1 reply-review-btn"
                                                            style="border-radius:3px; font-size:.72rem; letter-spacing:.04em; text-transform:uppercase;"
                                                            data-id="<?= (int) $r['review_id'] ?>"
                                                            data-product="<?= htmlspecialchars($r['product_name']) ?>"
                                                            data-reviewer="<?= htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name'])) ?>"
                                                            data-rating="<?= (int) $r['rating'] ?>"
                                                            data-comment="<?= htmlspecialchars($r['comment'] ?? '') ?>"
                                                            data-date="<?= date('M j, Y', strtotime($r['created_at'])) ?>"
                                                            data-reply="<?= htmlspecialchars($r['admin_reply'] ?? '') ?>">
                                                            <?= !empty($r['admin_reply']) ? 'Edit Reply' : 'Reply' ?>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (cg_can('feedback.delete_review')): ?>
                                                    <button type="button"
                                                        class="btn btn-outline-danger-soft btn-sm px-2 py-1 delete-review-btn"
                                                        style="border-radius:3px; font-size:.72rem; letter-spacing:.04em; text-transform:uppercase;"
                                                        data-id="<?= (int) $r['review_id'] ?>">
                                                        Delete
                                                    </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($total_pages > 1): ?>
                                <div class="audit-pagination d-flex align-items-center justify-content-between mt-4">
                                    <div class="audit-pagination-info">
                                        Page <span class="fw-medium"><?= $page ?></span> of <span class="fw-medium"><?= $total_pages ?></span>
                                    </div>
                                    <div class="audit-pagination-controls d-flex gap-2">
                                        <a href="<?= buildQueryString(['page' => max(1, $page - 1)]) ?>"
                                            class="btn btn-outline-muted btn-sm px-3 py-1 <?= $page <= 1 ? 'disabled' : '' ?>">Previous</a>
                                        <a href="<?= buildQueryString(['page' => min($total_pages, $page + 1)]) ?>"
                                            class="btn btn-outline-muted btn-sm px-3 py-1 <?= $page >= $total_pages ? 'disabled' : '' ?>">Next</a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- ============ INQUIRY DETAIL MODAL ============ -->
    <div class="modal fade" id="inquiryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="inqModalTitle">Inquiry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="inqModalBody"></div>
                <div class="modal-footer" id="inqModalFooter"></div>
            </div>
        </div>
    </div>

    <!-- ============ REVIEW REPLY MODAL ============ -->
    <div class="modal fade" id="reviewReplyModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="rrTitle">Reply to review</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="od-meta">
                        <div><span>Product</span><strong id="rrProduct"></strong></div>
                        <div><span>Reviewer</span><strong id="rrReviewer"></strong></div>
                        <div><span>Rating</span><strong id="rrRating" class="review-stars"></strong></div>
                        <div><span>Posted</span><strong id="rrDate"></strong></div>
                    </div>
                    <div class="od-section">
                        <p class="od-kicker">Review</p>
                        <p class="review-quote" id="rrComment"></p>
                    </div>
                    <div class="od-section reply-form">
                        <p class="od-kicker">Your public reply</p>
                        <textarea class="form-control" id="rrReply" maxlength="2000" placeholder="Thank the customer, answer their question, or address their concern."></textarea>
                        <div class="d-flex justify-content-between align-items-center mt-2 gap-3 flex-wrap">
                            <span class="reply-hint">Shown under the review on the product page. The reviewer is notified the first time you reply.</span>
                            <span class="reply-hint" id="rrCount">0 / 2000</span>
                        </div>
                        <div class="reply-error mt-2" id="rrError" hidden></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-danger-soft btn-sm px-3 me-auto" id="rrDelete" hidden>Remove reply</button>
                    <button type="button" class="btn btn-outline-muted btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-gold btn-sm px-3" id="rrSave">Post reply</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const inquiryModalEl = document.getElementById('inquiryModal');
        const inquiryModal = new bootstrap.Modal(inquiryModalEl);
        const inqModalTitle = document.getElementById('inqModalTitle');
        const inqModalBody = document.getElementById('inqModalBody');
        const inqModalFooter = document.getElementById('inqModalFooter');

        function renderInquiryFooter(id, status) {
            let html = '';
            if (status !== 'Read') {
                html += `<button type="button" class="btn btn-outline-muted btn-sm px-3" onclick="updateInquiryStatus(${id}, 'Read')">Mark as Read</button>`;
            }
            if (status !== 'Resolved') {
                html += `<button type="button" class="btn ${REPLIES_READY ? 'btn-outline-muted' : 'btn-gold'} btn-sm px-3" onclick="updateInquiryStatus(${id}, 'Resolved')">Mark as Resolved</button>`;
            }
            html += `<button type="button" class="btn btn-outline-muted btn-sm px-3" data-bs-dismiss="modal">Close</button>`;
            return html;
        }

        const REPLIES_READY = <?= $repliesReady ? 'true' : 'false' ?>;
        const ACTION_URL = '../functions/feedback-action.php';

        function postAction(fields) {
            const formData = new FormData();
            Object.entries(fields).forEach(([k, v]) => formData.append(k, v));
            return fetch(ACTION_URL, { method: 'POST', body: formData })
                .then(res => res.json())
                .catch(() => ({ success: false, message: 'Could not reach the server. Check your connection and try again.' }));
        }

        function el(tag, className, text) {
            const node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        }

        function renderThread(container, replies) {
            container.innerHTML = '';
            if (!replies.length) {
                container.appendChild(el('div', 'thread-empty', 'No replies yet.'));
                return;
            }
            replies.forEach(r => {
                const item = el('div', 'thread-item');
                const head = el('div', 'thread-head');
                const who = el('span');
                who.appendChild(el('strong', '', r.by));
                who.appendChild(document.createTextNode(' replied by email'));
                head.appendChild(who);
                head.appendChild(el('span', '', r.date));
                item.appendChild(head);
                item.appendChild(el('p', 'thread-body', r.message));
                container.appendChild(item);
            });
        }

        function buildReplySection(d) {
            const section = el('div', 'od-section');
            section.appendChild(el('p', 'od-kicker', 'Conversation'));
            const thread = el('div', 'thread');
            thread.appendChild(el('div', 'thread-empty', 'Loading replies…'));
            section.appendChild(thread);

            postAction({ action: 'get_inquiry_replies', inquiry_id: d.id }).then(data => {
                if (data.success) renderThread(thread, data.replies);
                else thread.replaceChildren(el('div', 'reply-error', data.message || 'Could not load replies.'));
            });

            const form = el('div', 'reply-form mt-3');
            const label = el('label', 'form-label fw-medium', 'Reply to ' + d.name);
            label.htmlFor = 'inqReplyText';
            const textarea = el('textarea', 'form-control');
            textarea.id = 'inqReplyText';
            textarea.maxLength = 5000;
            textarea.placeholder = 'Write your reply…';

            const row = el('div', 'd-flex justify-content-between align-items-center mt-2 gap-3 flex-wrap');
            row.appendChild(el('span', 'reply-hint', 'Sent by email to ' + d.email));
            const check = el('div', 'form-check mb-0');
            const box = el('input', 'form-check-input');
            box.type = 'checkbox';
            box.id = 'inqMarkResolved';
            box.checked = d.status !== 'Resolved';
            const boxLabel = el('label', 'form-check-label', 'Mark as resolved after sending');
            boxLabel.htmlFor = 'inqMarkResolved';
            check.appendChild(box);
            check.appendChild(boxLabel);
            row.appendChild(check);

            const error = el('div', 'reply-error mt-2');
            error.id = 'inqReplyError';
            error.hidden = true;

            form.appendChild(label);
            form.appendChild(textarea);
            form.appendChild(row);
            form.appendChild(error);
            section.appendChild(form);
            return section;
        }

        function sendInquiryReply(id, btn) {
            const textarea = document.getElementById('inqReplyText');
            const error = document.getElementById('inqReplyError');
            const message = textarea.value.trim();
            error.hidden = true;

            if (!message) {
                error.textContent = 'Write a reply before sending.';
                error.hidden = false;
                textarea.focus();
                return;
            }

            btn.disabled = true;
            const original = btn.textContent;
            btn.textContent = 'Sending…';

            postAction({
                action: 'reply_inquiry',
                inquiry_id: id,
                message: message,
                mark_resolved: document.getElementById('inqMarkResolved').checked ? '1' : '0'
            }).then(data => {
                if (data.success) {
                    location.reload();
                    return;
                }
                error.textContent = data.message || 'Could not send this reply.';
                error.hidden = false;
                btn.disabled = false;
                btn.textContent = original;
            });
        }

        document.querySelectorAll('.view-inquiry-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const d = btn.dataset;
                inqModalTitle.textContent = d.subject;

                inqModalBody.innerHTML = '';
                const meta = document.createElement('div');
                meta.className = 'od-meta';
                meta.innerHTML = `
                    <div><span>From</span><strong></strong></div>
                    <div><span>Email</span><strong></strong></div>
                    <div><span>Phone</span><strong></strong></div>
                    <div><span>Received</span><strong></strong></div>
                `;
                const strongs = meta.querySelectorAll('strong');
                strongs[0].textContent = d.name;
                strongs[1].textContent = d.email;
                strongs[2].textContent = d.phone || '—';
                strongs[3].textContent = d.date;
                inqModalBody.appendChild(meta);

                const msgSection = document.createElement('div');
                msgSection.className = 'od-section';
                const kicker = document.createElement('p');
                kicker.className = 'od-kicker';
                kicker.textContent = 'Message';
                const msgBody = document.createElement('p');
                msgBody.style.fontSize = '.9rem';
                msgBody.style.whiteSpace = 'pre-wrap';
                msgBody.textContent = d.message;
                msgSection.appendChild(kicker);
                msgSection.appendChild(msgBody);
                inqModalBody.appendChild(msgSection);

                if (REPLIES_READY) {
                    inqModalBody.appendChild(buildReplySection(d));
                }

                inqModalFooter.innerHTML = renderInquiryFooter(d.id, d.status);

                if (REPLIES_READY) {
                    const sendBtn = el('button', 'btn btn-gold btn-sm px-3', 'Send reply');
                    sendBtn.type = 'button';
                    sendBtn.addEventListener('click', () => sendInquiryReply(d.id, sendBtn));
                    inqModalFooter.appendChild(sendBtn);
                }
                inquiryModal.show();
            });
        });

        function updateInquiryStatus(inquiryId, newStatus) {
            if (!confirm(`Mark this inquiry as ${newStatus}?`)) return;

            const formData = new FormData();
            formData.append('action', 'update_inquiry_status');
            formData.append('inquiry_id', inquiryId);
            formData.append('status', newStatus);

            fetch('../functions/feedback-action.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) location.reload();
                    else alert(data.message || 'Could not update this inquiry.');
                });
        }

        document.querySelectorAll('.delete-review-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (!confirm('Delete this review? This cannot be undone.')) return;

                const formData = new FormData();
                formData.append('action', 'delete_review');
                formData.append('review_id', btn.dataset.id);

                fetch('../functions/feedback-action.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) location.reload();
                        else alert(data.message || 'Could not delete this review.');
                    });
            });
        });

        /* ---------------- Review replies ---------------- */
        const rrModalEl = document.getElementById('reviewReplyModal');
        if (rrModalEl) {
            const rrModal = new bootstrap.Modal(rrModalEl);
            const rrReply = document.getElementById('rrReply');
            const rrCount = document.getElementById('rrCount');
            const rrError = document.getElementById('rrError');
            const rrSave = document.getElementById('rrSave');
            const rrDelete = document.getElementById('rrDelete');
            let rrReviewId = null;

            const updateCount = () => { rrCount.textContent = rrReply.value.length + ' / 2000'; };
            rrReply.addEventListener('input', updateCount);

            function showRrError(msg) {
                rrError.textContent = msg;
                rrError.hidden = false;
            }

            document.querySelectorAll('.reply-review-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const d = btn.dataset;
                    const rating = parseInt(d.rating, 10) || 0;
                    rrReviewId = d.id;
                    document.getElementById('rrTitle').textContent = d.reply ? 'Edit reply' : 'Reply to review';
                    document.getElementById('rrProduct').textContent = d.product;
                    document.getElementById('rrReviewer').textContent = d.reviewer;
                    document.getElementById('rrRating').textContent = '★'.repeat(rating) + '☆'.repeat(5 - rating);
                    document.getElementById('rrDate').textContent = d.date;
                    document.getElementById('rrComment').textContent = d.comment || '(No written comment)';
                    rrReply.value = d.reply || '';
                    rrSave.textContent = d.reply ? 'Save changes' : 'Post reply';
                    rrDelete.hidden = !d.reply;
                    rrError.hidden = true;
                    rrSave.disabled = false;
                    updateCount();
                    rrModal.show();
                });
            });

            rrModalEl.addEventListener('shown.bs.modal', () => rrReply.focus());

            rrSave.addEventListener('click', () => {
                const reply = rrReply.value.trim();
                rrError.hidden = true;
                if (!reply) {
                    showRrError('Write a reply before saving.');
                    rrReply.focus();
                    return;
                }
                rrSave.disabled = true;
                postAction({ action: 'save_review_reply', review_id: rrReviewId, reply: reply }).then(data => {
                    if (data.success) { location.reload(); return; }
                    showRrError(data.message || 'Could not save this reply.');
                    rrSave.disabled = false;
                });
            });

            rrDelete.addEventListener('click', () => {
                if (!confirm('Remove this reply from the product page?')) return;
                rrDelete.disabled = true;
                postAction({ action: 'delete_review_reply', review_id: rrReviewId }).then(data => {
                    if (data.success) { location.reload(); return; }
                    showRrError(data.message || 'Could not remove this reply.');
                    rrDelete.disabled = false;
                });
            });
        }

        document.querySelectorAll('.audit-pagination-controls .disabled').forEach(a => {
            a.addEventListener('click', e => e.preventDefault());
        });
    </script>
</body>

</html>