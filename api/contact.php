<?php
/**
 * /api/contact.php
 *
 *   GET  -> the store's contact details, from the same contact_page row the
 *           website reads, with the same fallbacks when the row is missing.
 *   POST -> { full_name, email, phone?, subject?, message }
 *           files an inquiry in contact_inquiries, exactly as
 *           functions/contact-submit.php does.
 *
 * Public on both verbs: the website lets a guest send a message, and an
 * app that demanded an account before someone could ask a question would
 * be worse than the site it came from.
 *
 * The details are editable by the admin in the same place as the web's,
 * so a phone number changed in the admin panel changes in the app too —
 * which is the whole reason this is an endpoint rather than text compiled
 * into the app.
 */
require_once __DIR__ . '/_bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];

/** The website's own defaults, for a database with no contact_page row yet. */
function cg_contact_fallback(): array
{
    return [
        'heading'        => 'Contact Our Celestial Artisans',
        'subtext'        => 'Whether you are inquiring about our cosmic jewelry, interested in '
                          . 'co-creating a custom astronomical masterpiece, or seeking support '
                          . 'for an order, we are here to assist you.',
        'store_name'     => 'Celestia Gems',
        'store_address'  => "123 Sesame Street, Sample City\nManila, Philippines",
        'phone'          => '+63 912-345-7891',
        'email_general'  => 'hello@celestiagems.com',
        'email_custom'   => 'design@celestiagems.com',
        'hours_weekday'  => '10:00 AM – 8:00 PM',
        'hours_sunday'   => '11:00 AM – 6:00 PM',
    ];
}

if ($method === 'GET') {
    $contact = cg_contact_fallback();

    try {
        $row = $pdo->query("SELECT * FROM contact_page LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // Keep a fallback for any column the admin left empty, rather
            // than showing a blank phone number.
            foreach ($contact as $key => $default) {
                if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                    $contact[$key] = $row[$key];
                }
            }
        }
    } catch (PDOException $e) {
        error_log('contact.php read error: ' . $e->getMessage());
        // Fall through with the defaults — contact details are the last
        // thing that should 500 on someone trying to report a problem.
    }

    // The subjects the web form offers, sent down so the two stay in step.
    $contact['subjects'] = ['Custom Design', 'Repairs', 'Order Support', 'Wholesale / Partnerships', 'Other'];

    json_out(['success' => true, 'contact' => $contact]);
}

if ($method === 'POST') {
    $in = read_input();

    $fullName = trim($in['full_name'] ?? '');
    $email    = trim($in['email'] ?? '');
    $phone    = trim($in['phone'] ?? '');
    $subject  = trim($in['subject'] ?? 'Other');
    $message  = trim($in['message'] ?? '');

    if ($fullName === '' || $email === '' || $message === '') {
        json_error('Please give your name, email and a message.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Please provide a valid email address.');
    }

    // The subject reaches the admin's inbox as a label, so it is pinned to
    // the list we offer rather than taken as typed.
    $allowed = ['Custom Design', 'Repairs', 'Order Support', 'Wholesale / Partnerships', 'Other'];
    if (!in_array($subject, $allowed, true)) {
        $subject = 'Other';
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO contact_inquiries (full_name, email, phone, subject, message)
            VALUES (:full_name, :email, :phone, :subject, :message)
        ");
        $stmt->execute([
            ':full_name' => $fullName,
            ':email'     => $email,
            ':phone'     => $phone,
            ':subject'   => $subject,
            ':message'   => $message,
        ]);

        json_out([
            'success' => true,
            'message' => 'Thank you — your message has been received. We usually reply within one business day.',
        ]);
    } catch (PDOException $e) {
        error_log('contact.php write error: ' . $e->getMessage());
        json_error('We could not send your message just now. Please try again, or email us directly.', 500);
    }
}

json_error('Method not allowed.', 405);
