<?php
// includes/bootstrap.php

define('ROOT_PATH', dirname(__DIR__));

// 1. Autoloader must come first (Dotenv lives in vendor/)
if (!file_exists(ROOT_PATH . '/vendor/autoload.php')) {
    die("Autoloader not found at: " . ROOT_PATH . '/vendor/autoload.php');
}
require_once ROOT_PATH . '/vendor/autoload.php';

// 2. Load .env BEFORE config.php so $_ENV is populated when constants are defined
if (file_exists(ROOT_PATH . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
    $dotenv->safeLoad();
}

// 3. Now config.php can safely read $_ENV
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/Database.php';
require_once ROOT_PATH . '/includes/Auth.php';
require_once ROOT_PATH . '/includes/ImageUpload.php';
require_once ROOT_PATH . '/includes/MultiFileUpload.php';
require_once ROOT_PATH . '/includes/Stripe.php';
require_once ROOT_PATH . '/includes/Mailer.php';
require_once ROOT_PATH . '/includes/Theme.php';
require_once ROOT_PATH . '/includes/AuthProviders.php';
require_once ROOT_PATH . '/includes/EventTypes.php';
require_once ROOT_PATH . '/includes/PageText.php';
require_once ROOT_PATH . '/includes/PageSections.php';
require_once ROOT_PATH . '/includes/ContentReset.php';
require_once ROOT_PATH . '/includes/EmailTemplates.php';
require_once ROOT_PATH . '/includes/Backup.php';
require_once ROOT_PATH . '/includes/ActivityLog.php';
require_once ROOT_PATH . '/includes/PageMeta.php';
require_once ROOT_PATH . '/includes/Totp.php';
require_once ROOT_PATH . '/includes/SampleContent.php';
require_once ROOT_PATH . '/includes/ListReorder.php';
require_once ROOT_PATH . '/includes/ListQuery.php';

Auth::start();

// Hardening headers sent on every PHP response.
//   - Referrer-Policy strips path + query from the Referer header on
//     cross-origin navigations, killing the CSRF-token-in-referer-log leak
//     vector that ?csrf=… GET-style admin links would otherwise create.
//     Same-origin navigations still get the full URL (so internal flow
//     analytics keep working).
//   - X-Content-Type-Options stops MIME-sniffing on the (rare) endpoints
//     that emit non-HTML — sitemap.php, install zip download, etc.
//   - X-Frame-Options denies iframe embedding except the theme preview
//     iframe (same-origin, allowed by SAMEORIGIN).
//
// Per-request CSP nonce. Generated BEFORE the CSP header so the same value lands
// in the header and the page body. During the slice-by-slice CSP tightening
// (mirroring the SaaS's Phase 7H) this is the escape hatch for the few inline
// <style> blocks that are genuinely request-time dynamic (Theme::styleBlock, the
// error pages); everything static is extracted to external CSS instead.
if (!defined('CSP_NONCE')) {
    define('CSP_NONCE', bin2hex(random_bytes(16)));
}

/**
 * csp_nonce — the per-request nonce string. Stamp it on inline <style> blocks
 * that MUST stay inline (request-time dynamic CSS the file path can't reach):
 *
 *   echo '<style nonce="' . e(csp_nonce()) . '">...</style>';
 *
 * Don't reach for this to drop new inline styles into templates — the per-page
 * CSS files under /css/pages/ (or /admin/css/pages/) are the right home for
 * anything that isn't request-time dynamic. Fewer nonce uses = smaller bypass
 * surface.
 */
function csp_nonce(): string {
    return CSP_NONCE;
}

if (!headers_sent()) {
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');

    // Content-Security-Policy — STRICT on both script-src and style-src:
    // no 'unsafe-inline' anywhere. Reached by extracting every inline script,
    // on*= handler, inline <style> block, and style="" attribute across four
    // slices (mirroring the SaaS's Phase 7H). The admin-trusted social-post
    // `embed_code` field still works: Instagram/TikTok embeds load via their
    // allowlisted CDN <script src> + render into allowlisted frames.
    //
    // What this policy blocks:
    //   - Inline-script XSS: a stored-XSS injecting `<script>…</script>` OR an
    //     on*= attribute is refused by script-src 'self' (no 'unsafe-inline').
    //   - Inline-style injection: same shape via `<style>` blocks / style=""
    //     attrs, refused by style-src (no 'unsafe-inline'). The only inline
    //     styles left are nonce-stamped (Theme::styleBlock, 404.php).
    //   - Remote script injection: `<script src="https://evil.com/x.js">` —
    //     host not in the script-src allowlist.
    //   - Object/embed injection (Flash, PDF takeovers): object-src 'none'.
    //   - Form hijacking to an attacker domain: form-action restricts POST targets.
    //   - Mixed-content downgrade on HTTPS: upgrade-insecure-requests.
    //   - <base> tag injection that rewrites relative URLs: base-uri 'self'.
    //   - Framing by third-party sites: frame-ancestors 'self'.
    //
    // Edit guidance — keep it strict:
    //   - New inline <script> or on*= handler: don't. Put JS in an external
    //     file under public/js/ or public/admin/js/ and reference via <script
    //     src>; use data-* attributes + a delegated listener for handlers.
    //   - New inline <style> block or style="" attr: don't. Put rules in the
    //     per-page CSS at /css/pages/<slug>.css (or /admin/css/pages/), use a
    //     state-driven class, set styles via JS (CSSOM bypasses style-src), or
    //     use SVG presentation attributes for dynamic colors. Genuinely
    //     request-time-dynamic CSS may use a nonce'd <style> via csp_nonce().
    //   - New CDN dependency: add its hostname to the relevant -src directive.
    // The nonce is on style-src ONLY — script-src has zero inline scripts, so
    // adding a nonce there would needlessly re-open the inline-script door.
    $csp = "default-src 'self'; "
         . "script-src 'self' https://platform.instagram.com https://www.tiktok.com; "
         . "style-src 'self' 'nonce-" . CSP_NONCE . "' https://fonts.googleapis.com; "
         . "font-src 'self' https://fonts.gstatic.com; "
         . "img-src 'self' data: blob: https:; "
         . "connect-src 'self'; "
         . "frame-src 'self' https://www.instagram.com https://www.tiktok.com; "
         . "object-src 'none'; "
         . "base-uri 'self'; "
         . "form-action 'self' https://checkout.stripe.com; "
         . "frame-ancestors 'self'; "
         . "upgrade-insecure-requests";
    header('Content-Security-Policy: ' . $csp);
}

// Helper: get site setting
function setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        try {
            $rows  = Database::fetchAll("SELECT setting_key, setting_value FROM settings");
            $cache = array_column($rows, 'setting_value', 'setting_key');
        } catch (Exception $e) {
            $cache = [];
        }
    }
    return $cache[$key] ?? $default;
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

function flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void {
    $supplied = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!$expected || !is_string($supplied) || !hash_equals($expected, $supplied)) {
        http_response_code(403);
        flash('error', 'Security check failed. Please try again.');
        // Open-redirect guard: HTTP_REFERER is attacker-controllable, so only
        // follow it back when it's same-origin with our SITE_URL. Otherwise a
        // crafted form submission with Referer: https://evil.example could
        // bounce the (logged-in) admin off-site after a 403. Falls back to the
        // admin dashboard for in-app failures, or "/" if SITE_URL isn't set.
        $fallback = defined('SITE_URL') ? SITE_URL . '/admin/dashboard.php' : '/';
        $referer  = $_SERVER['HTTP_REFERER'] ?? '';
        $target   = $fallback;
        if ($referer !== '' && defined('SITE_URL')) {
            $refParts  = parse_url($referer);
            $siteParts = parse_url(SITE_URL);
            if ($refParts && $siteParts
                && ($refParts['scheme'] ?? '') === ($siteParts['scheme'] ?? '')
                && strcasecmp($refParts['host'] ?? '', $siteParts['host'] ?? '') === 0
                && ($refParts['port'] ?? null) === ($siteParts['port'] ?? null)) {
                $target = $referer;
            }
        }
        header('Location: ' . $target);
        exit;
    }
}

function getFlash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// Social icon SVG helper — available globally
function getSocialIcon(string $platform): string {
    $icons = [
        'instagram' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
        'tiktok'    => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V8.69a8.18 8.18 0 004.78 1.52V6.74a4.85 4.85 0 01-1.01-.05z"/></svg>',
        'youtube'   => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
    ];
    return $icons[strtolower($platform)] ?? '<span>' . htmlspecialchars($platform) . '</span>';
}
