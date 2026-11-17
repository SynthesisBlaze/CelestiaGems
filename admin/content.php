<?php
require_once __DIR__ . '/../functions/permissions.php';
cg_require_page('content.manage');

// Include your database connection
require_once __DIR__ . '/../functions/db_connection.php'; // Adjust this path based on your folder structure

$current_admin_page = 'content';
$page_title = 'Content';
$admin_user = cg_admin_user();

// --- Fetch Contact Data ---
$stmtContact = $pdo->prepare("SELECT * FROM contact_page LIMIT 1");
$stmtContact->execute();
$contact = $stmtContact->fetch(PDO::FETCH_ASSOC);

// Fallback if the contact_page table is empty
if (!$contact) {
    $contact = [
        'heading' => '',
        'subtext' => '',
        'store_name' => '',
        'store_address' => '',
        'phone' => '',
        'email_general' => '',
        'email_custom' => '',
        'hours_weekday' => '',
        'hours_sunday' => ''
    ];
}

// --- Fetch FAQ Data ---
$stmtFaq = $pdo->prepare("SELECT * FROM faqs ORDER BY sort_order ASC");
$stmtFaq->execute();
$faqs = $stmtFaq->fetchAll(PDO::FETCH_ASSOC);

// --- Fetch About Data ---
$stmtAbout = $pdo->prepare("SELECT * FROM about_page LIMIT 1");
$stmtAbout->execute();
$about = $stmtAbout->fetch(PDO::FETCH_ASSOC);

// Fallback if the about_page table is empty
if (!$about) {
    $about = [
        'about_heading' => '',
        'about_body' => '',
        'vision_heading' => '',
        'vision_text' => '',
        'mission_heading' => '',
        'mission_text' => ''
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Content — Celestia Gems Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/../styles/admin.css">
</head>

<body>

    <div class="admin-shell">
        <?php include __DIR__ . '/../components/admin_sidebar.php'; ?>

        <div class="admin-main">
            <?php include __DIR__ . '/../components/admin_topbar.php'; ?>

            <div class="admin-content">

                <?php if (isset($_GET['saved'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        Changes saved successfully!
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="content-tabs">
                    <button class="content-tab-btn active" data-ctab="about">About Us</button>
                    <button class="content-tab-btn" data-ctab="contact">Contact Us</button>
                    <button class="content-tab-btn" data-ctab="faq">FAQ</button>
                </div>

                <!-- ============ ABOUT US ============ -->
                <div class="content-panel active" id="ctab-about">
                    <form class="admin-panel" action="../functions/content-save-about.php" method="post">
                        <div class="admin-panel-title">About Us Page</div>

                        <div class="mb-3">
                            <label class="form-label">About Heading</label>
                            <input type="text" class="form-control" name="about_heading" value="<?= htmlspecialchars($about['about_heading']) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">About Body Text</label>
                            <textarea class="form-control" name="about_body" rows="6" required><?= htmlspecialchars($about['about_body']) ?></textarea>
                            <small class="text-muted">Use standard line breaks (Enter) to separate paragraphs.</small>
                        </div>

                        <hr style="border-color:var(--cg-hair); margin:1.4rem 0;">

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Vision Heading</label>
                                <input type="text" class="form-control" name="vision_heading" value="<?= htmlspecialchars($about['vision_heading']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Mission Heading</label>
                                <input type="text" class="form-control" name="mission_heading" value="<?= htmlspecialchars($about['mission_heading']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Vision Text</label>
                                <textarea class="form-control" name="vision_text" rows="4" required><?= htmlspecialchars($about['vision_text']) ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Mission Text</label>
                                <textarea class="form-control" name="mission_text" rows="4" required><?= htmlspecialchars($about['mission_text']) ?></textarea>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-gold px-4 py-2">Save Changes</button>
                            <button type="button" class="btn btn-outline-muted px-4 py-2" onclick="location.reload()">Cancel</button>
                        </div>
                    </form>
                </div>

                <!-- ============ CONTACT US ============ -->
                <div class="content-panel" id="ctab-contact">
                    <form class="admin-panel" action="../functions/content-save-contact.php" method="post">
                        <div class="admin-panel-title">Contact Us Page</div>

                        <div class="mb-3">
                            <label class="form-label">Page Heading</label>
                            <input type="text" class="form-control" name="heading" value="<?= htmlspecialchars($contact['heading']) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Subtext</label>
                            <textarea class="form-control" name="subtext" rows="3" required><?= htmlspecialchars($contact['subtext']) ?></textarea>
                        </div>

                        <hr style="border-color:var(--cg-hair); margin:1.4rem 0;">

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Store Name</label>
                                <input type="text" class="form-control" name="store_name" value="<?= htmlspecialchars($contact['store_name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone Number</label>
                                <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($contact['phone']) ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Store Address</label>
                                <textarea class="form-control" name="store_address" rows="2" required><?= htmlspecialchars($contact['store_address']) ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">General Inquiries Email</label>
                                <input type="email" class="form-control" name="email_general" value="<?= htmlspecialchars($contact['email_general']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Custom Commissions Email</label>
                                <input type="email" class="form-control" name="email_custom" value="<?= htmlspecialchars($contact['email_custom']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Business Hours (Weekday)</label>
                                <input type="text" class="form-control" name="hours_weekday" value="<?= htmlspecialchars($contact['hours_weekday']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Business Hours (Sunday)</label>
                                <input type="text" class="form-control" name="hours_sunday" value="<?= htmlspecialchars($contact['hours_sunday']) ?>" required>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-gold px-4 py-2">Save Changes</button>
                            <button type="button" class="btn btn-outline-muted px-4 py-2" onclick="location.reload()">Cancel</button>
                        </div>
                    </form>
                </div>

                <!-- ============ FAQ ============ -->
                <div class="content-panel" id="ctab-faq">
                    <form class="admin-panel" action="../functions/content-save-faq.php" method="post">
                        <div class="admin-panel-title">FAQ Page</div>
                        <p style="font-size:.82rem; color:var(--cg-muted); margin-top:-.6rem; margin-bottom:1.3rem;">
                            Add the questions and answers that should appear on the public FAQ page. Drag and drop ordering is not supported yet, order is based on appearance here.
                        </p>

                        <div id="faqList">
                            <?php if (empty($faqs)): ?>
                                <div class="empty-state mb-3" id="faqEmptyState">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                                        <path d="M9.1 9a3 3 0 015.8 1c0 2-3 2-3 4" />
                                        <path d="M12 17h.01" />
                                        <circle cx="12" cy="12" r="9" />
                                    </svg>
                                    <div class="title">No FAQs yet</div>
                                    <div class="hint">Click "Add Question" below to create the first one.</div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($faqs as $index => $faq): ?>
                                    <div class="faq-item p-3 mb-3 border rounded">
                                        <div class="faq-item-head mb-2 d-flex justify-content-between align-items-center">
                                            <span class="faq-index fw-bold">Question</span>
                                            <button type="button" class="btn btn-outline-danger btn-sm px-2 py-1 faq-remove">Remove</button>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">Question</label>
                                            <input type="text" class="form-control" name="faq_question[]" value="<?= htmlspecialchars($faq['question']) ?>" required>
                                        </div>
                                        <div>
                                            <label class="form-label">Answer</label>
                                            <textarea class="form-control" name="faq_answer[]" rows="3" required><?= htmlspecialchars($faq['answer']) ?></textarea>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <button type="button" class="btn btn-outline-primary btn-sm px-3 mb-3" id="addFaqBtn" style="border-radius:3px; font-size:.75rem; letter-spacing:.05em; text-transform:uppercase;">
                            + Add Question
                        </button>

                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-gold px-4 py-2">Save Changes</button>
                            <button type="button" class="btn btn-outline-muted px-4 py-2" onclick="location.reload()">Cancel</button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ---- Tab switching ----
        function showContentTab(name) {
            document.querySelectorAll('.content-panel').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.content-tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById('ctab-' + name).classList.add('active');
            document.querySelector('.content-tab-btn[data-ctab="' + name + '"]').classList.add('active');
        }
        document.querySelectorAll('.content-tab-btn').forEach(btn => {
            btn.addEventListener('click', () => showContentTab(btn.dataset.ctab));
        });

        // ---- FAQ: add / remove entries client-side ----
        const faqList = document.getElementById('faqList');
        const emptyState = document.getElementById('faqEmptyState');

        function addFaqItem() {
            const currentEmptyState = document.getElementById('faqEmptyState');
            if (currentEmptyState) currentEmptyState.remove();

            const wrap = document.createElement('div');
            wrap.className = 'faq-item p-3 mb-3 border rounded';
            wrap.innerHTML = `
                <div class="faq-item-head mb-2 d-flex justify-content-between align-items-center">
                    <span class="faq-index fw-bold">New Question</span>
                    <button type="button" class="btn btn-outline-danger btn-sm px-2 py-1 faq-remove">Remove</button>
                </div>
                <div class="mb-2">
                    <label class="form-label">Question</label>
                    <input type="text" class="form-control" name="faq_question[]" placeholder="e.g. Do you offer international shipping?" required>
                </div>
                <div>
                    <label class="form-label">Answer</label>
                    <textarea class="form-control" name="faq_answer[]" rows="3" placeholder="Write the answer here..." required></textarea>
                </div>
            `;

            attachRemoveEvent(wrap.querySelector('.faq-remove'), wrap);
            faqList.appendChild(wrap);
        }

        function createEmptyState() {
            const div = document.createElement('div');
            div.className = 'empty-state mb-3';
            div.id = 'faqEmptyState';
            div.innerHTML = `
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><path d="M9.1 9a3 3 0 015.8 1c0 2-3 2-3 4"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
                <div class="title">No FAQs yet</div>
                <div class="hint">Click "Add Question" below to create the first one.</div>
            `;
            return div;
        }

        function attachRemoveEvent(button, wrap) {
            button.addEventListener('click', () => {
                wrap.remove();
                // If there are no more FAQ items, show the empty state again
                if (!faqList.querySelector('.faq-item')) {
                    faqList.appendChild(createEmptyState());
                }
            });
        }

        // Attach remove events to existing loaded items
        document.querySelectorAll('.faq-remove').forEach(btn => {
            attachRemoveEvent(btn, btn.closest('.faq-item'));
        });

        document.getElementById('addFaqBtn').addEventListener('click', addFaqItem);
    </script>
</body>

</html>