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

    // Overrides: empty means "use preset"; non-empty must be hex.
    $overrides = [];
    foreach ([
        Theme::SETTING_OVERRIDE_PRIMARY    => 'override_primary',
        Theme::SETTING_OVERRIDE_ACCENT     => 'override_accent',
        Theme::SETTING_OVERRIDE_BG         => 'override_background',
        Theme::SETTING_OVERRIDE_TEXT       => 'override_text',
    ] as $settingKey => $postKey) {
        $raw = trim((string)($_POST[$postKey] ?? ''));
        if ($raw === '') {
            $overrides[$settingKey] = '';
        } elseif (Theme::isValidHex($raw)) {
            $overrides[$settingKey] = strtoupper($raw);
        } else {
            $errors[] = ucfirst(str_replace('_', ' ', $postKey)) . ' must be a 6-digit hex color (e.g. #D4A820) or blank.';
        }
    }

    $displayFont = (string)($_POST['theme_font_display'] ?? '');
    $bodyFont    = (string)($_POST['theme_font_body']    ?? '');
    $radiusScale = (string)($_POST['theme_radius_scale'] ?? '');
    $shadowScale = (string)($_POST['theme_shadow_scale'] ?? '');

    if (!Theme::isValidDisplayFont($displayFont)) $errors[] = 'Unknown display font.';
    if (!Theme::isValidBodyFont($bodyFont))       $errors[] = 'Unknown body font.';
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
$radii    = Theme::radiusScales();
$shadows  = Theme::shadowScales();

// Current override raw values (empty string when "use preset")
$ov = [
    'primary'    => setting(Theme::SETTING_OVERRIDE_PRIMARY,    ''),
    'accent'     => setting(Theme::SETTING_OVERRIDE_ACCENT,     ''),
    'background' => setting(Theme::SETTING_OVERRIDE_BG,         ''),
    'text'       => setting(Theme::SETTING_OVERRIDE_TEXT,       ''),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Theme — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        .theme-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(320px, 420px); gap: 1.5rem; align-items: start; }
        @media (max-width: 1100px) { .theme-layout { grid-template-columns: 1fr; } }
        .theme-preview { position: sticky; top: 1rem; }
        .theme-preview__label { font-size:.75rem; text-transform:uppercase; letter-spacing:.08em; color: var(--fog,#7a8090); margin-bottom:.35rem; }
        .theme-preview__frame { width: 100%; height: 580px; border: 1px solid var(--sand,#e8e4d8); border-radius: 8px; background: #fff; box-shadow: 0 4px 16px rgba(30,36,48,.06); }
        .theme-preset-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
        .theme-preset {
            position: relative; display: block; cursor: pointer;
            border: 2px solid var(--sand, #e8e4d8); border-radius: 12px;
            padding: 1rem 1rem .9rem; background: #fff;
            transition: border-color .15s, box-shadow .15s, transform .15s;
        }
        .theme-preset:hover { border-color: var(--clay, #d4a820); transform: translateY(-1px); }
        .theme-preset input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
        /* Selected state — must be visually distinct at a glance. */
        .theme-preset.is-active {
            border-color: var(--clay, #d4a820);
            border-width: 3px;
            padding: calc(1rem - 1px) calc(1rem - 1px) calc(.9rem - 1px);  /* compensate so card doesn't jump */
            background: linear-gradient(180deg, #fffbe6 0%, #fff 60%);
            box-shadow: 0 4px 14px rgba(212,168,32,.25), 0 0 0 4px rgba(212,168,32,.12);
        }
        .theme-preset.is-active::after {
            content: "✓ Selected";
            position: absolute; top: -10px; right: 12px;
            background: var(--clay, #d4a820); color: #fff;
            font-size: .7rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase;
            padding: .2rem .55rem; border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,.18);
        }
        .theme-preset__label { font-weight: 600; margin-bottom: .25rem; }
        .theme-preset__desc { font-size: .8rem; color: var(--fog, #7a8090); margin-bottom: .65rem; }
        .theme-swatches { display: flex; gap: .35rem; }
        .theme-swatch { width: 100%; height: 28px; border-radius: 4px; border: 1px solid rgba(0,0,0,.08); }
        .theme-color-row { display: flex; align-items: center; gap: .5rem; }
        .theme-color-row input[type="color"] { width: 56px; height: 38px; padding: 0; border: 1px solid var(--sand, #e8e4d8); border-radius: 6px; background: #fff; }
        .theme-color-row input[type="text"] { flex: 1; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .theme-color-row .preset-hint { font-size: .75rem; color: var(--fog, #7a8090); white-space: nowrap; }
        .radio-row { display: flex; gap: 1rem; flex-wrap: wrap; }
        .radio-row label { display: inline-flex; align-items: center; gap: .35rem; font-weight: 500; }
    </style>
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
                                <span class="theme-swatch" style="background:<?= e($p['primary']) ?>" title="Primary"></span>
                                <span class="theme-swatch" style="background:<?= e($p['accent']) ?>" title="Accent"></span>
                                <span class="theme-swatch" style="background:<?= e($p['background']) ?>" title="Background"></span>
                                <span class="theme-swatch" style="background:<?= e($p['surface']) ?>" title="Surface"></span>
                                <span class="theme-swatch" style="background:<?= e($p['cool']) ?>" title="Cool"></span>
                                <span class="theme-swatch" style="background:<?= e($p['text']) ?>" title="Text"></span>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="admin-card">
                <h2>Color Overrides <small style="font-weight:400; color:var(--fog,#7a8090);">(optional)</small></h2>
                <p class="form-hint">Leave blank to use the preset's value. Lighter/darker shades are derived from these.</p>
                <div class="form-grid">
                    <?php foreach ([
                        'primary'    => ['Primary',    $current['palette']['primary']],
                        'accent'     => ['Accent',     $current['palette']['accent']],
                        'background' => ['Background', $current['palette']['background']],
                        'text'       => ['Text',       $current['palette']['text']],
                    ] as $role => [$label, $resolved]):
                        $raw = $ov[$role];
                    ?>
                        <div class="form-group">
                            <label><?= e($label) ?></label>
                            <div class="theme-color-row">
                                <input type="color"
                                       value="<?= e($raw !== '' ? $raw : $resolved) ?>"
                                       oninput="document.getElementById('override_<?= e($role) ?>').value = this.value.toUpperCase();">
                                <input type="text"
                                       id="override_<?= e($role) ?>"
                                       name="override_<?= e($role) ?>"
                                       value="<?= e($raw) ?>"
                                       placeholder="#RRGGBB (blank = preset)"
                                       pattern="^#[0-9a-fA-F]{6}$|^$"
                                       maxlength="7">
                                <button type="button" class="admin-btn admin-btn--secondary preset-hint"
                                        onclick="this.previousElementSibling.value=''; this.previousElementSibling.previousElementSibling.value='<?= e($resolved) ?>';">
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
<script>
    // Highlight the active preset card as the user clicks.
    document.querySelectorAll('.theme-preset').forEach(card => {
        card.addEventListener('click', () => {
            document.querySelectorAll('.theme-preset').forEach(c => c.classList.remove('is-active'));
            card.classList.add('is-active');
        });
    });

    // Live preview — collect form values, encode as base64 JSON, push into the
    // iframe URL. Debounced so rapid input doesn't thrash the iframe.
    const form    = document.getElementById('theme-form');
    const frame   = document.getElementById('theme-preview-frame');
    let debounce  = null;
    function rebuildPreview() {
        const preset = form.querySelector('input[name="theme_preset"]:checked');
        const blob = {
            preset: preset ? preset.value : null,
            overrides: {
                primary:    valOrSkip('override_primary'),
                accent:     valOrSkip('override_accent'),
                background: valOrSkip('override_background'),
                text:       valOrSkip('override_text'),
            },
            fonts: {
                display: form.querySelector('[name="theme_font_display"]').value,
                body:    form.querySelector('[name="theme_font_body"]').value,
            },
            radius: form.querySelector('input[name="theme_radius_scale"]:checked')?.value,
            shadow: form.querySelector('input[name="theme_shadow_scale"]:checked')?.value,
        };
        const encoded = btoa(JSON.stringify(blob));
        frame.src = '/?_theme_preview=' + encodeURIComponent(encoded);
    }
    function valOrSkip(name) {
        const el = form.querySelector('input[name="' + name + '"]');
        const v  = el ? el.value.trim() : '';
        return v === '' ? undefined : v;
    }
    function schedulePreview() {
        clearTimeout(debounce);
        debounce = setTimeout(rebuildPreview, 250);
    }
    form.addEventListener('input',  schedulePreview);
    form.addEventListener('change', schedulePreview);
    // Initial render so the iframe shows the current edited state, not the persisted one.
    schedulePreview();
</script>
</body>
</html>
