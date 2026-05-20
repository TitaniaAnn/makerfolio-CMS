<?php
/**
 * 404 Not Found — branded landing for typo'd URLs and broken inbound links.
 *
 * Bootstrap is required because this page uses the site's theme + nav
 * include, just like every other public page. If bootstrap itself fails
 * (DB down etc.) the user will see 500.php instead via the Apache
 * ErrorDocument chain.
 */
http_response_code(404);
require_once __DIR__ . '/../includes/bootstrap.php';

$siteName  = setting('site_name', 'My Pottery');
$pageTitle = '404 — Page Not Found · ' . $siteName;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet" href="/css/main.css">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <style>
        .err-wrap { max-width: 640px; margin: 5rem auto; padding: 2rem; text-align: center; }
        .err-code { font-family: var(--font-display, Georgia, serif); font-size: 5rem; line-height: 1; color: var(--clay, #c89a6a); margin: 0 0 .5rem; }
        .err-title { font-size: 1.5rem; margin: 0 0 1rem; color: var(--ink, #2a2520); }
        .err-body { color: var(--fog, #7a8090); margin-bottom: 1.5rem; line-height: 1.6; }
        .err-actions { display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap; }
        .err-actions a { display: inline-block; padding: .75rem 1.5rem; border-radius: 6px; text-decoration: none; }
        .err-actions a.primary { background: var(--clay, #c89a6a); color: white; }
        .err-actions a.primary:hover { background: var(--clay-dk, #a87f55); }
        .err-actions a.secondary { background: transparent; color: var(--ink, #2a2520); border: 1px solid var(--sand, #e8e4d8); }
        .err-actions a.secondary:hover { background: var(--cream, #faf6ef); }
    </style>
</head>
<body>
<?php include __DIR__ . '/../templates/nav.php'; ?>

<main class="err-wrap">
    <p class="err-code">404</p>
    <h1 class="err-title">This page wandered off.</h1>
    <p class="err-body">
        The page you're looking for isn't here — it may have been moved, renamed,
        or never existed. Try the portfolio or head back home.
    </p>
    <div class="err-actions">
        <a href="/" class="primary">Return home</a>
        <a href="/portfolio" class="secondary">Browse the portfolio</a>
    </div>
</main>

<?php include __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
