<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();
csrf_verify();

$id = (int)($_GET['id'] ?? 0);
$piece = Database::fetchOne("SELECT * FROM pottery WHERE id = ?", [$id]);
if ($piece) {
    // Atomicity: collect every image path BEFORE the DB delete (the FK
    // cascade on pottery_images wipes the child rows), then delete the row,
    // THEN unlink the files. If the DB delete fails the files are still on
    // disk to retry; if the unlink fails we just leak a file rather than
    // leave an orphan DB row.
    $paths = [$piece['image_path']];
    if (!empty($piece['image_thumb']) && $piece['image_thumb'] !== $piece['image_path']) {
        $paths[] = $piece['image_thumb'];
    }
    foreach (Database::fetchAll("SELECT image_path, image_thumb FROM pottery_images WHERE pottery_id = ?", [$id]) as $img) {
        if (!empty($img['image_path']))  $paths[] = $img['image_path'];
        if (!empty($img['image_thumb']) && $img['image_thumb'] !== $img['image_path']) {
            $paths[] = $img['image_thumb'];
        }
    }

    Database::delete('pottery', 'id = ?', [$id]);
    foreach (array_unique(array_filter($paths)) as $p) {
        ImageUpload::delete($p);
    }
    flash('success', 'Piece deleted.');
} else {
    flash('error', 'Piece not found.');
}
redirect(SITE_URL . '/admin/pottery/');
