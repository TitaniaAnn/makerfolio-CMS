<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();
csrf_verify();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Invalid announcement ID.');
    redirect(SITE_URL . '/admin/announcements/');
}

$announcement = Database::fetchOne("SELECT * FROM announcements WHERE id = ?", [$id]);
if (!$announcement) {
    flash('error', 'Announcement not found.');
    redirect(SITE_URL . '/admin/announcements/');
}

try {
    // Atomicity: stash image paths first, delete the DB row, THEN unlink.
    // Previously the file unlinks ran first — if a unlink failed but the
    // catch swallowed it, the DB delete still ran and we'd be left with a
    // valid-looking row pointing at no file. DB-first keeps the row +
    // file in sync: if DB succeeds and unlink fails, we leak a file but
    // the system is still internally consistent.
    $paths = [];
    if (!empty($announcement['image_path'])) {
        $paths[] = $announcement['image_path'];
    }
    if (!empty($announcement['image_thumb']) && $announcement['image_thumb'] !== $announcement['image_path']) {
        $paths[] = $announcement['image_thumb'];
    }

    // Cascade delete handles announcement_links + announcement_social_posts.
    Database::query("DELETE FROM announcements WHERE id = ?", [$id]);
    foreach (array_unique($paths) as $p) {
        ImageUpload::delete($p);
    }

    flash('success', 'Announcement deleted successfully.');
} catch (Exception $e) {
    error_log('Announcement delete failed (id=' . $id . '): ' . $e->getMessage());
    flash('error', 'Failed to delete announcement — see server log for details.');
}

redirect(SITE_URL . '/admin/announcements/');
