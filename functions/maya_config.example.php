<?php
// Maya (online payments) configuration.
//
// SETUP:
// 1. Copy this file to functions/maya_config.php (it's gitignored, so your
//    real keys will never be committed).
// 2. Create a Maya Business account and copy your keys from the Maya
//    Developer / Manager dashboard (sandbox first).
// 3. Paste them below.

define('MAYA_PUBLIC_KEY', 'pk-YOUR_PUBLIC_KEY');
define('MAYA_SECRET_KEY', 'sk-YOUR_SECRET_KEY');
define('MAYA_BASE_URL', 'https://pg-sandbox.paymaya.com');

// The callback URL is built from the current domain in maya.php (MAYA_CALLBACK_PATH).
