<?php
// ============================================================
// config/config.php - Main configuration
// ============================================================

/**
 * Render a styled 500 error page and exit. Used only for fail-closed config
 * errors that prevent the rest of the stack from booting (missing DB creds,
 * placeholder Stripe keys, etc.).
 *
 * Has to be self-contained because config.php runs before any class in
 * includes/ has loaded — no Database, no Auth, no PageText, no Theme. Plain
 * inline HTML + CSS. Doesn't reveal secrets; the detail message is allowed to
 * list which env-var keys were missing but not what their values were.
 */
function _config_error_page(string $title, string $body, array $missingKeys = [], bool $isFreshInstall = false): void {
    if (headers_sent()) {
        // Mid-render fallback; can't change status or headers.
        echo "\n\n<!-- Configuration error: {$title} -->\n";
        echo strip_tags($body) . "\n";
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $safeTitle  = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeBody   = htmlspecialchars($body,  ENT_QUOTES, 'UTF-8');
    $missingLis = '';
    if ($missingKeys) {
        $missingLis = '<ul class="key-list">';
        foreach ($missingKeys as $k) {
            $missingLis .= '<li><code>' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '</code></li>';
        }
        $missingLis .= '</ul>';
    }
    $installerLink = $isFreshInstall
        ? '<p><a href="/install/" class="primary-btn">Run the install wizard →</a></p>'
        : '';
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$safeTitle}</title>
  <style>
    *{box-sizing:border-box}
    body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#faf6ef;color:#2a2520;margin:0;padding:2rem;line-height:1.6;}
    .wrap{max-width:640px;margin:4rem auto;background:#fff;border:1px solid #e8e4d8;border-radius:12px;padding:2rem 2.25rem;box-shadow:0 4px 20px rgba(0,0,0,.04);}
    h1{margin:0 0 .5rem;font-size:1.5rem;color:#b53a3a;}
    .lead{color:#5a5048;margin:0 0 1.5rem;}
    .key-list{background:#fdf0f0;border:1px solid #f2c2c2;border-radius:8px;padding:.75rem 1rem .75rem 2rem;margin:1rem 0;}
    .key-list li{margin:.15rem 0;}
    code{background:#f4f2ec;padding:1px 6px;border-radius:3px;font-size:.92em;color:#5c4a3a;}
    .help{background:#f4f2ec;border-radius:8px;padding:1rem 1.25rem;margin:1.5rem 0;}
    .help h2{margin:0 0 .5rem;font-size:1rem;}
    .help p{margin:.35rem 0;}
    .primary-btn{display:inline-block;background:#c89a6a;color:#fff;padding:.65rem 1.25rem;border-radius:6px;text-decoration:none;font-weight:600;margin-top:1rem;}
    .primary-btn:hover{background:#a87f55;}
    .meta{color:#9a9088;font-size:.85rem;margin-top:2rem;border-top:1px solid #f0ebe2;padding-top:1rem;}
  </style>
</head>
<body>
<div class="wrap">
  <h1>⚠ Site not fully configured</h1>
  <p class="lead">{$safeBody}</p>
  {$missingLis}
  {$installerLink}
  <div class="help">
    <h2>What to check</h2>
    <p>• The <code>.env</code> file at the project root has values for every required key.</p>
    <p>• The database server is running and accepting connections.</p>
    <p>• If you just deployed, your hosting may need the document root pointed at <code>public/</code>.</p>
    <p>• Full details (with values) are in the PHP error log — never displayed here.</p>
  </div>
  <p class="meta">Configuration error · this page is shown instead of running the app when required setup is incomplete.</p>
</div>
</body>
</html>
HTML;
    exit(1);
}

// Validate required environment variables up front so a missing .env produces
// a clear error rather than cryptic PDO/Stripe failures deeper in the stack.
$requiredEnv = [
    'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
    // GitHub OAuth credentials moved to the `settings` table in migration 015.
    // The installer / admin auth page writes them; AuthProviders reads them.
    // `.env` may still carry them as a one-time bootstrap source (the docker
    // auto-migrate entrypoint copies them into settings on first boot).
    // Stripe vars are intentionally NOT required — the shop falls back to
    // "contact me to purchase" UX when missing. See STRIPE_ENABLED below.
];
$missing = [];
foreach ($requiredEnv as $var) {
    if (empty($_ENV[$var])) {
        $missing[] = $var;
    }
}
if (!empty($missing)) {
    error_log('Configuration error — missing .env values: ' . implode(', ', $missing));
    // If the install wizard is still in place, this is almost certainly a
    // fresh-install situation — surface the wizard link prominently.
    $isFreshInstall = is_dir(__DIR__ . '/../public/install') && !file_exists(__DIR__ . '/../.installed');
    _config_error_page(
        'Site not configured',
        'These required environment variables are missing from .env:',
        $missing,
        $isFreshInstall
    );
}

// Stripe is opt-in. Treat it as enabled iff all three keys are present and
// non-empty. When enabled, sanity-check them. When disabled, the shop UI hides
// "Buy Now" and the checkout/webhook entry points refuse politely.
$stripeKeys = ['STRIPE_PUBLISHABLE_KEY' => 'pk_', 'STRIPE_SECRET_KEY' => 'sk_', 'STRIPE_WEBHOOK_SECRET' => 'whsec_'];
$stripeEnabled = true;
foreach ($stripeKeys as $var => $_) {
    if (empty($_ENV[$var])) { $stripeEnabled = false; break; }
}
if ($stripeEnabled) {
    foreach ($stripeKeys as $var => $expectedPrefix) {
        $val = $_ENV[$var];
        if (strpos($val, 'YOUR_') !== false || strpos($val, $expectedPrefix) !== 0) {
            error_log("Configuration error: {$var} looks like a placeholder.");
            _config_error_page(
                'Payment keys not configured',
                "Stripe key {$var} looks like a placeholder. Either set a real key or leave all three STRIPE_* keys blank to disable payments (the shop falls back to \"contact me to purchase\")."
            );
        }
    }
}
define('STRIPE_ENABLED', $stripeEnabled);

define('DB_HOST', $_ENV['DB_HOST']);
define('DB_NAME', $_ENV['DB_NAME']);
define('DB_USER', $_ENV['DB_USER']);
define('DB_PASS', $_ENV['DB_PASS']);

define('DB_CHARSET', 'utf8mb4');

// Override via SITE_URL in .env for local dev (e.g. http://localhost:8000) so
// OAuth callbacks, mailer links, and Stripe redirect URIs resolve correctly.
// No trailing slash. Falls back to a localhost URL when missing — set this in
// .env on first install (the wizard does this for you).
define('SITE_URL', rtrim($_ENV['SITE_URL'] ?? 'http://localhost:8088', '/'));
define('UPLOAD_PATH', __DIR__ . '/../public/uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');

// GitHub OAuth constants — kept for backwards compatibility with any code
// that still references them. The authoritative values now live in the
// `settings` table (auth_github_*) and are read via AuthProviders.
// These define()s gracefully tolerate a missing .env entry; the auto-migrate
// entrypoint copies non-empty values into settings on first boot.
define('GITHUB_CLIENT_ID',     $_ENV['GITHUB_CLIENT_ID']     ?? '');
define('GITHUB_CLIENT_SECRET', $_ENV['GITHUB_CLIENT_SECRET'] ?? '');
define('GITHUB_REDIRECT_URI',  SITE_URL . '/admin/auth/callback.php');
define('ALLOWED_GITHUB_USERS', $_ENV['ALLOWED_GITHUB_USERS'] ?? '');

// Stripe — get from https://dashboard.stripe.com/apikeys.
// Constants are always defined so existing references don't fatal; the empty
// fallbacks are guarded by STRIPE_ENABLED above.
define('STRIPE_PUBLISHABLE_KEY', $_ENV['STRIPE_PUBLISHABLE_KEY'] ?? '');
define('STRIPE_SECRET_KEY',      $_ENV['STRIPE_SECRET_KEY']      ?? '');
define('STRIPE_WEBHOOK_SECRET',  $_ENV['STRIPE_WEBHOOK_SECRET']  ?? '');

// Social Media Integration — for posting announcements
// Instagram Graph API — get from Meta Business Manager
// https://developers.facebook.com/docs/instagram-graph-api
define('INSTAGRAM_BUSINESS_ACCOUNT_ID', $_ENV['INSTAGRAM_BUSINESS_ACCOUNT_ID'] ?? '');
define('INSTAGRAM_ACCESS_TOKEN',        $_ENV['INSTAGRAM_ACCESS_TOKEN'] ?? '');

// TikTok Content Posting API — get from TikTok Developer Platform
// https://developers.tiktok.com/doc/content-posting-api
define('TIKTOK_BUSINESS_ACCOUNT_ID', $_ENV['TIKTOK_BUSINESS_ACCOUNT_ID'] ?? '');
define('TIKTOK_ACCESS_TOKEN',        $_ENV['TIKTOK_ACCESS_TOKEN'] ?? '');

define('SHOP_CURRENCY', 'usd');  // lowercase for Stripe API
define('SESSION_NAME', 'pottery_session');
define('SESSION_LIFETIME', 86400); // 24 hours

// Trusted reverse-proxy IPs. Auth::clientIp() honors X-Forwarded-For ONLY
// when REMOTE_ADDR matches an entry here — otherwise XFF is ignored and
// REMOTE_ADDR is used directly. Prevents rate-limit bypass via spoofed XFF
// on hosts that aren't behind a stripping proxy.
//
// Loopback (127.0.0.1, ::1) is always trusted, covering nginx/Apache on the
// same host. For external proxies (Cloudflare, Bluehost's shared frontend, etc.)
// add comma-separated IPs via the TRUSTED_PROXIES env var:
//   TRUSTED_PROXIES=192.0.2.1,2001:db8::1
define('TRUSTED_PROXIES', $_ENV['TRUSTED_PROXIES'] ?? '');

// Image settings
define('MAX_IMAGE_SIZE', 10 * 1024 * 1024); // 10MB upload cap
define('THUMB_WIDTH', 600);
define('THUMB_HEIGHT', 600);
// Originals larger than this on the longer edge are auto-resized down on upload.
// Saves disk and bandwidth — a 4 MB iPhone photo (4032×3024) shrinks to ~400 KB
// at 1600px without visible quality loss for portfolio display.
define('MAX_ORIGINAL_DIMENSION', 1600);
