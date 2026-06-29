<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

$groupLabels = [
    'nav'           => 'Navigation',
    'footer'        => 'Footer',
    'home'          => 'Homepage',
    'portfolio'     => 'Portfolio',
    'shop'          => 'Shop',
    'about'         => 'About',
    'events'        => 'Events',
    'templates'     => 'Templates',
    'announcement'  => 'Announcement Detail',
    'order'         => 'Order Pages (success / cancel)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $upsertSql = "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";

    foreach (PageText::allKeys() as [$group, $key]) {
        $defaultText = PageText::DEFAULTS[$group][$key];
        $supplied    = trim((string)($_POST['text'][$group][$key] ?? ''));
        $settingKey  = PageText::settingKey($group, $key);

        // Blank or "same as default" → delete the override row so the default
        // is used and the settings table stays clean.
        if ($supplied === '' || $supplied === $defaultText) {
            Database::query("DELETE FROM settings WHERE setting_key = ?", [$settingKey]);
        } else {
            Database::query($upsertSql, [$settingKey, $supplied]);
        }
    }
    ActivityLog::log('settings.page_text_save');
    flash('success', 'Page text saved.');
    redirect(SITE_URL . '/admin/settings/page-text');
}

// Load current override values keyed by setting key
$overrideRows = Database::fetchAll(
    "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'text.%'"
);
$overrides = [];
foreach ($overrideRows as $row) {
    $overrides[$row['setting_key']] = $row['setting_value'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Text — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/pages/settings-page-text.css">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Page Text</h1>
            <a href="/admin/settings/" class="admin-btn">Back to Settings</a>
        </div>

        <?php $flash = getFlash(); if ($flash): ?>
            <div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
        <?php endif; ?>

        <p class="u-muted-fog">
            Override the display text used by public pages. Each field shows
            the built-in default as a placeholder — leave blank (or restore
            the default text) to use it.
        </p>

        <section class="pt-priority" aria-label="High-impact strings">
            <h2>⭐ Top priorities</h2>
            <p>Quick links to the strings most adopters customize first — call-to-action buttons, page subtitles, post-purchase copy.</p>
            <ul>
                <?php foreach (PageText::HIGH_IMPACT as $hi):
                    [$g, $k] = explode('.', $hi, 2);
                    if (!isset(PageText::DEFAULTS[$g][$k])) continue;
                ?>
                    <li><a href="#group-<?= e($g) ?>"><?= e($k) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <nav class="pt-toc" aria-label="Section jump list">
            <ul>
                <?php foreach (PageText::groups() as $g): ?>
                    <li><a href="#group-<?= e($g) ?>"><?= e($groupLabels[$g] ?? $g) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <form method="POST">
            <?= csrf_field() ?>
            <?php foreach (PageText::DEFAULTS as $group => $keys): ?>
                <section class="pt-section" id="group-<?= e($group) ?>">
                    <h2><?= e($groupLabels[$group] ?? $group) ?></h2>
                    <p class="pt-section__desc">Setting key prefix: <code>text.<?= e($group) ?>.*</code></p>
                    <?php foreach ($keys as $key => $default):
                        $sk      = PageText::settingKey($group, $key);
                        $current = $overrides[$sk] ?? '';
                        $isMultiline = strlen($default) > 60 || str_contains($default, "\n");
                    ?>
                        <div class="pt-row">
                            <div class="pt-row__label">
                                <?= e($key) ?>
                                <?php if (PageText::isHighImpact($group, $key)): ?>
                                    <span class="pt-star" title="High-impact string — most adopters customize this">⭐</span>
                                <?php endif; ?>
                            </div>
                            <div class="pt-row__input">
                                <?php if ($isMultiline): ?>
                                    <textarea name="text[<?= e($group) ?>][<?= e($key) ?>]" placeholder="<?= e($default) ?>" rows="2"><?= e($current) ?></textarea>
                                <?php else: ?>
                                    <input type="text" name="text[<?= e($group) ?>][<?= e($key) ?>]" value="<?= e($current) ?>" placeholder="<?= e($default) ?>">
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>

            <div class="pt-form-actions">
                <button type="submit" class="admin-btn admin-btn--primary">Save All</button>
                <a href="/admin/settings/page-text" class="admin-btn">Cancel</a>
            </div>
        </form>
    </div>
</main>
</body>
</html>
