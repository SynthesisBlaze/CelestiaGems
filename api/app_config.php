<?php
/**
 * Base URL handling — shared-hosting version.
 *
 * The API now sits beside the web app inside public_html, so it reuses the
 * site's own cg_base_url() helper. That reads the host from the incoming
 * request, which means this file needs no configuration at all: it is
 * correct on the live domain, on a staging subdomain, and on XAMPP.
 */
require_once __DIR__ . '/../functions/helpers.php';

if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', cg_base_url());
}

/**
 * Product images are served straight from the web app's /img folder — the
 * same files the admin panel writes when a product photo is uploaded, so a
 * newly uploaded image shows up in the mobile app with no extra step.
 *
 * Define PRODUCT_IMAGE_BASE_URL in a config file only if images ever move
 * to a separate host, such as a CDN.
 */
function product_image_url(?string $filename): ?string
{
    if (empty($filename)) {
        return null;
    }
    $imageBase = defined('PRODUCT_IMAGE_BASE_URL') ? PRODUCT_IMAGE_BASE_URL : APP_BASE_URL;
    return rtrim($imageBase, '/') . '/img/' . rawurlencode($filename);
}
