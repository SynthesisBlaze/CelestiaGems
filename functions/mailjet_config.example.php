<?php
// Mailjet (transactional email) configuration — used for 2FA login codes.
//
// SETUP:
// 1. Copy this file to functions/mailjet_config.php (it's gitignored, so your
//    real credentials will never be committed).
// 2. Create a Mailjet account and copy your keys from
//    Account Settings -> REST API.
// 3. Paste them below.

define('MAILJET_API_KEY', 'YOUR_MAILJET_API_KEY');
define('MAILJET_SECRET_KEY', 'YOUR_MAILJET_SECRET_KEY');
define('MAILJET_SENDER_EMAIL', 'celestiagems@celestiagems.shop');
define('MAILJET_SENDER_NAME', 'Verify');
