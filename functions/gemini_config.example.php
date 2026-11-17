<?php
// Google Gemini (Generative Language API) configuration — used for the
// storefront AI shopping assistant.
//
// SETUP:
// 1. Copy this file to functions/gemini_config.php (it's gitignored, so your
//    real key will never be committed).
// 2. Get a free API key at https://aistudio.google.com/apikey
// 3. Paste the key below.
//
// Model names change fairly often. If GEMINI_MODEL below stops working,
// check https://ai.google.dev/gemini-api/docs/models for the current
// free-tier-eligible Flash model name and update it here.

define('GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY');
define('GEMINI_MODEL', 'gemini-3.6-flash');
