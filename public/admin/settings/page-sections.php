<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

$pageLabels = [
    'home'      => 'Homepage',
    'about'     => 'About Page',
    'portfolio' => 'Portfolio Page',
    'templates' => 'Templates Page',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // A submit button named `reset_page` carries the page slug as its value
    // (e.g. <button name="reset_page" value="home">). When present, treat the
    // request as a reset for that page and ignore the save inputs. Otherwise
    // fall through to the full save.
    $resetPage = (string)($_POST['reset_page'] ?? '');
    if ($resetPage !== '' && isset(PageSections::CATALOG[$resetPage])) {
        foreach (PageSections::CATALOG[$resetPage] as $sectionKey => $_label) {
            $defaultSort = PageSections::DEFAULT_SORT_ORDER[$resetPage][$sectionKey] ?? 0;
            Database::query(
                "INSERT INTO page_sections (page, section_key, is_visible, sort_order)
                 VALUES (?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE is_visible = 1, sort_order = VALUES(sort_order)",
                [$resetPage, $sectionKey, $defaultSort]
            );
        }
        PageSections::resetCache();
        ActivityLog::log('settings.page_sections_save', 'page', $resetPage, ['reset' => true]);
        flash('success', ($pageLabels[$resetPage] ?? $resetPage) . ' sections reset to defaults.');
        redirect(SITE_URL . '/admin/settings/page-sections');
    }

    // Visibility-only save. The home page's sort_order is now controlled via
    // drag-to-reorder (which posts to /admin/reorder.php out-of-band), so we
    // don't touch sort_order here — that would clobber the drag state.
    foreach (PageSections::CATALOG as $page => $sections) {
        foreach (array_keys($sections) as $sectionKey) {
            $visible      = !empty($_POST['is_visible'][$page][$sectionKey]);
            $defaultSort  = PageSections::DEFAULT_SORT_ORDER[$page][$sectionKey] ?? 0;
            Database::query(
                "INSERT INTO page_sections (page, section_key, is_visible, sort_order)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE is_visible = VALUES(is_visible)",
                [$page, $sectionKey, $visible ? 1 : 0, $defaultSort]
            );
        }
    }
    PageSections::resetCache();
    ActivityLog::log('settings.page_sections_save');
    flash('success', 'Page sections saved.');
    redirect(SITE_URL . '/admin/settings/page-sections');
}

// Build current state for the form (keyed by page → section → row).
$current = [];
$rows = Database::fetchAll("SELECT page, section_key, is_visible, sort_order FROM page_sections");
foreach ($rows as $row) {
    $current[$row['page']][$row['section_key']] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Sections — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        .ps-section { padding: 1.25rem; margin-bottom: 1rem; background: #fff; border: 1px solid var(--sand,#e8e4d8); border-radius: 8px; }
        .ps-section__head { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom: .75rem; }
        .ps-section__head h2 { margin: 0; font-size: 1.05rem; }
        .ps-section__hint { color: var(--fog,#7a8090); font-size: .8rem; margin: 0 0 .75rem; }
        .ps-row { display: grid; grid-template-columns: 32px 60px 1fr; gap: 1rem; padding: .55rem 0; align-items: center; border-top: 1px solid #f4f2ec; }
        .ps-row--no-drag { grid-template-columns: 60px 1fr; }
        .ps-row:first-of-type { border-top: 0; }
        .ps-row__toggle { display:flex; align-items:center; gap:.5rem; }
        .ps-row__label { color: var(--ink); }
        .ps-row__key { display:block; font-size: .72rem; color: var(--fog,#7a8090); font-family: ui-monospace, Menlo, monospace; margin-top: .15rem; }
        .ps-reset-form { display:inline; }
        .ps-form-actions { display:flex; gap:.5rem; padding: 1rem 0; position: sticky; bottom: 0; background: linear-gradient(to top, var(--cream,#f8f6f0) 70%, rgba(248,246,240,0)); }
    </style>
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Page Sections</h1>
            <a href="/admin/settings/" class="admin-btn">Back to Settings</a>
        </div>

        <?php $flash = getFlash(); if ($flash): ?>
            <div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
        <?php endif; ?>

        <p style="color:var(--fog,#7a8090);">
            Toggle which sections appear on each public page, and (for the homepage)
            drag the <span class="reorder-handle" style="display:inline; cursor:default;">⋮⋮</span> handle
            to reorder them — order saves automatically. Untoggling a section that's
            already data-gated (e.g. "Featured Work" when there are no featured
            pieces) just removes the slot — it doesn't force-render an empty section.
        </p>

        <form method="POST">
            <?= csrf_field() ?>
            <?php foreach (PageSections::CATALOG as $page => $sections):
                // For the home page, render sections in current sort_order so
                // drag-to-reorder reflects the live order. Inner pages just use
                // catalog declaration order — there's no reordering anyway.
                $renderOrder = array_keys($sections);
                if ($page === 'home') {
                    usort($renderOrder, function ($a, $b) use ($current, $page) {
                        $sa = (int)($current[$page][$a]['sort_order'] ?? PageSections::DEFAULT_SORT_ORDER[$page][$a] ?? 0);
                        $sb = (int)($current[$page][$b]['sort_order'] ?? PageSections::DEFAULT_SORT_ORDER[$page][$b] ?? 0);
                        return $sa <=> $sb;
                    });
                }
            ?>
                <section class="ps-section" id="page-<?= e($page) ?>">
                    <div class="ps-section__head">
                        <h2><?= e($pageLabels[$page] ?? $page) ?></h2>
                        <button type="submit"
                                name="reset_page"
                                value="<?= e($page) ?>"
                                class="admin-btn admin-btn--secondary"
                                onclick="return confirm('Reset all <?= e($pageLabels[$page] ?? $page) ?> sections to their default visibility and order? Unsaved edits on this page will also be lost.');">
                            Reset to defaults
                        </button>
                    </div>
                    <?php if ($page !== 'home'): ?>
                        <p class="ps-section__hint">Inner page — visibility only.</p>
                    <?php endif; ?>
                    <div<?= $page === 'home' ? ' data-reorder-kind="page_sections" data-page="home"' : '' ?>>
                    <?php foreach ($renderOrder as $sectionKey):
                        $label   = $sections[$sectionKey];
                        $row     = $current[$page][$sectionKey] ?? null;
                        $checked = $row === null ? true : ((int)$row['is_visible'] === 1);
                    ?>
                        <div class="ps-row <?= $page === 'home' ? '' : 'ps-row--no-drag' ?>"
                             <?= $page === 'home' ? 'data-section-key="' . e($sectionKey) . '"' : '' ?>>
                            <?php if ($page === 'home'): ?>
                                <span class="reorder-handle" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</span>
                            <?php endif; ?>
                            <div class="ps-row__toggle">
                                <input type="hidden" name="is_visible[<?= e($page) ?>][<?= e($sectionKey) ?>]" value="0">
                                <input type="checkbox"
                                       id="vis_<?= e($page) ?>_<?= e($sectionKey) ?>"
                                       name="is_visible[<?= e($page) ?>][<?= e($sectionKey) ?>]"
                                       value="1" <?= $checked ? 'checked' : '' ?>>
                            </div>
                            <label class="ps-row__label" for="vis_<?= e($page) ?>_<?= e($sectionKey) ?>">
                                <?= e($label) ?>
                                <span class="ps-row__key">text key: <?= e($page . '.' . $sectionKey) ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="ps-form-actions">
                <button type="submit" class="admin-btn admin-btn--primary">Save All</button>
                <a href="/admin/settings/page-sections" class="admin-btn">Cancel</a>
                <a href="/" target="_blank" class="admin-btn">Preview Site ↗</a>
            </div>
        </form>
    </div>
</main>
<script src="/admin/js/sortable.min.js"></script>
<script src="/admin/js/reorder.js"></script>
</body>
</html>
