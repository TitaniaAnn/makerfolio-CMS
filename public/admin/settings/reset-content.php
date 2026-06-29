<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

$result = null;
$errors = [];

$partitions = [
    'content'        => [
        'label'   => 'Content tables',
        'detail'  => count(ContentReset::CONTENT_TABLES) . ' tables (pottery, products, events, announcements, social, orders, templates) + reseed default shop categories',
    ],
    'uploads' => [
        'label'  => 'Upload files',
        'detail' => 'Wipe contents of ' . implode(', ', array_map(fn($d) => "uploads/$d/", ContentReset::UPLOAD_SUBDIRS)) . ' (directories themselves are kept)',
    ],
    'branding' => [
        'label'  => 'Branding settings',
        'detail' => count(ContentReset::BRANDING_SETTING_KEYS) . ' rows: site name, tagline, bio, hero copy, contact email, profile photo, privacy policy, nav external link',
    ],
    'text_overrides' => [
        'label'  => 'Page-text overrides',
        'detail' => 'All admin edits made via /admin/settings/page-text.php (rows in `settings` keyed text.*)',
    ],
    'email_overrides' => [
        'label'  => 'Email-template overrides',
        'detail' => 'All admin edits made via /admin/settings/email-templates.php (rows in `settings` keyed email.*)',
    ],
    'design' => [
        'label'  => 'Design (theme + sections + event labels)',
        'detail' => 'Theme overrides, event-type labels, page_sections — all reset to defaults',
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $confirm = trim((string)($_POST['confirm_phrase'] ?? ''));
    if ($confirm !== 'RESET CONTENT') {
        $errors[] = "Confirmation phrase didn't match. Type exactly: RESET CONTENT";
    }

    $selected = [];
    foreach (array_keys($partitions) as $key) {
        $selected[$key] = !empty($_POST['partitions'][$key]);
    }
    if (!array_filter($selected)) {
        $errors[] = 'Pick at least one partition to reset.';
    }

    if (!$errors) {
        try {
            $result = ContentReset::reset($selected, UPLOAD_PATH);
            // Reset the page-sections cache so the admin nav re-reads fresh state on next request.
            PageSections::resetCache();
            ActivityLog::log('content.reset', null, null, ['partitions' => array_keys(array_filter($selected))]);
        } catch (\Throwable $e) {
            $errors[] = 'Reset failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Content — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/pages/settings-reset-content.css">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Reset Content</h1>
            <a href="/admin/settings/" class="admin-btn">Back to Settings</a>
        </div>

        <?php foreach ($errors as $err): ?>
            <div class="flash flash--error"><?= e($err) ?></div>
        <?php endforeach; ?>

        <?php if ($result !== null && empty($errors)): ?>
            <div class="reset-results">
                <h2>Reset complete</h2>
                <ul>
                    <?php foreach ($result['db_log'] as $line): ?>
                        <li><?= e($line) ?></li>
                    <?php endforeach; ?>
                    <?php foreach ($result['fs_log'] as $line): ?>
                        <li><?= e($line) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if (!empty($result['fs_failed'])): ?>
                    <div class="reset-results reset-failed reset-results--spaced">
                        <h2>Some files couldn't be removed</h2>
                        <p>Filesystem permissions blocked these paths. Remove them manually:</p>
                        <ul>
                            <?php foreach ($result['fs_failed'] as $p): ?>
                                <li><?= e($p) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="danger-card">
            <h2>⚠ Destructive — read this</h2>
            <p>
                This wipes the selected partitions from your install. Useful for handing the
                template off to another potter as a clean slate. <strong>There is no undo.</strong>
                Take a backup first if you're not sure.
            </p>
            <p class="reset-confirm-note">
                <strong>Always preserved:</strong> the database schema, admin users, all login-provider
                settings (so you stay logged in), the migrations ledger, and the shop currency.
            </p>
        </div>

        <form method="POST">
            <?= csrf_field() ?>

            <div class="admin-card">
                <h2>What to reset</h2>
                <?php foreach ($partitions as $key => $info): ?>
                    <div class="partition-row">
                        <input type="checkbox"
                               id="part_<?= e($key) ?>"
                               name="partitions[<?= e($key) ?>]"
                               value="1"
                               checked>
                        <div>
                            <label for="part_<?= e($key) ?>"><?= e($info['label']) ?></label>
                            <small><?= e($info['detail']) ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="admin-card">
                <h2>Confirm</h2>
                <p class="form-hint">Type <code>RESET CONTENT</code> (exactly, all caps) to enable the button below.</p>
                <input type="text" name="confirm_phrase" class="confirm-input" placeholder="RESET CONTENT" autocomplete="off">
            </div>

            <div class="form-actions">
                <button type="submit" class="admin-btn admin-btn--primary reset-submit-btn">Reset Content</button>
                <a href="/admin/settings/" class="admin-btn">Cancel</a>
            </div>
        </form>
    </div>
</main>
</body>
</html>
