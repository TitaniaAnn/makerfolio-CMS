<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

$isEdit         = !empty($_GET['id']);
$pieceId        = $isEdit ? (int) $_GET['id'] : 0;
$piece          = null;
$existingImages = [];

if ($isEdit) {
    $piece = Database::fetchOne("SELECT * FROM piece WHERE id = ?", [$pieceId]);
    if (!$piece) {
        flash('error', 'Piece not found.');
        redirect(SITE_URL . '/admin/pieces/');
    }
    $existingImages = Database::fetchAll(
        "SELECT * FROM piece_images WHERE piece_id = ? ORDER BY sort_order ASC, id ASC",
        [$pieceId]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $data = [
            'title'       => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'technique'   => trim($_POST['technique'] ?? ''),
            'dimensions'  => trim($_POST['dimensions'] ?? ''),
            'year'        => (int)($_POST['year'] ?? 0) ?: null,
            'alt_text'    => trim($_POST['alt_text'] ?? '') ?: null,
            'featured'    => isset($_POST['featured']) ? 1 : 0,
            'sort_order'  => (int)($_POST['sort_order'] ?? 0),
        ];

        if (empty($data['title'])) {
            throw new RuntimeException('Title is required.');
        }

        $newUploads = [];
        foreach (MultiFileUpload::parse($_FILES['images'] ?? null) as $file) {
            $newUploads[] = ImageUpload::upload($file, 'pottery');
        }

        if (!$isEdit && empty($newUploads)) {
            throw new RuntimeException('At least one image is required.');
        }

        if ($isEdit) {
            Database::update('piece', $data, 'id = :id', ['id' => $pieceId]);
            $finalId = $pieceId;
        } else {
            $data['image_path'] = '';
            $finalId = Database::insert('piece', $data);
        }

        // Append new uploads after existing images.
        $maxSort = count($existingImages);
        foreach ($newUploads as $i => $upload) {
            $isFirstEverImage = !$isEdit && $i === 0;
            Database::query(
                "INSERT INTO piece_images (piece_id, image_path, image_thumb, sort_order, is_primary)
                 VALUES (?,?,?,?,?)",
                [$finalId, $upload['path'], $upload['thumb'], $maxSort + $i, $isFirstEverImage ? 1 : 0]
            );
        }

        // Caller-selected primary takes precedence.
        $primaryImgId = (int)($_POST['primary_image_id'] ?? 0);
        if ($primaryImgId > 0) {
            Database::query("UPDATE piece_images SET is_primary = 0 WHERE piece_id = ?", [$finalId]);
            Database::query(
                "UPDATE piece_images SET is_primary = 1 WHERE id = ? AND piece_id = ?",
                [$primaryImgId, $finalId]
            );
        } elseif (!$isEdit && !empty($newUploads)) {
            // First newly-uploaded image becomes primary on a fresh piece.
            $first = Database::fetchOne(
                "SELECT * FROM piece_images WHERE piece_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1",
                [$finalId]
            );
            if ($first) {
                Database::query("UPDATE piece_images SET is_primary = 1 WHERE id = ?", [$first['id']]);
            }
        }

        // Sync the pottery row's image_path/thumb to whichever image is now primary.
        $primary = Database::fetchOne(
            "SELECT image_path, image_thumb FROM piece_images
              WHERE piece_id = ?
              ORDER BY is_primary DESC, sort_order ASC, id ASC
              LIMIT 1",
            [$finalId]
        );
        if ($primary) {
            Database::query(
                "UPDATE piece SET image_path = ?, image_thumb = ? WHERE id = ?",
                [$primary['image_path'], $primary['image_thumb'], $finalId]
            );
        }

        flash('success', $isEdit ? 'Piece updated!' : 'Piece added successfully!');
        redirect(SITE_URL . '/admin/pieces/add?id=' . $finalId);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }

    if ($isEdit) {
        $existingImages = Database::fetchAll(
            "SELECT * FROM piece_images WHERE piece_id = ? ORDER BY sort_order ASC, id ASC",
            [$pieceId]
        );
    }
}

$formData = $_POST + ($piece ?? []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEdit ? 'Edit Piece' : 'Add Piece' ?> — Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link rel="stylesheet" href="/admin/css/pages/pieces-add.css">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1><?= $isEdit ? 'Edit: ' . e($piece['title']) : 'Add Pottery Piece' ?></h1>
            <a href="/admin/pieces/" class="admin-btn">← Back</a>
        </div>
        <?php if (!empty($error)): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="admin-form" id="pieceForm" data-is-edit="<?= $isEdit ? 'true' : 'false' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="primary_image_id" id="primaryImageId" value="">

            <div class="form-grid">
                <div class="form-group form-group--full">
                    <label>Title *</label>
                    <input type="text" name="title" required value="<?= e($formData['title'] ?? '') ?>" placeholder="e.g. Speckled Stoneware Mug">
                </div>
                <div class="form-group form-group--full">
                    <label>Description</label>
                    <textarea name="description" rows="4" placeholder="Describe this piece..."><?= e($formData['description'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>Technique</label>
                    <input type="text" name="technique" value="<?= e($formData['technique'] ?? '') ?>" placeholder="e.g. Wheel-thrown">
                </div>
                <div class="form-group">
                    <label>Dimensions</label>
                    <input type="text" name="dimensions" value="<?= e($formData['dimensions'] ?? '') ?>" placeholder="e.g. 10cm H × 8cm W">
                </div>
                <div class="form-group">
                    <label>Year</label>
                    <input type="number" name="year" value="<?= e($formData['year'] ?? date('Y')) ?>" min="1900" max="<?= date('Y') ?>">
                </div>
                <div class="form-group">
                    <label>Sort Order</label>
                    <input type="number" name="sort_order" value="<?= e($formData['sort_order'] ?? '0') ?>">
                    <small>Lower numbers appear first</small>
                </div>
                <div class="form-group form-group--full">
                    <label>Image alt text (for accessibility)</label>
                    <input type="text" name="alt_text" value="<?= e($formData['alt_text'] ?? '') ?>" maxlength="500"
                           placeholder="Defaults to the title — override for a richer image description (e.g. 'Speckled stoneware mug with cobalt rim, photographed from the front').">
                    <small>Leave blank to use the title. Visible to screen readers and used as the social-share image alt.</small>
                </div>

                <div class="form-group form-group--full">
                    <label>
                        Photos <?= $isEdit ? '' : '*' ?>
                        <small style="font-weight:400;color:var(--ash)">
                            <?= $isEdit ? 'Click "Set cover" to change primary image. Dashed border = new uploads.' : 'First image is the cover. Up to 10.' ?>
                        </small>
                    </label>
                    <div class="img-gallery" id="imgGallery">
                        <?php foreach ($existingImages as $img): ?>
                        <div class="img-gallery-item <?= $img['is_primary'] ? 'is-primary' : '' ?>" data-img-id="<?= $img['id'] ?>"
                             data-full-url="/uploads/<?= e($img['image_path']) ?>">
                            <img src="/uploads/<?= e($img['image_thumb'] ?? $img['image_path']) ?>" alt="">
                            <button type="button" class="rotate-img-btn rotate-img-btn--ccw"
                                data-action="rotate" data-img-id="<?= $img['id'] ?>" data-parent-id="<?= $pieceId ?>" data-dir="ccw"
                                title="Rotate left">⟲</button>
                            <button type="button" class="rotate-img-btn rotate-img-btn--cw"
                                data-action="rotate" data-img-id="<?= $img['id'] ?>" data-parent-id="<?= $pieceId ?>" data-dir="cw"
                                title="Rotate right">⟳</button>
                            <button type="button" class="rotate-img-btn crop-img-btn"
                                data-action="crop" data-img-id="<?= $img['id'] ?>" data-parent-id="<?= $pieceId ?>"
                                title="Crop">✂</button>
                            <button type="button" class="delete-img-btn"
                                data-action="delete-image" data-img-id="<?= $img['id'] ?>" data-parent-id="<?= $pieceId ?>"
                                title="Delete image">×</button>
                            <div class="img-labels">
                                <?php if ($img['is_primary']): ?>
                                    <span class="primary-indicator">★ Cover</span>
                                <?php else: ?>
                                    <button type="button" class="set-primary-btn"
                                        data-action="set-primary" data-img-id="<?= $img['id'] ?>">Set cover</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <div id="newPreviews"></div>

                        <div class="img-add-more" data-action="open-picker">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            <?= $isEdit ? 'Add more' : 'Add photos' ?>
                        </div>
                    </div>
                    <input type="file" id="imgPicker" accept="image/*" multiple style="display:none">
                    <div id="fileInputContainer"></div>
                    <small style="color:var(--ash);margin-top:.4rem;display:block">JPG, PNG, WebP — max 10MB each</small>
                </div>

                <div class="form-group form-group--full">
                    <label class="checkbox-label">
                        <input type="checkbox" name="featured" value="1" <?= !empty($formData['featured']) ? 'checked' : '' ?>>
                        <span>Feature on homepage</span>
                    </label>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="admin-btn admin-btn--primary"><?= $isEdit ? 'Save Changes' : 'Add Piece' ?></button>
                <a href="/admin/pieces/" class="admin-btn">Cancel</a>
            </div>
        </form>
    </div>
</main>

<script src="/admin/js/image-cropper.js"></script>
<script src="/admin/js/pieces-add.js"></script>
</body>
</html>
