<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$category = trim($_GET['category'] ?? '');

if ($category !== '') {
    $templates = Database::fetchAll(
        "SELECT * FROM piece_templates WHERE category = ? ORDER BY sort_order ASC, created_at DESC",
        [$category]
    );
} else {
    $templates = Database::fetchAll(
        "SELECT * FROM piece_templates ORDER BY sort_order ASC, created_at DESC"
    );
}

// Attach files to each template
$templateFiles = [];
if (!empty($templates)) {
    $ids = implode(',', array_map('intval', array_column($templates, 'id')));
    $rows = Database::fetchAll(
        "SELECT * FROM piece_template_files WHERE template_id IN ($ids) ORDER BY template_id ASC, sort_order ASC"
    );
    foreach ($rows as $row) {
        $templateFiles[$row['template_id']][] = $row;
    }
}

$categories = Database::fetchAll(
    "SELECT DISTINCT category FROM piece_templates WHERE category != '' ORDER BY category ASC"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(PageText::get('titles', 'templates')) ?> — <?= e(setting('site_name')) ?></title>
    <?= PageMeta::renderHead([
        'title'       => PageText::get('titles', 'templates') . ' — ' . setting('site_name', 'My Pottery'),
        'description' => PageText::get('templates', 'header_sub'),
    ]) ?>
    <link rel="stylesheet" href="/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/favicon-512.png">
    <link rel="apple-touch-icon" href="/favicon-512.png">
    <link rel="stylesheet" href="/css/pages/downloads.css">
</head>
<body>
<?php include __DIR__ . '/../templates/nav.php'; ?>

<div class="page-header">
    <div class="container">
        <h1 class="page-header__title"><?= e(PageText::get('templates', 'header_title')) ?></h1>
        <p class="page-header__sub"><?= e(PageText::get('templates', 'header_sub')) ?></p>
    </div>
</div>

<section class="section">
    <div class="container">

        <?php if (PageSections::isVisible('templates', 'filters') && !empty($categories)): ?>
        <div class="templates-filters">
            <a href="/downloads" class="<?= $category === '' ? 'active' : '' ?>"><?= e(PageText::get('templates', 'filter_all')) ?></a>
            <?php foreach ($categories as $cat): ?>
            <a href="/downloads?category=<?= urlencode($cat['category']) ?>"
               class="<?= $category === $cat['category'] ? 'active' : '' ?>">
                <?= e($cat['category']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (empty($templates)): ?>
        <div class="templates-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
            </svg>
            <p><?= e(PageText::get('templates', 'empty')) ?></p>
        </div>
        <?php else: ?>
        <div class="templates-grid">
            <?php foreach ($templates as $t):
                $files = $templateFiles[$t['id']] ?? [];
                $fileCount = count($files);
            ?>
            <div class="template-card">
                <div class="template-card__preview">
                    <?php if (!empty($t['preview_thumb'])): ?>
                        <img src="/uploads/<?= e($t['preview_thumb']) ?>" alt="<?= e($t['title']) ?>">
                    <?php else: ?>
                        <div class="template-card__preview-placeholder">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                                <line x1="16" y1="13" x2="8" y2="13"/>
                                <line x1="16" y1="17" x2="8" y2="17"/>
                            </svg>
                            <span><?= $fileCount ?> file<?= $fileCount !== 1 ? 's' : '' ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="template-card__body">
                    <div class="template-card__meta">
                        <?php if ($fileCount > 0): ?>
                        <span class="template-card__badge"><?= $fileCount ?> file<?= $fileCount !== 1 ? 's' : '' ?></span>
                        <?php endif; ?>
                        <?php if (!empty($t['category'])): ?>
                        <span class="template-card__category"><?= e($t['category']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="template-card__title"><?= e($t['title']) ?></div>
                    <?php if (!empty($t['description'])): ?>
                    <div class="template-card__desc"><?= e($t['description']) ?></div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($files)): ?>
                <div class="template-card__files">
                    <?php foreach ($files as $f): ?>
                    <div class="template-file-row">
                        <span class="template-file-row__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                            </svg>
                        </span>
                        <span class="template-file-row__info">
                            <span class="template-file-row__label"><?= e($f['label'] ?: $f['file_name']) ?></span>
                            <?php if (!empty($f['label'])): ?>
                            <span class="template-file-row__name"><?= e($f['file_name']) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="template-file-row__ext"><?= e(strtoupper($f['file_ext'])) ?></span>
                        <a href="/downloads/download?file_id=<?= $f['id'] ?>" class="template-file-row__dl">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                                <polyline points="7 10 12 15 17 10"/>
                                <line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            <?= e(PageText::get('templates', 'btn_download')) ?>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="template-card__footer">
                    <span class="template-card__downloads">
                        <?= number_format($t['download_count']) ?> download<?= $t['download_count'] != 1 ? 's' : '' ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</section>

<?php include __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
