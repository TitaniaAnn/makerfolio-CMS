<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $fields = [
        'site_name', 'tagline', 'bio', 'about_text',
        'hero_title', 'hero_subtitle', 'hero_image', 'shop_intro', 'contact_email', 'profile_photo',
        // Site copy / page text (added in migration 016)
        'privacy_policy_html', 'privacy_updated',
        'nav_external_url', 'nav_external_label',
    ];
    foreach ($fields as $key) {
        if (isset($_POST[$key])) {
            // privacy_policy_html is admin-trusted HTML — only trim() it; never escape.
            $raw = $_POST[$key];
            Database::query(
                "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                [$key, trim($raw)]
            );
        }
    }
    // Event-type labels — composed from per-key inputs into a JSON blob so the
    // events table's ENUM and the public-page rendering stay in sync.
    if (isset($_POST['event_type_label'])) {
        $labels = [];
        foreach (\EventTypes::DEFAULT_LABELS as $key => $default) {
            $val = trim((string)($_POST['event_type_label'][$key] ?? ''));
            $labels[$key] = $val !== '' ? $val : $default;
        }
        Database::query(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('event_type_labels', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [json_encode($labels, JSON_UNESCAPED_UNICODE)]
        );
    }
    // Ensure hero upload dir exists
    $heroDir = UPLOAD_PATH . 'hero/';
    if (!is_dir($heroDir)) { mkdir($heroDir, 0755, true); }

    // Handle hero image upload
    if (!empty($_FILES['hero_image_file']['name']) && $_FILES['hero_image_file']['error'] === UPLOAD_ERR_OK) {
        try {
            $result = ImageUpload::upload($_FILES['hero_image_file'], 'hero');
            Database::query(
                "INSERT INTO settings (setting_key, setting_value) VALUES ('hero_image', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                [$result['path']]
            );
        } catch (RuntimeException $e) {
            flash('error', 'Image upload failed: ' . $e->getMessage());
            redirect(SITE_URL . '/admin/settings/');
        }
    }
    // Handle profile photo upload
    if (!empty($_FILES['profile_photo_file']['name']) && $_FILES['profile_photo_file']['error'] === UPLOAD_ERR_OK) {
        $profileDir = UPLOAD_PATH . 'profile/';
        if (!is_dir($profileDir)) { mkdir($profileDir, 0755, true); }
        try {
            $result = ImageUpload::upload($_FILES['profile_photo_file'], 'profile');
            Database::query(
                "INSERT INTO settings (setting_key, setting_value) VALUES ('profile_photo', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                [$result['path']]
            );
        } catch (RuntimeException $e) {
            flash('error', 'Profile photo upload failed: ' . $e->getMessage());
            redirect(SITE_URL . '/admin/settings/');
        }
    }
    ActivityLog::log('settings.branding_save');
    flash('success', 'Settings saved!');
    redirect(SITE_URL . '/admin/settings/');
}

// Load current settings
$s = [];
$rows = Database::fetchAll("SELECT setting_key, setting_value FROM settings");
foreach ($rows as $row) { $s[$row['setting_key']] = $row['setting_value']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Settings — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link rel="stylesheet" href="/admin/css/pages/settings-index.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Site Settings</h1>
            <div>
                <a href="/admin/settings/theme" class="admin-btn">Theme</a>
                <a href="/admin/settings/auth" class="admin-btn">Login Providers</a>
                <a href="/admin/settings/page-text" class="admin-btn">Page Text</a>
                <a href="/admin/settings/page-sections" class="admin-btn">Page Sections</a>
                <a href="/admin/settings/email-templates" class="admin-btn">Email Templates</a>
                <a href="/admin/settings/health" class="admin-btn">System Health</a>
                <a href="/admin/settings/schema-health" class="admin-btn">Schema Health</a>
                <a href="/admin/settings/sample-content" class="admin-btn">Sample Content</a>
                <a href="/admin/settings/reset-content" class="admin-btn settings-reset-btn">Reset Content</a>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" class="admin-form">
            <?= csrf_field() ?>
            <div class="admin-card">
                <h2>Branding</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Site Name</label>
                        <input type="text" name="site_name" value="<?= e($s['site_name'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Tagline</label>
                        <input type="text" name="tagline" value="<?= e($s['tagline'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Contact Email</label>
                        <input type="email" name="contact_email" value="<?= e($s['contact_email'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <h2>Homepage Hero</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Hero Title</label>
                        <input type="text" name="hero_title" value="<?= e($s['hero_title'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Hero Subtitle</label>
                        <input type="text" name="hero_subtitle" value="<?= e($s['hero_subtitle'] ?? '') ?>">
                    </div>
                    <div class="form-group form-group--full">
                        <label>Hero Background Photo</label>
                        <?php if (!empty($s['hero_image'])): ?>
                        <div class="settings-img-row">
                            <img src="/uploads/<?= e($s['hero_image']) ?>" class="settings-hero-thumb">
                            <p class="settings-img-note">Current hero photo — upload a new one to replace it</p>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="hero_image_file" accept="image/*">
                        <p class="form-hint">Recommended: landscape photo, at least 1600×900px. The photo will have a sage overlay applied on top.</p>
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <h2>About</h2>
                <div class="form-grid">
                    <div class="form-group form-group--full">
                        <label>Bio (About page — supports line breaks)</label>
                        <textarea name="bio" rows="6"><?= e($s['bio'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group form-group--full">
                        <label>About Strip Text (Homepage)</label>
                        <textarea name="about_text" rows="3"><?= e($s['about_text'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group form-group--full">
                        <label>Profile Photo (About page)</label>
                        <?php if (!empty($s['profile_photo'])): ?>
                        <div class="settings-img-row--avatar">
                            <img src="/uploads/<?= e($s['profile_photo']) ?>" class="settings-avatar-thumb">
                            <p class="settings-img-note--inline">Current profile photo — upload a new one to replace it</p>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="profile_photo_file" accept="image/*">
                        <p class="form-hint">Square crop works best. Min 400×400px recommended.</p>
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <h2>Shop</h2>
                <div class="form-group">
                    <label>Shop Introduction Text</label>
                    <textarea name="shop_intro" rows="2"><?= e($s['shop_intro'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="admin-card">
                <h2>Navigation</h2>
                <p class="form-hint">Optional external link in the main nav (e.g. a companion app, blog, or studio booking site). Leave the URL blank to hide it.</p>
                <div class="form-grid">
                    <div class="form-group">
                        <label>External link label</label>
                        <input type="text" name="nav_external_label" value="<?= e($s['nav_external_label'] ?? 'App') ?>" placeholder="App">
                    </div>
                    <div class="form-group">
                        <label>External link URL</label>
                        <input type="url" name="nav_external_url" value="<?= e($s['nav_external_url'] ?? '') ?>" placeholder="https://example.com">
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <h2>Event Type Labels</h2>
                <p class="form-hint">Display labels for each event type. Adding a new type requires a schema change; renaming is free. Leave blank to use the default.</p>
                <?php
                    $currentLabels = \EventTypes::labels();
                ?>
                <div class="form-grid">
                    <?php foreach (\EventTypes::DEFAULT_LABELS as $typeKey => $defaultLabel): ?>
                    <div class="form-group">
                        <label><?= e($typeKey) ?></label>
                        <input type="text" name="event_type_label[<?= e($typeKey) ?>]" value="<?= e($currentLabels[$typeKey] ?? $defaultLabel) ?>" placeholder="<?= e($defaultLabel) ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="admin-card">
                <h2>Privacy Policy</h2>
                <p class="form-hint">Body of <code>/privacy.php</code>. Free-form HTML — paste your policy text or a generator's output. Use <code>&lt;h2&gt;</code> for section headings to match site styling. Leave blank to show a placeholder.</p>
                <div class="form-group">
                    <label>"Last updated" line</label>
                    <input type="text" name="privacy_updated" value="<?= e($s['privacy_updated'] ?? '') ?>" placeholder="March 2026">
                </div>
                <div class="form-group form-group--full">
                    <label>Privacy policy HTML</label>
                    <textarea name="privacy_policy_html" rows="14" class="settings-html-textarea"><?= e($s['privacy_policy_html'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="admin-btn admin-btn--primary">Save Settings</button>
            </div>
        </form>
    </div>
</main>
</body>
</html>
