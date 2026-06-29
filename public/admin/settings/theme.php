<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $errors = [];

    $preset = (string)($_POST['theme_preset'] ?? '');
    if (!Theme::isValidPreset($preset)) {
        $errors[] = 'Unknown preset.';
    }

    // Overrides: empty means "use preset"; non-empty must be hex. Roles come
    // from Theme::overridableRoles() so new roles wire in automatically.
    $overrides = [];
    foreach (Theme::overridableRoles() as $role => $settingKey) {
        $postKey = 'override_' . $role;
        $raw = trim((string)($_POST[$postKey] ?? ''));
        if ($raw === '') {
            $overrides[$settingKey] = '';
        } elseif (Theme::isValidHex($raw)) {
            $overrides[$settingKey] = strtoupper($raw);
        } else {
            $errors[] = ucfirst($role) . ' must be a 6-digit hex color (e.g. #D4A820) or blank.';
        }
    }

    $displayFont = (string)($_POST['theme_font_display'] ?? '');
    $bodyFont    = (string)($_POST['theme_font_body']    ?? '');
    $eyebrowFont = (string)($_POST['theme_font_eyebrow'] ?? '');
    $radiusScale = (string)($_POST['theme_radius_scale'] ?? '');
    $shadowScale = (string)($_POST['theme_shadow_scale'] ?? '');

    if (!Theme::isValidDisplayFont($displayFont)) $errors[] = 'Unknown display font.';
    if (!Theme::isValidBodyFont($bodyFont))       $errors[] = 'Unknown body font.';
    if (!Theme::isValidEyebrowFont($eyebrowFont)) $errors[] = 'Unknown eyebrow font.';
    if (!Theme::isValidRadiusScale($radiusScale)) $errors[] = 'Unknown radius scale.';
    if (!Theme::isValidShadowScale($shadowScale)) $errors[] = 'Unknown shadow scale.';

    if ($errors) {
        flash('error', implode(' ', $errors));
        redirect(SITE_URL . '/admin/settings/theme');
    }

    $writes = [
        Theme::SETTING_PRESET        => $preset,
        Theme::SETTING_FONT_DISPLAY  => $displayFont,
        Theme::SETTING_FONT_BODY     => $bodyFont,
        Theme::SETTING_FONT_EYEBROW  => $eyebrowFont,
        Theme::SETTING_RADIUS_SCALE  => $radiusScale,
        Theme::SETTING_SHADOW_SCALE  => $shadowScale,
    ] + $overrides;

    foreach ($writes as $key => $value) {
        Database::query(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [$key, $value]
        );
    }

    ActivityLog::log('settings.theme_save', null, null, ['preset' => $preset]);
    flash('success', 'Theme saved.');
    redirect(SITE_URL . '/admin/settings/theme');
}

$current  = Theme::current();
$presets  = Theme::presets();
$dFonts   = Theme::displayFonts();
$bFonts   = Theme::bodyFonts();
$eFonts   = Theme::eyebrowFonts();
$radii    = Theme::radiusScales();
$shadows  = Theme::shadowScales();

// Current override raw values (empty string when "use preset"), keyed by role.
$ov = [];
foreach (Theme::overridableRoles() as $role => $settingKey) {
    $ov[$role] = setting($settingKey, '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Theme — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/pages/settings-theme.css">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Theme</h1>
            <a href="/admin/settings/" class="admin-btn">Back to Settings</a>
        </div>

        <?php $flash = getFlash(); if ($flash): ?>
            <div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
        <?php endif; ?>

        <div class="theme-layout">
        <form method="POST" class="admin-form" id="theme-form">
            <?= csrf_field() ?>

            <div class="admin-card">
                <h2>Preset</h2>
                <p class="form-hint">Pick a base palette. Use the section below to override individual colors if needed.</p>
                <div class="theme-preset-grid">
                    <?php foreach ($presets as $key => $preset):
                        $isActive = $current['preset_key'] === $key;
                        $p = $preset['palette'];
                    ?>
                        <label class="theme-preset <?= $isActive ? 'is-active' : '' ?>" data-preset="<?= e($key) ?>">
                            <input type="radio" name="theme_preset" value="<?= e($key) ?>" <?= $isActive ? 'checked' : '' ?>>
                            <div class="theme-preset__label"><?= e($preset['label']) ?></div>
                            <div class="theme-preset__desc"><?= e($preset['description']) ?></div>
                            <div class="theme-swatches">
                                <svg class="theme-swatch" viewBox="0 0 1 1" preserveAspectRatio="none" role="img" aria-label="Primary"><title>Primary</title><rect width="1" height="1" fill="<?= e($p['primary']) ?>"/></svg>
                                <svg class="theme-swatch" viewBox="0 0 1 1" preserveAspectRatio="none" role="img" aria-label="Accent"><title>Accent</title><rect width="1" height="1" fill="<?= e($p['accent']) ?>"/></svg>
                                <svg class="theme-swatch" viewBox="0 0 1 1" preserveAspectRatio="none" role="img" aria-label="Background"><title>Background</title><rect width="1" height="1" fill="<?= e($p['background']) ?>"/></svg>
                                <svg class="theme-swatch" viewBox="0 0 1 1" preserveAspectRatio="none" role="img" aria-label="Surface"><title>Surface</title><rect width="1" height="1" fill="<?= e($p['surface']) ?>"/></svg>
                                <svg class="theme-swatch" viewBox="0 0 1 1" preserveAspectRatio="none" role="img" aria-label="Cool"><title>Cool</title><rect width="1" height="1" fill="<?= e($p['cool']) ?>"/></svg>
                                <svg class="theme-swatch" viewBox="0 0 1 1" preserveAspectRatio="none" role="img" aria-label="Text"><title>Text</title><rect width="1" height="1" fill="<?= e($p['text']) ?>"/></svg>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="admin-card">
                <h2>Color Overrides <small class="u-note-opt">(optional)</small></h2>
                <p class="form-hint">Leave blank to use the preset's value. Lighter/darker shades are derived from these.</p>
                <div class="form-grid">
                    <?php foreach ([
                        'primary'    => ['Primary',    $current['palette']['primary']],
                        'accent'     => ['Accent',     $current['palette']['accent']],
                        'background' => ['Background', $current['palette']['background']],
                        'surface'    => ['Surface (cards)', $current['palette']['surface']],
                        'text'       => ['Text',       $current['palette']['text']],
                        'cool'       => ['Cool accent', $current['palette']['cool']],
                    ] as $role => [$label, $resolved]):
                        $raw = $ov[$role];
                    ?>
                        <div class="form-group">
                            <label><?= e($label) ?></label>
                            <div class="theme-color-row">
                                <input type="color"
                                       value="<?= e($raw !== '' ? $raw : $resolved) ?>"
                                       data-color-target="override_<?= e($role) ?>">
                                <input type="text"
                                       id="override_<?= e($role) ?>"
                                       name="override_<?= e($role) ?>"
                                       value="<?= e($raw) ?>"
                                       placeholder="#RRGGBB (blank = preset)"
                                       pattern="^#[0-9a-fA-F]{6}$|^$"
                                       maxlength="7">
                                <button type="button" class="admin-btn admin-btn--secondary preset-hint"
                                        data-action="reset-color" data-role="<?= e($role) ?>" data-resolved="<?= e($resolved) ?>">
                                    Reset
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="admin-card">
                <h2>Fonts</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Display Font (headings)</label>
                        <select name="theme_font_display">
                            <?php foreach ($dFonts as $key => $f): ?>
                                <option value="<?= e($key) ?>" <?= $current['display_font']['key'] === $key ? 'selected' : '' ?>>
                                    <?= e($f['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Body Font</label>
                        <select name="theme_font_body">
                            <?php foreach ($bFonts as $key => $f): ?>
                                <option value="<?= e($key) ?>" <?= $current['body_font']['key'] === $key ? 'selected' : '' ?>>
                                    <?= e($f['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Eyebrow Font (hero accent)</label>
                        <select name="theme_font_eyebrow">
                            <?php foreach ($eFonts as $key => $f): ?>
                                <option value="<?= e($key) ?>" <?= $current['eyebrow_font']['key'] === $key ? 'selected' : '' ?>>
                                    <?= e($f['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <h2>Shapes</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Corner Radius</label>
                        <div class="radio-row">
                            <?php foreach ($radii as $key => $r): ?>
                                <label>
                                    <input type="radio" name="theme_radius_scale" value="<?= e($key) ?>" <?= $current['radius']['key'] === $key ? 'checked' : '' ?>>
                                    <?= e($r['label']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Shadow Depth</label>
                        <div class="radio-row">
                            <?php foreach ($shadows as $key => $sh): ?>
                                <label>
                                    <input type="radio" name="theme_shadow_scale" value="<?= e($key) ?>" <?= $current['shadow']['key'] === $key ? 'checked' : '' ?>>
                                    <?= e($sh['label']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admin-form__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Save Theme</button>
                <a href="/" target="_blank" class="admin-btn admin-btn--secondary">Open Site ↗</a>
            </div>
        </form>
        <aside class="theme-preview">
            <div class="theme-preview__label">Live preview (updates as you edit)</div>
            <iframe id="theme-preview-frame" class="theme-preview__frame" src="/" title="Theme preview"></iframe>
        </aside>
        </div>
    </div>
</main>
<script src="/admin/js/theme.js"></script>
</body>
</html>
