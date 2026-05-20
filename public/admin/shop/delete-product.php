<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();
csrf_verify();

$id = (int)($_GET['id'] ?? 0);
$product = Database::fetchOne("SELECT * FROM products WHERE id = ?", [$id]);
if ($product) {
    // Atomicity: stash all file paths first, delete DB row (cascade clears
    // product_images), then unlink. DB consistency is the priority — a leaked
    // file on disk is easier to clean up than an orphan DB row.
    $paths = [];
    if (!empty($product['image_path'])) $paths[] = $product['image_path'];
    foreach (Database::fetchAll("SELECT image_path FROM product_images WHERE product_id = ?", [$id]) as $img) {
        if (!empty($img['image_path'])) $paths[] = $img['image_path'];
    }

    Database::delete('products', 'id = ?', [$id]);
    foreach (array_unique($paths) as $p) {
        ImageUpload::delete($p);
    }
    flash('success', 'Product deleted.');
} else {
    flash('error', 'Product not found.');
}
redirect(SITE_URL . '/admin/shop/');
