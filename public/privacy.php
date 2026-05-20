<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$policyHtml    = (string)setting('privacy_policy_html', '');
$policyUpdated = trim((string)setting('privacy_updated', ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(PageText::get('titles', 'privacy')) ?> — <?= e(setting('site_name', 'My Pottery')) ?></title>
    <?= PageMeta::renderHead([
        'title'       => PageText::get('titles', 'privacy') . ' — ' . setting('site_name', 'My Pottery'),
        'description' => 'Privacy policy for ' . setting('site_name', 'My Pottery') . '.',
    ]) ?>
    <link rel="stylesheet" href="/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/favicon-512.png">
    <link rel="apple-touch-icon" href="/favicon-512.png">
</head>
<body>
<?php include __DIR__ . '/../templates/nav.php'; ?>

<div class="page-header">
    <div class="container">
        <h1 class="page-header__title">Privacy Policy</h1>
        <p class="page-header__sub"><?= e(setting('site_name', 'My Pottery')) ?></p>
    </div>
</div>

<section class="section">
    <div class="container prose">

        <?php if ($policyUpdated !== ''): ?>
            <p class="prose__meta">Last updated: <?= e($policyUpdated) ?></p>
        <?php endif; ?>

        <?php if (trim($policyHtml) === ''): ?>
            <p><em>No privacy policy has been published yet. Add one from <a href="/admin/settings/">Admin → Site Settings → Privacy Policy</a>.</em></p>
        <?php else: ?>
            <?php /* Admin-trusted HTML: editor is the site owner; rendered as-is. */ ?>
            <?= $policyHtml ?>
        <?php endif; ?>

    </div>
</section>

<?php include __DIR__ . '/../templates/footer.php'; ?>
<script src="/js/main.js"></script>
</body>
</html>
