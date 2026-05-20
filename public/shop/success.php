<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$sessionId = $_GET['session_id'] ?? '';
$order = null;

if ($sessionId) {
    $order = Database::fetchOne(
        "SELECT * FROM orders WHERE stripe_session_id = ?",
        [$sessionId]
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(PageText::get('titles', 'order_done')) ?> — <?= e(setting('site_name')) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Crimson+Pro:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .order-confirm {
            min-height: 80vh;
            display: flex; align-items: center; justify-content: center;
            padding: 8rem 2rem 4rem;
        }
        .order-confirm__card {
            max-width: 560px; width: 100%;
            text-align: center;
            background: var(--parchment);
            border: 2px solid var(--wheat);
            box-shadow: 4px 4px 0 var(--wheat);
            padding: 3.5rem 2.5rem;
        }
        .order-confirm__icon {
            font-size: 3.5rem; margin-bottom: 1.25rem;
            animation: popIn .5s cubic-bezier(.175,.885,.32,1.275) both;
        }
        @keyframes popIn {
            from { transform: scale(0); opacity: 0; }
            to   { transform: scale(1); opacity: 1; }
        }
        .order-confirm__title {
            font-family: var(--font-display);
            font-size: 2rem; margin-bottom: .75rem;
            color: var(--bark);
        }
        .order-confirm__script {
            font-family: var(--font-script);
            font-size: 1.3rem; color: var(--ember);
            display: block; margin-bottom: 1.5rem;
        }
        .order-confirm__detail {
            background: var(--parchment-dk);
            border: 1px solid var(--wheat);
            padding: 1.25rem; margin: 1.5rem 0;
            text-align: left;
        }
        .order-confirm__detail p { font-size: .95rem; color: var(--bark-lt); margin-bottom: .4rem; }
        .order-confirm__detail strong { color: var(--bark); }
        .order-confirm__actions { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; margin-top: 2rem; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../../templates/nav.php'; ?>

<div class="order-confirm">
    <div class="order-confirm__card">
        <div class="order-confirm__icon">🏺</div>
        <h1 class="order-confirm__title"><?= e(PageText::get('order', 'success_title')) ?></h1>
        <span class="order-confirm__script"><?= e(PageText::get('order', 'success_script')) ?></span>

        <p><?= e(PageText::get('order', 'success_body')) ?></p>

        <?php if ($order): ?>
        <div class="order-confirm__detail">
            <p><strong><?= e($order['product_name']) ?></strong></p>
            <?php if ($order['customer_name']): ?>
            <p><?= e(PageText::get('order', 'success_for')) ?> <strong><?= e($order['customer_name']) ?></strong></p>
            <?php endif; ?>
            <?php if ($order['customer_email']): ?>
            <p><?= e(PageText::get('order', 'success_email_to')) ?> <strong><?= e($order['customer_email']) ?></strong></p>
            <?php endif; ?>
            <?php if ($order['shipping_city']): ?>
            <p><?= e(PageText::get('order', 'success_ship_to')) ?> <strong><?= e($order['shipping_city']) ?>, <?= e($order['shipping_country']) ?></strong></p>
            <?php endif; ?>
        </div>
        <p style="font-size:.88rem; color:var(--ash); font-style:italic;"><?= e(PageText::get('order', 'success_followup')) ?></p>
        <?php endif; ?>

        <div class="order-confirm__actions">
            <a href="/shop" class="btn btn--outline"><?= e(PageText::get('order', 'btn_back_shop')) ?></a>
            <a href="/" class="btn btn--primary"><?= e(PageText::get('order', 'btn_return_home')) ?></a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
<script src="/js/main.js"></script>
</body>
</html>
