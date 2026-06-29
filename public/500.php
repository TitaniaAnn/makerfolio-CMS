<?php
/**
 * 500 Internal Server Error — branded fallback when the app crashes.
 *
 * DELIBERATELY MINIMAL: no bootstrap, no DB, no class includes. If the app is
 * crashing hard enough to land here, the very classes we'd use to render a
 * theme are likely what crashed. The one dependency is a static stylesheet
 * (/css/pages/500.css) served by the web server, not PHP — it can't trigger a
 * cascading "error page can't render" failure (worst case: an unstyled but
 * functional page). Kept external (not inline) so the strict style-src CSP,
 * which this no-bootstrap file can't nonce, still allows it.
 *
 * Sets the response code defensively, but Apache's ErrorDocument may have
 * already set 500 before invoking this file — that's fine, headers() is a no-op
 * once headers are sent.
 */
if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 — Something Went Wrong</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet" href="/css/pages/500.css">
</head>
<body>
<div class="err-wrap">
    <p class="err-code">500</p>
    <h1>Something went wrong on our end.</h1>
    <p>The site hit an unexpected error trying to handle your request. We're not sure exactly what — but it's been logged.</p>
    <p>Try refreshing in a moment, or head back home.</p>
    <div class="err-actions">
        <a href="/">Return home</a>
    </div>
    <p class="meta">If this keeps happening, the site owner can check the PHP error log for details.</p>
</div>
</body>
</html>
