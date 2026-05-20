<?php
/**
 * 500 Internal Server Error — branded fallback when the app crashes.
 *
 * DELIBERATELY MINIMAL: no bootstrap, no DB, no class includes. If the app is
 * crashing hard enough to land here, the very classes we'd use to render a
 * theme are likely what crashed. Self-contained inline HTML + CSS prevents a
 * cascading "error page can't render an error" failure.
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
    <style>
        *{box-sizing:border-box}
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #faf6ef; color: #2a2520; margin: 0; padding: 2rem; line-height: 1.6; }
        .err-wrap { max-width: 560px; margin: 5rem auto; padding: 2rem 2.25rem; background: #fff; border: 1px solid #e8e4d8; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,.04); text-align: center; }
        .err-code { font-family: Georgia, serif; font-size: 4rem; line-height: 1; color: #c89a6a; margin: 0 0 .5rem; }
        h1 { font-size: 1.4rem; margin: 0 0 1rem; }
        p { color: #5a5048; margin: .5rem 0; }
        .err-actions { display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap; margin-top: 1.5rem; }
        .err-actions a { display: inline-block; padding: .65rem 1.25rem; border-radius: 6px; text-decoration: none; background: #c89a6a; color: #fff; }
        .err-actions a:hover { background: #a87f55; }
        .meta { color: #9a9088; font-size: .85rem; margin-top: 2rem; border-top: 1px solid #f0ebe2; padding-top: 1rem; }
    </style>
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
