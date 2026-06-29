<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

function productImageThumbPath(?string $path): ?string {
    if (empty($path)) {
        return null;
    }

    $dir = dirname($path);
    $file = basename($path);

    return ($dir === '.' ? '' : $dir . '/') . 'thumb_' . $file;
}

function syncProductPrimaryImage(int $productId): void {
    $primaryImage = Database::fetchOne(
        "SELECT * FROM product_images
         WHERE product_id = ?
         ORDER BY is_primary DESC, sort_order ASC, id ASC
         LIMIT 1",
        [$productId]
    );

    Database::query("UPDATE product_images SET is_primary = 0 WHERE product_id = ?", [$productId]);

    if ($primaryImage) {
        Database::query("UPDATE product_images SET is_primary = 1 WHERE id = ?", [$primaryImage['id']]);
        Database::query("UPDATE products SET image_path = ? WHERE id = ?", [$primaryImage['image_path'], $productId]);
        return;
    }

    Database::query("UPDATE products SET image_path = NULL WHERE id = ?", [$productId]);
}

function backfillLegacyProductImage(array $product): void {
    if (empty($product['id']) || empty($product['image_path'])) {
        return;
    }

    $existingCount = Database::fetchOne(
        "SELECT COUNT(*) AS cnt FROM product_images WHERE product_id = ?",
        [$product['id']]
    );

    if (($existingCount['cnt'] ?? 0) > 0) {
        return;
    }

    Database::insert('product_images', [
        'product_id' => $product['id'],
        'image_path' => $product['image_path'],
        'image_thumb' => productImageThumbPath($product['image_path']),
        'sort_order' => 0,
        'is_primary' => 1,
    ]);
}

$categories = Database::fetchAll("SELECT * FROM shop_categories ORDER BY type, name");
$isEdit = !empty($_GET['id']);
$productId = $isEdit ? (int)($_GET['id'] ?? 0) : 0;
$product = null;
$existingImages = [];

if ($isEdit) {
    $product = Database::fetchOne("SELECT * FROM products WHERE id = ?", [$productId]);
    if (!$product) {
        flash('error', 'Product not found.');
        redirect(SITE_URL . '/admin/shop/');
    }

    backfillLegacyProductImage($product);
    $existingImages = Database::fetchAll(
        "SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order ASC, id ASC",
        [$productId]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $type = $_POST['type'] ?? ($product['type'] ?? 'pot');
        $data = [
            'category_id' => (int)($_POST['category_id'] ?? 0) ?: null,
            'name'         => trim($_POST['name'] ?? ''),
            'description'  => trim($_POST['description'] ?? ''),
            'price'        => is_numeric($_POST['price'] ?? '') ? (float)$_POST['price'] : null,
            'type'         => $type,
            'status'       => $_POST['status'] ?? 'available',
            'dimensions'   => trim($_POST['dimensions'] ?? ''),
            'technique'    => trim($_POST['technique'] ?? ''),
            'alt_text'     => trim($_POST['alt_text'] ?? '') ?: null,
            'quantity'     => (int)($_POST['quantity'] ?? 1),
            'pod_provider' => $type === 'merch' ? ($_POST['pod_provider'] ?? null) : null,
            'pod_product_url'=> trim($_POST['pod_product_url'] ?? '') ?: null,
            'pod_product_id' => trim($_POST['pod_product_id'] ?? '') ?: null,
            'external_url'  => trim($_POST['external_url'] ?? '') ?: null,
            'sort_order'   => (int)($_POST['sort_order'] ?? 0),
        ];

        $data['is_visible'] = isset($_POST['is_visible']) ? 1 : 0;

        if (empty($data['name'])) throw new RuntimeException('Name is required.');

        $newUploads = [];
        foreach (MultiFileUpload::parse($_FILES['images'] ?? null) as $file) {
            $newUploads[] = ImageUpload::upload($file, 'products');
        }

        if ($isEdit) {
            Database::update('products', $data, 'id = :id', ['id' => $productId]);
            $finalId = $productId;
        } else {
            $finalId = Database::insert('products', $data);
        }

        if (!empty($newUploads)) {
            $sortSeedRow = Database::fetchOne(
                "SELECT COALESCE(MAX(sort_order), -1) AS max_sort FROM product_images WHERE product_id = ?",
                [$finalId]
            );
            $sortOrder = (int)($sortSeedRow['max_sort'] ?? -1) + 1;

            $imageCountRow = Database::fetchOne(
                "SELECT COUNT(*) AS cnt FROM product_images WHERE product_id = ?",
                [$finalId]
            );
            $isFirstImage = ((int)($imageCountRow['cnt'] ?? 0) === 0);

            foreach ($newUploads as $upload) {
                Database::insert('product_images', [
                    'product_id' => $finalId,
                    'image_path' => $upload['path'],
                    'image_thumb' => $upload['thumb'],
                    'sort_order' => $sortOrder++,
                    'is_primary' => $isFirstImage ? 1 : 0,
                ]);
                $isFirstImage = false;
            }
        }

        $primaryImageId = (int)($_POST['primary_image_id'] ?? 0);
        if ($primaryImageId > 0) {
            Database::query("UPDATE product_images SET is_primary = 0 WHERE product_id = ?", [$finalId]);
            Database::query(
                "UPDATE product_images SET is_primary = 1 WHERE id = ? AND product_id = ?",
                [$primaryImageId, $finalId]
            );
        }

        syncProductPrimaryImage($finalId);

        if ($isEdit) {
            flash('success', 'Product updated!');
            redirect(SITE_URL . '/admin/shop/edit-product?id=' . $productId);
        }

        flash('success', 'Product added!');
        redirect(SITE_URL . '/admin/shop/edit-product?id=' . $finalId);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$formData = $_POST + ($product ?? []);
$selectedType = $formData['type'] ?? 'pot';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEdit ? 'Edit Product' : 'Add Product' ?> — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/pages/shop-add-product.css">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1><?= $isEdit ? 'Edit Shop Product' : 'Add Shop Product' ?></h1>
            <a href="/admin/shop/" class="admin-btn">← Back</a>
        </div>

        <?php if (!empty($error)): ?>
        <div class="alert alert--error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="admin-form" id="productForm">
            <?= csrf_field() ?>
            <input type="hidden" name="primary_image_id" id="primaryImageId" value="">

            <!-- Product Type Tabs -->
            <div class="type-tabs">
                <label class="type-tab <?= $selectedType === 'pot' ? 'active' : '' ?>">
                    <input type="radio" name="type" value="pot" <?= $selectedType === 'pot' ? 'checked' : '' ?>>
                    🏺 Original Pot
                </label>
                <label class="type-tab <?= $selectedType === 'merch' ? 'active' : '' ?>">
                    <input type="radio" name="type" value="merch" <?= $selectedType === 'merch' ? 'checked' : '' ?>>
                    👕 Merch (Print-on-Demand)
                </label>
            </div>

            <div class="form-grid">
                <div class="form-group form-group--full">
                    <label>Product Name *</label>
                    <input type="text" name="name" required value="<?= e($formData['name'] ?? '') ?>"
                           placeholder="e.g. Hand-thrown Celadon Bowl">
                </div>
                <div class="form-group form-group--full">
                    <label>Description</label>
                    <textarea name="description" rows="3"><?= e($formData['description'] ?? '') ?></textarea>
                </div>
                <div class="form-group form-group--full">
                    <label>Image alt text (for accessibility)</label>
                    <input type="text" name="alt_text" value="<?= e($formData['alt_text'] ?? '') ?>" maxlength="500"
                           placeholder="Defaults to the product name. Override for a richer description.">
                    <small>Leave blank to use the name. Visible to screen readers.</small>
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id">
                        <option value="">— None —</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= (string)($formData['category_id'] ?? '') === (string)$cat['id'] ? 'selected' : '' ?>>
                            <?= e($cat['name']) ?> (<?= e($cat['type']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Price ($)</label>
                    <input type="number" name="price" step="0.01" min="0"
                           value="<?= e($formData['price'] ?? '') ?>" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="available" <?= ($formData['status'] ?? 'available') === 'available' ? 'selected' : '' ?>>Available</option>
                        <option value="sold" <?= ($formData['status'] ?? '') === 'sold' ? 'selected' : '' ?>>Sold</option>
                        <option value="coming_soon" <?= ($formData['status'] ?? '') === 'coming_soon' ? 'selected' : '' ?>>Coming Soon</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Storefront Visibility</label>
                    <label style="display: inline-flex; align-items: center; gap: .5rem; margin-top: .25rem;">
                        <input type="checkbox" name="is_visible" value="1"
                               <?= (string)($formData['is_visible'] ?? '1') === '1' ? 'checked' : '' ?>>
                        Show this product in the public shop
                    </label>
                </div>

                <!-- Pot-only fields -->
                <div class="form-group pot-only">
                    <label>Dimensions</label>
                    <input type="text" name="dimensions" value="<?= e($formData['dimensions'] ?? '') ?>" placeholder="e.g. 12cm H">
                </div>
                <div class="form-group pot-only">
                    <label>Technique</label>
                    <input type="text" name="technique" value="<?= e($formData['technique'] ?? '') ?>">
                </div>
                <div class="form-group pot-only">
                    <label>Quantity Available</label>
                    <input type="number" name="quantity" value="<?= e($formData['quantity'] ?? '1') ?>" min="0">
                </div>

                <!-- Merch-only fields -->
                <div class="form-group merch-only">
                    <label>Print-on-Demand Provider</label>
                    <select name="pod_provider">
                        <option value="">— Select —</option>
                        <option value="printful" <?= ($formData['pod_provider'] ?? '') === 'printful' ? 'selected' : '' ?>>Printful ✓ (your provider)</option>
                        <option value="printify" <?= ($formData['pod_provider'] ?? '') === 'printify' ? 'selected' : '' ?>>Printify</option>
                        <option value="other" <?= ($formData['pod_provider'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                <div class="form-group merch-only form-group--full">
                    <div class="tip-box">
                        <strong>Printful tip:</strong> In your Printful dashboard, go to <em>Stores → My Products</em>, open a product, and copy the product's URL or your store's checkout URL. Paste it below so customers are sent directly to that product on Printful/your storefront.
                    </div>
                </div>
                <div class="form-group merch-only form-group--full">
                    <label>Printful Product URL *</label>
                    <input type="url" name="pod_product_url" value="<?= e($formData['pod_product_url'] ?? '') ?>"
                           placeholder="https://your-store.printful.me/products/...">
                </div>
                <div class="form-group merch-only">
                    <label>Product ID (optional)</label>
                    <input type="text" name="pod_product_id" value="<?= e($formData['pod_product_id'] ?? '') ?>">
                </div>

                <!-- Both -->
                <div class="form-group form-group--full">
                    <label>External Link (override buy button URL)</label>
                    <input type="url" name="external_url" value="<?= e($formData['external_url'] ?? '') ?>"
                           placeholder="Alternative URL if not using POD provider link">
                </div>

                <div class="form-group form-group--full">
                    <label>Product Images</label>
                    <input type="file" name="images[]" id="imageInput" accept="image/*" multiple>
                    <small class="upload-note">Upload one or more images. The first image becomes the cover until you change it.</small>

                    <?php if (!empty($existingImages)): ?>
                    <div class="img-gallery" id="existingGallery">
                        <?php foreach ($existingImages as $image): ?>
                        <div class="img-gallery-item <?= $image['is_primary'] ? 'is-primary' : '' ?>" data-img-id="<?= $image['id'] ?>"
                             data-full-url="/uploads/<?= e($image['image_path']) ?>">
                            <img src="/uploads/<?= e($image['image_thumb'] ?? $image['image_path']) ?>" alt="">
                            <button type="button" class="rotate-img-btn rotate-img-btn--ccw" data-action="rotate" data-img-id="<?= $image['id'] ?>" data-parent-id="<?= $productId ?>" data-dir="ccw" title="Rotate left">⟲</button>
                            <button type="button" class="rotate-img-btn rotate-img-btn--cw" data-action="rotate" data-img-id="<?= $image['id'] ?>" data-parent-id="<?= $productId ?>" data-dir="cw" title="Rotate right">⟳</button>
                            <button type="button" class="rotate-img-btn crop-img-btn" data-action="crop" data-img-id="<?= $image['id'] ?>" data-parent-id="<?= $productId ?>" title="Crop">✂</button>
                            <button type="button" class="delete-img-btn" data-action="delete-image" data-img-id="<?= $image['id'] ?>" data-parent-id="<?= $productId ?>" title="Delete image">×</button>
                            <div class="img-labels">
                                <?php if ($image['is_primary']): ?>
                                <span class="primary-indicator">★ Cover</span>
                                <?php else: ?>
                                <button type="button" class="set-primary-btn" data-action="set-primary" data-img-id="<?= $image['id'] ?>">Set cover</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php elseif ($isEdit): ?>
                    <p class="upload-note">No product images yet.</p>
                    <?php endif; ?>

                    <div class="img-gallery" id="newPreviews"></div>
                    <?php if (!$isEdit): ?>
                    <p class="upload-note">After saving, you can set the cover image or remove individual images.</p>
                    <?php else: ?>
                    <p class="upload-note">Cover selection and individual delete actions are available for saved images.</p>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Sort Order</label>
                    <input type="number" name="sort_order" value="<?= e($formData['sort_order'] ?? '0') ?>">
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="admin-btn admin-btn--primary"><?= $isEdit ? 'Save Changes' : 'Add Product' ?></button>
                <a href="/admin/shop/" class="admin-btn">Cancel</a>
            </div>
        </form>
    </div>
</main>
<!-- admin.js is now included via topbar.php on every admin page; don't reload it. -->
<script src="/admin/js/image-cropper.js"></script>
<script src="/admin/js/shop-add-product.js"></script>
</body>
</html>
