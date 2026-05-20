<?php
// public/shop/checkout.php
// POST handler: creates a Stripe Checkout session and redirects the user

require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(SITE_URL . '/shop');
}

if (!STRIPE_ENABLED) {
    flash('error', PageText::get('checkout', 'disabled'));
    redirect(SITE_URL . '/shop');
}

csrf_verify();

$productId = (int)($_POST['product_id'] ?? 0);
$quantity  = max(1, (int)($_POST['quantity'] ?? 1));

$product = Database::fetchOne(
    "SELECT * FROM products WHERE id = ? AND type = 'pot' AND status = 'available' AND is_visible = 1",
    [$productId]
);

if (!$product) {
    flash('error', PageText::get('checkout', 'item_unavailable'));
    redirect(SITE_URL . '/shop');
}

if ($product['quantity'] < $quantity) {
    flash('error', str_replace('{quantity}', (string)$product['quantity'], PageText::get('checkout', 'not_enough_stock')));
    redirect(SITE_URL . '/shop');
}

if (!$product['price'] || $product['price'] <= 0) {
    flash('error', PageText::get('checkout', 'no_price'));
    redirect(SITE_URL . '/shop');
}

try {
    $checkoutUrl = StripeHelper::createCheckoutSession($product, $quantity);
    header('Location: ' . $checkoutUrl);
    exit;
} catch (RuntimeException $e) {
    // SDK not installed yet — friendly message; for other RuntimeExceptions surface the message.
    if (str_contains($e->getMessage(), 'SDK not found')) {
        flash('error', PageText::get('checkout', 'sdk_missing'));
    } else {
        flash('error', 'Checkout error: ' . $e->getMessage());
    }
    redirect(SITE_URL . '/shop');
} catch (Exception $e) {
    error_log('Stripe checkout error: ' . $e->getMessage());
    flash('error', PageText::get('checkout', 'generic_error'));
    redirect(SITE_URL . '/shop');
}
