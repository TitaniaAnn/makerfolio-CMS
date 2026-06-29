<?php
/**
 * System Health — broader green/red status checks beyond DB schema.
 *
 * Schema-health.php already covers DB-level checks (tables, columns, fix-SQL
 * snippets). This page covers everything else an adopter cares about but
 * wouldn't notice if it broke silently:
 *
 *   - Migration ledger up to date
 *   - Stripe keys present + correctly prefixed (when enabled)
 *   - Instagram token present + not expired/expiring
 *   - mail() function available
 *   - Request is HTTPS (production-mode flag)
 *   - public/uploads/ writable
 *   - .env doesn't still contain installer placeholders
 *   - Sample content marker (informational warning)
 *   - .installed marker present
 *   - public/install/ folder removed
 *
 * Each check returns one of: ok / warn / error / info, plus a short message
 * and an optional remediation link. The page never modifies state — it's
 * read-only diagnostics.
 */
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

// -- Check helpers -----------------------------------------------------------

/**
 * Each entry: ['label' => string, 'status' => 'ok'|'warn'|'error'|'info',
 *              'detail' => string, 'fix' => ['href' => string, 'text' => string]|null]
 */
$checks = [];

// 1. Database — if we got here, the connection works (bootstrap loads
//    Database.php and the topbar/sidebar both query). But ping explicitly so
//    a connection failure surfaces with a clear message rather than a 500.
try {
    Database::fetchOne("SELECT 1 AS ping");
    // Don't echo DB_NAME / DB_HOST — they're admin-trusted but appear in
    // screenshots, support emails, and browser history; we have nothing to
    // gain from showing them here (the admin already knows what they configured).
    $checks[] = ['label' => 'Database connection', 'status' => 'ok', 'detail' => 'Connected.', 'fix' => null];
} catch (\Throwable $e) {
    // Log the full exception (with SQL state, server path, etc.) but only
    // tell the admin "database error" — PDO messages can leak credentials
    // or connection-string fragments into the rendered page.
    error_log('Health check: database ping failed — ' . $e->getMessage());
    $checks[] = ['label' => 'Database connection', 'status' => 'error', 'detail' => 'Database error — see PHP error log for details.', 'fix' => null];
}

// 2. Migrations — any pending files?
try {
    $runner  = new MigrationRunner(ROOT_PATH . '/sql');
    $pending = $runner->pending();
    if (empty($pending)) {
        $checks[] = ['label' => 'Database migrations', 'status' => 'ok', 'detail' => 'All shipped migrations are applied.', 'fix' => null];
    } else {
        $checks[] = [
            'label'  => 'Database migrations',
            'status' => 'warn',
            'detail' => count($pending) . ' pending: ' . implode(', ', array_map('basename', $pending)),
            'fix'    => ['href' => '/admin/migrations/index.php', 'text' => 'Open Migrations'],
        ];
    }
} catch (\Throwable $e) {
    error_log('Health check: migration ledger read failed — ' . $e->getMessage());
    $checks[] = ['label' => 'Database migrations', 'status' => 'warn', 'detail' => 'Could not read migration ledger — see PHP error log for details.', 'fix' => null];
}

// 3. Stripe — STRIPE_ENABLED gate handled in config.php (which already exits
//    with a friendly error on placeholder keys). Surface the current state here.
if (STRIPE_ENABLED) {
    $detail = 'All three keys present and prefixed correctly. Webhook URL: ' . SITE_URL . '/shop/webhook.php';
    $checks[] = ['label' => 'Stripe payments', 'status' => 'ok', 'detail' => $detail, 'fix' => null];
} else {
    $checks[] = [
        'label'  => 'Stripe payments',
        'status' => 'info',
        'detail' => 'Disabled — shop UI hides Buy Now and falls back to "Enquire" links. Add STRIPE_PUBLISHABLE_KEY / STRIPE_SECRET_KEY / STRIPE_WEBHOOK_SECRET in .env to enable.',
        'fix'    => null,
    ];
}

// 4. Instagram token — managed via /admin/social/tokens.php after install.
$igToken  = AnnouncementSocialMedia::getInstagramAccessToken();
$igExpiry = AnnouncementSocialMedia::getInstagramTokenExpiry();
if (empty($igToken)) {
    $checks[] = ['label' => 'Instagram token', 'status' => 'info', 'detail' => 'Not configured — social-posting for announcements is disabled. Optional unless you want Announcements to auto-post.', 'fix' => null];
} else {
    $now = new DateTimeImmutable();
    if ($igExpiry === null) {
        $checks[] = ['label' => 'Instagram token', 'status' => 'warn', 'detail' => 'Token present but expiry unknown. Refresh to capture the next expiry date.', 'fix' => ['href' => '/admin/social/tokens.php', 'text' => 'Open Social Tokens']];
    } elseif ($igExpiry < $now) {
        $checks[] = ['label' => 'Instagram token', 'status' => 'error', 'detail' => 'Expired on ' . $igExpiry->format('Y-m-d') . '. Posts will fail until a new token is generated.', 'fix' => ['href' => '/admin/social/tokens.php', 'text' => 'Refresh token']];
    } elseif ($igExpiry->diff($now)->days <= 7) {
        $checks[] = ['label' => 'Instagram token', 'status' => 'warn', 'detail' => 'Expires on ' . $igExpiry->format('Y-m-d') . ' (' . $igExpiry->diff($now)->days . ' days). Refresh soon.', 'fix' => ['href' => '/admin/social/tokens.php', 'text' => 'Refresh token']];
    } else {
        $checks[] = ['label' => 'Instagram token', 'status' => 'ok', 'detail' => 'Valid until ' . $igExpiry->format('Y-m-d') . '.', 'fix' => null];
    }
}

// 5. mail() availability — we can't actually send a test email without
//    spamming someone, but checking the function exists rules out hosts that
//    disable it entirely.
if (function_exists('mail')) {
    $checks[] = ['label' => 'PHP mail()', 'status' => 'ok', 'detail' => 'mail() function is available. (This does not guarantee delivery — check spam folders if customer emails go missing.)', 'fix' => null];
} else {
    $checks[] = ['label' => 'PHP mail()', 'status' => 'error', 'detail' => 'mail() is disabled on this host. Order receipts and shipping notifications will silently fail. Ask your host to enable it, or wire a different SMTP layer.', 'fix' => null];
}

// 6. HTTPS — Auth::isSecureRequest honors X-Forwarded-Proto on trusted proxies.
if (Auth::isSecureRequest()) {
    $checks[] = ['label' => 'HTTPS', 'status' => 'ok', 'detail' => 'Request is over TLS. Session cookies get the Secure flag.', 'fix' => null];
} else {
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
            || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost');
    $checks[] = [
        'label'  => 'HTTPS',
        'status' => $isLocal ? 'info' : 'warn',
        'detail' => $isLocal
            ? 'Local development — HTTPS not required.'
            : 'This request is plain HTTP. Session cookies cannot be marked Secure, so logins are vulnerable to network sniffing. Configure TLS on your host before going live.',
        'fix' => null,
    ];
}

// 7. Uploads directory writable.
$uploadDir = rtrim(UPLOAD_PATH, '/\\');
if (is_dir($uploadDir) && is_writable($uploadDir)) {
    $checks[] = ['label' => 'Uploads directory', 'status' => 'ok', 'detail' => 'public/uploads/ is writable. Image uploads will succeed.', 'fix' => null];
} else {
    $checks[] = ['label' => 'Uploads directory', 'status' => 'error', 'detail' => 'public/uploads/ is missing or not writable by the web server user. Image + template uploads will fail.', 'fix' => null];
}

// 8. .env hygiene — flag remaining installer placeholders that escaped a
//    half-finished setup.
$envPath = ROOT_PATH . '/.env';
if (is_file($envPath) && is_readable($envPath)) {
    $envContent = (string)file_get_contents($envPath);
    $placeholderHits = [];
    foreach (['YOUR_', 'your-secret-here', 'CHANGEME', 'changeme'] as $needle) {
        if (str_contains($envContent, $needle)) {
            $placeholderHits[] = $needle;
        }
    }
    if ($placeholderHits) {
        $checks[] = ['label' => '.env placeholders', 'status' => 'warn', 'detail' => 'Found placeholder strings still in .env: ' . implode(', ', $placeholderHits) . '. These look unset and may cause silent failures.', 'fix' => null];
    } else {
        $checks[] = ['label' => '.env placeholders', 'status' => 'ok', 'detail' => 'No common placeholder patterns found in .env.', 'fix' => null];
    }
} else {
    $checks[] = ['label' => '.env placeholders', 'status' => 'info', 'detail' => '.env not readable from PHP (this is unusual but not necessarily a bug — env vars may be injected another way).', 'fix' => null];
}

// 9. Sample content marker — purely informational reminder.
if (SampleContent::isSeeded()) {
    $checks[] = [
        'label'  => 'Sample content',
        'status' => 'info',
        'detail' => 'Demo rows are still in the database. Wipe them via Reset Content (content partition) before sharing the site publicly.',
        'fix'    => ['href' => '/admin/settings/reset-content.php', 'text' => 'Reset Content'],
    ];
}

// 10. .installed marker.
if (file_exists(ROOT_PATH . '/.installed')) {
    $checks[] = ['label' => 'Install marker', 'status' => 'ok', 'detail' => '.installed marker present. The install wizard will refuse to re-run.', 'fix' => null];
} else {
    $checks[] = ['label' => 'Install marker', 'status' => 'warn', 'detail' => '.installed marker missing. If /install/ is still present, anyone hitting that URL could re-run the wizard.', 'fix' => null];
}

// 11. public/install/ folder — should be removed after a successful install.
if (is_dir(ROOT_PATH . '/public/install')) {
    $checks[] = [
        'label'  => 'Install folder removal',
        'status' => 'warn',
        'detail' => 'public/install/ still exists. The wizard auto-deletes after success; survived deletions usually mean a deploy re-syncs the folder. Add public/install/ to your deploy exclusion list, or delete manually.',
        'fix'    => null,
    ];
} else {
    $checks[] = ['label' => 'Install folder removal', 'status' => 'ok', 'detail' => 'public/install/ has been removed.', 'fix' => null];
}

// 12. Stray error_log file at project root (this is gitignored but can still
//     end up world-readable on a misconfigured host).
if (file_exists(ROOT_PATH . '/error_log')) {
    $size = filesize(ROOT_PATH . '/error_log') ?: 0;
    $checks[] = [
        'label'  => 'Stray error_log file',
        'status' => 'warn',
        'detail' => 'PHP wrote an error_log file at the project root (' . number_format($size) . ' bytes). Configure error_log in php.ini to point outside the document root, or delete this file after reading it.',
        'fix'    => null,
    ];
}

// Tally for the summary pills.
$tally = ['ok' => 0, 'warn' => 0, 'error' => 0, 'info' => 0];
foreach ($checks as $c) {
    $tally[$c['status']]++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Health — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/pages/settings-health.css">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>System Health</h1>
            <a href="/admin/settings/" class="admin-btn">Back to Settings</a>
        </div>

        <p style="color:var(--fog,#7a8090);">
            Read-only diagnostics. Covers the things that can fail silently —
            expired social tokens, missing TLS, leftover install folders. For
            DB-schema checks (table/column existence + fix-SQL), use
            <a href="/admin/settings/schema-health">Schema Health</a>.
        </p>

        <div class="health-summary">
            <?php foreach (['ok' => 'OK', 'warn' => 'Warn', 'error' => 'Error', 'info' => 'Info'] as $k => $label): ?>
                <?php if ($tally[$k] > 0): ?>
                    <span class="health-pill health-pill--<?= e($k) ?>"><?= e($label) ?>: <?= (int)$tally[$k] ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="health-list">
            <?php foreach ($checks as $c): ?>
                <div class="health-item">
                    <span class="health-item__status health-item__status--<?= e($c['status']) ?>">
                        <?= $c['status'] === 'ok' ? '✓ OK' : ($c['status'] === 'error' ? '✗ Error' : ($c['status'] === 'warn' ? '⚠ Warn' : 'ℹ Info')) ?>
                    </span>
                    <div class="health-item__body">
                        <div class="health-item__label"><?= e($c['label']) ?></div>
                        <div class="health-item__detail"><?= e($c['detail']) ?></div>
                    </div>
                    <div class="health-item__fix">
                        <?php if (!empty($c['fix'])): ?>
                            <a href="<?= e($c['fix']['href']) ?>" class="admin-btn admin-btn--sm"><?= e($c['fix']['text']) ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>
</body>
</html>
