<?php
/**
 * Sample-content seeder admin page.
 *
 * One button: "Load sample content". Drops in 5 pottery pieces, 3 events,
 * 2 announcements, 4 shop products, 3 social links — all with generated SVG
 * placeholder images. Lets a fresh adopter see a populated site immediately
 * instead of evaluating the CMS against empty states.
 *
 * Strictly additive: never deletes existing rows. If the seeder has already
 * run on this install (marker row exists), the button is disabled to prevent
 * accidental double-seeding. Use /admin/settings/reset-content.php (content
 * partition) to wipe the demo rows + clear the marker if you want to re-seed.
 */
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

$result = null;
$error  = null;
$alreadySeeded = SampleContent::isSeeded();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if ($alreadySeeded) {
        $error = 'Sample content has already been loaded on this install. Use Reset Content (content partition) to wipe demo rows first if you want to re-seed.';
    } else {
        try {
            $result = SampleContent::seed(rtrim(UPLOAD_PATH, '/\\'));
            $alreadySeeded = SampleContent::isSeeded();
            ActivityLog::log('content.sample_seed', null, null, ['created' => $result['created']]);
            flash('success', 'Sample content loaded. Have a look at the public site or browse the admin sections to see the demo rows.');
        } catch (\Throwable $e) {
            $error = 'Seeding failed: ' . $e->getMessage();
        }
    }
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sample Content — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        .sc-panel { background: #fff; border: 1px solid var(--sand,#e8e4d8); border-radius: 8px; padding: 1.5rem; margin-bottom: 1.25rem; }
        .sc-panel h2 { margin: 0 0 .5rem; }
        .sc-list { margin: .5rem 0 1rem 1.25rem; color: var(--ink); line-height: 1.7; }
        .sc-list li code { background: #f4f2ec; padding: 1px 5px; border-radius: 3px; font-size: .85em; }
        .sc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: .75rem; margin-top: 1rem; }
        .sc-grid > div { background: #f4f2ec; padding: .75rem 1rem; border-radius: 6px; text-align: center; }
        .sc-grid strong { display: block; font-size: 1.6rem; color: var(--ink); }
        .sc-grid span { color: var(--fog); font-size: .85rem; }
        .sc-banner { padding: 1rem 1.25rem; border-radius: 8px; margin-bottom: 1.25rem; }
        .sc-banner--info  { background: #fffbe6; border: 1px solid #d4a820; color: #5a4500; }
        .sc-banner--done  { background: #e8f4e8; border: 1px solid #4a8a4a; color: #1c4a1c; }
        .sc-banner--error { background: #fbe8e8; border: 1px solid #c44; color: #7a1a1a; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Sample Content</h1>
            <a href="/admin/settings/" class="admin-btn">Back to Settings</a>
        </div>

        <?php if ($flash): ?>
            <div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="sc-banner sc-banner--error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($result && !$result['skipped']): ?>
            <div class="sc-banner sc-banner--done">
                <strong>Sample content loaded.</strong>
                <div class="sc-grid">
                    <?php foreach ($result['created'] as $kind => $n): ?>
                        <div><strong><?= (int)$n ?></strong><span><?= e($kind) ?></span></div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($result['image_dir_warnings'])): ?>
                    <p style="margin-top:.75rem;">⚠ Couldn't create these upload dirs (sample images skipped there): <?= e(implode(', ', $result['image_dir_warnings'])) ?>. Check filesystem permissions on <code>public/uploads/</code>.</p>
                <?php endif; ?>
                <p style="margin-top:1rem;">
                    <a href="/" target="_blank" rel="noopener" class="admin-btn admin-btn--primary">Open public site →</a>
                    <a href="/admin/pieces/" class="admin-btn">Manage pottery</a>
                </p>
            </div>
        <?php endif; ?>

        <div class="sc-panel">
            <h2>What this does</h2>
            <p style="color:var(--fog);">
                Populates an empty install with realistic demo content so you can see what the site looks like
                fully populated — useful for evaluating the CMS, picking a theme, or showing it to someone
                before you've added your own work.
            </p>
            <ul class="sc-list">
                <li><strong><?= count(SampleContent::SAMPLE_POTTERY) ?> portfolio pieces</strong> — varied techniques, 2 featured</li>
                <li><strong><?= count(SampleContent::SAMPLE_EVENTS) ?> events</strong> — one pottery show, one class, one sale (dates start 2–11 weeks out)</li>
                <li><strong><?= count(SampleContent::SAMPLE_ANNOUNCEMENTS) ?> announcements</strong> — one linked to the upcoming Open Studio event</li>
                <li><strong><?= count(SampleContent::SAMPLE_PRODUCTS) ?> shop products</strong> — covering available / sold states</li>
                <li><strong><?= count(SampleContent::SAMPLE_SOCIAL_LINKS) ?> social links</strong> — placeholder Instagram / TikTok / YouTube handles</li>
            </ul>
            <p style="color:var(--fog);">
                Images are generated as small earthy-toned SVG placeholders (no copyright, ~1 KB each)
                written into <code>public/uploads/pottery/</code>, <code>products/</code>, and
                <code>announcements/</code>. Filenames are prefixed <code>sample-</code> so you can find
                or remove them later.
            </p>
        </div>

        <div class="sc-panel">
            <h2>Before you click</h2>
            <p>This is strictly additive — your existing data is left alone. But:</p>
            <ul class="sc-list">
                <li>The seeder runs once per install. To re-seed, wipe the demo rows first via
                    <a href="/admin/settings/reset-content">Reset Content</a> (content partition).</li>
                <li>When you're ready to ship the site to visitors, run the same reset-content flow to clear
                    the demo rows (or just edit/delete them individually as you replace them with real content).</li>
                <li>Sample social links point to placeholder URLs — replace with your real handles, or
                    delete them, before publishing.</li>
            </ul>
        </div>

        <form method="POST">
            <?= csrf_field() ?>
            <?php if ($alreadySeeded): ?>
                <div class="sc-banner sc-banner--info">
                    Sample content has already been loaded on this install.
                    Visit <a href="/admin/settings/reset-content">Reset Content</a> if you want to wipe and re-seed.
                </div>
                <button type="submit" class="admin-btn" disabled style="opacity:.5;cursor:not-allowed;">Load sample content (already loaded)</button>
            <?php else: ?>
                <button type="submit" class="admin-btn admin-btn--primary">Load sample content</button>
                <a href="/admin/settings/" class="admin-btn">Cancel</a>
            <?php endif; ?>
        </form>
    </div>
</main>
</body>
</html>
