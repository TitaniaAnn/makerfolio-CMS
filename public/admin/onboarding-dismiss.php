<?php
/**
 * Dismiss the dashboard onboarding card. POST-only + CSRF-verified; writes a
 * per-install marker into the settings table so the card stays hidden across
 * sessions and admins. Re-enable by deleting the `onboarding_dismissed`
 * settings row (e.g. via reset-content's design partition isn't enough — this
 * is deliberately separate so resetting design doesn't drag the welcome card
 * back into view on an established site).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
csrf_verify();

Database::query(
    "INSERT INTO settings (setting_key, setting_value) VALUES ('onboarding_dismissed', '1')
     ON DUPLICATE KEY UPDATE setting_value = '1'"
);

redirect(SITE_URL . '/admin/dashboard');
