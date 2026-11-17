<?php
// PayMongo (online payments) configuration.
//
// SETUP:
// 1. Copy this file to functions/paymongo_config.php (it's gitignored, so
//    your real keys will never be committed).
// 2. Create a PayMongo account and copy your keys from
//    Settings -> Developers in the PayMongo dashboard.
// 3. Paste them below.
//
// Use TEST keys (pk_test_/sk_test_) while developing — they cannot move real
// money. Swap in pk_live_/sk_live_ only when the site is actually ready to
// accept real payments.

define('PAYMONGO_PUBLIC_KEY', 'pk_test_YOUR_PUBLIC_KEY');
define('PAYMONGO_SECRET_KEY', 'sk_test_YOUR_SECRET_KEY');
