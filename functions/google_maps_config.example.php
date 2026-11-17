<?php
// Google Maps Platform key — used client-side for the shipping
// address map/autocomplete on account.php and checkout.php.
//
// SETUP:
// 1. Copy this file to functions/google_maps_config.php (it's gitignored).
// 2. Create a key in Google Cloud console (Maps JavaScript API + Places API).
// 3. Paste it below.
//
// This key is meant to be visible in the page source (that's normal for
// Maps JS keys). Protect it in the Google Cloud console instead of hiding
// it: restrict the key to your site's URL under HTTP referrer restrictions.

define('GOOGLE_MAPS_API_KEY', 'YOUR_GOOGLE_MAPS_API_KEY');
