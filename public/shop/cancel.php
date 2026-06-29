<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
$productId = (int)($_GET['product_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(PageText::get('titles', 'order_cancel')) ?> — <?= e(setting('site_name')) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Crimson+Pro:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/pages/shop-cancel.css">
</head>
<body>
<?php include __DIR__ . '/../../templates/nav.php'; ?>

<div class="cancel-page">
    <div class="cancel-card">
        <div class="cancel-card__icon">👐</div>
        <h1 class="cancel-card__title"><?= e(PageText::get('order', 'cancel_title')) ?></h1>
        <span class="cancel-card__sub"><?= e(PageText::get('order', 'cancel_sub')) ?></span>
        <p><?= e(PageText::get('order', 'cancel_body')) ?></p>
        <div class="cancel-card__actions">
            <?php if ($productId): ?>
            <a href="/shop" class="btn btn--primary"><?= e(PageText::get('order', 'btn_back_shop')) ?></a>
            <?php endif; ?>
            <?php if (setting('contact_email')): ?>
            <a href="mailto:<?= e(setting('contact_email')) ?>" class="btn btn--outline"><?= e(PageText::get('order', 'cancel_ask')) ?></a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
<script src="/js/main.js"></script>
</body>
</html>
