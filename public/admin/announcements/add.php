<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

$isEdit = !empty($_GET['id']);
$announcementId = $isEdit ? (int)$_GET['id'] : null;
$announcement = null;
$linkedEntities = [];
$existingImage = null;

if ($isEdit) {
    $announcement = Database::fetchOne("SELECT * FROM announcements WHERE id = ?", [$announcementId]);
    if (!$announcement) {
        flash('error', 'Announcement not found.');
        redirect(SITE_URL . '/admin/announcements/');
    }
    $linkedEntities = Database::fetchAll(
        "SELECT entity_type, entity_id FROM announcement_links WHERE announcement_id = ? ORDER BY sort_order ASC",
        [$announcementId]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $publishDate = trim($_POST['publish_date'] ?? '');
        
        if (empty($title)) throw new RuntimeException('Title is required.');
        if (empty($publishDate)) throw new RuntimeException('Publish date is required.');
        
        // Validate datetime format
        if (!strtotime($publishDate)) throw new RuntimeException('Invalid publish date format.');
        
        $data = [
            'title'        => $title,
            'description'  => $description,
            'image_alt'    => trim($_POST['image_alt'] ?? '') ?: null,
            'publish_date' => date('Y-m-d H:i:s', strtotime($publishDate)),
            'created_by'   => $_SESSION['admin_id'] ?? (Auth::getUser()['id'] ?? null),
        ];

        // Handle image upload
        $imagePath = null;
        $imageThumb = null;
        
        if (!empty($_FILES['image']['name'])) {
            $file = $_FILES['image'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Image upload failed: ' . $file['error']);
            }
            
            try {
                $upload = ImageUpload::upload($file, 'announcements');
                $imagePath = $upload['path'];
                $imageThumb = $upload['thumb'];
            } catch (Exception $e) {
                throw new RuntimeException('Image upload error: ' . $e->getMessage());
            }
        } else {
            // Try to use existing image if not uploading new one
            if ($isEdit && !empty($announcement['image_path'])) {
                $imagePath = $announcement['image_path'];
                $imageThumb = $announcement['image_thumb'];
            }
        }

        // If no image provided, try to use first linked entity's image
        if (empty($imagePath)) {
            $selectedEntities = [];
            // Get selected event/pottery IDs from form
            $eventIds = array_map('intval', $_POST['event_ids'] ?? []);
            $potteryIds = array_map('intval', $_POST['piece_ids'] ?? []);
            
            // Try to get image from first event
            if (!empty($eventIds)) {
                $event = Database::fetchOne(
                    "SELECT id FROM events WHERE id = ? LIMIT 1",
                    [$eventIds[0]]
                );
                if ($event) {
                    // Events don't have images, skip
                }
            }
            
            // Try to get image from first pottery piece
            if (!empty($potteryIds)) {
                $pottery = Database::fetchOne(
                    "SELECT image_path, image_thumb FROM piece WHERE id = ?",
                    [$potteryIds[0]]
                );
                if ($pottery && !empty($pottery['image_path'])) {
                    $imagePath = $pottery['image_path'];
                    $imageThumb = $pottery['image_thumb'];
                }
            }
        }

        $data['image_path']  = $imagePath;
        $data['image_thumb'] = $imageThumb;

        // Insert or update announcement
        if ($isEdit) {
            Database::update('announcements', $data, 'id = :id', ['id' => $announcementId]);
            $finalId = $announcementId;
        } else {
            $finalId = Database::insert('announcements', $data);
        }

        // Delete old links if edit
        if ($isEdit) {
            Database::query("DELETE FROM announcement_links WHERE announcement_id = ?", [$finalId]);
        }

        // Handle entity links
        $eventIds = array_map('intval', $_POST['event_ids'] ?? []);
        $potteryIds = array_map('intval', $_POST['piece_ids'] ?? []);
        
        $sortOrder = 0;
        foreach ($eventIds as $eventId) {
            if ($eventId > 0) {
                Database::insert('announcement_links', [
                    'announcement_id' => $finalId,
                    'entity_type' => 'event',
                    'entity_id' => $eventId,
                    'sort_order' => $sortOrder++,
                ]);
            }
        }
        
        foreach ($potteryIds as $potteryId) {
            if ($potteryId > 0) {
                Database::insert('announcement_links', [
                    'announcement_id' => $finalId,
                    'entity_type' => 'piece',
                    'entity_id' => $potteryId,
                    'sort_order' => $sortOrder++,
                ]);
            }
        }

        $msg = $isEdit ? 'Announcement updated successfully!' : 'Announcement created successfully!';
        flash('success', $msg);
        redirect(SITE_URL . '/admin/announcements/');
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load all events and pottery for selection
$allEvents = Database::fetchAll(
    "SELECT id, name FROM events ORDER BY featured DESC, start_date DESC"
);
$allPottery = Database::fetchAll(
    "SELECT id, title FROM piece ORDER BY featured DESC, sort_order ASC"
);

// Build linked entity ID arrays for edit mode
$linkedEventIds = [];
$linkedPotteryIds = [];
foreach ($linkedEntities as $link) {
    if ($link['entity_type'] === 'event') {
        $linkedEventIds[] = $link['entity_id'];
    } elseif ($link['entity_type'] === 'piece') {
        $linkedPotteryIds[] = $link['entity_id'];
    }
}

// Derived branding line for the social-post preview — mirrors post.php so the
// preview matches what actually gets posted. Computed here (before render) so
// the form's data-visit-line attribute can carry it to the external JS.
$_siteName = setting('site_name', '');
$_siteHost = defined('SITE_URL') ? parse_url(SITE_URL, PHP_URL_HOST) : '';
if ($_siteName !== '' && $_siteHost) {
    $_visitLine = "Visit {$_siteHost} for more from {$_siteName}!";
} elseif ($_siteHost) {
    $_visitLine = "Visit {$_siteHost} for more!";
} else {
    $_visitLine = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEdit ? 'Edit' : 'Add' ?> Announcement — Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link rel="stylesheet" href="/admin/css/pages/announcements-add.css">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1><?= $isEdit ? 'Edit Announcement' : 'Add Announcement' ?></h1>
            <a href="/admin/announcements/" class="admin-btn">← Back</a>
        </div>
        <?php if (!empty($error)): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="admin-form" id="announcementForm" data-visit-line="<?= e($_visitLine ?? '') ?>">
            <?= csrf_field() ?>
            <div class="form-grid">
                <!-- Title -->
                <div class="form-group form-group--full">
                    <label>Title *</label>
                    <input type="text" name="title" required 
                           value="<?= e($_POST['title'] ?? ($announcement['title'] ?? '')) ?>" 
                           placeholder="e.g. New Pottery Collection Available">
                </div>

                <!-- Description -->
                <div class="form-group form-group--full">
                    <label>Description</label>
                    <textarea name="description" rows="3" placeholder="Describe the announcement..."><?= e($_POST['description'] ?? ($announcement['description'] ?? '')) ?></textarea>
                </div>

                <!-- Image alt text -->
                <div class="form-group form-group--full">
                    <label>Image alt text (for accessibility)</label>
                    <input type="text" name="image_alt" maxlength="500"
                           value="<?= e($_POST['image_alt'] ?? ($announcement['image_alt'] ?? '')) ?>"
                           placeholder="Defaults to the announcement title — override for a richer description of the image.">
                    <small class="u-text-ash">Leave blank to use the title.</small>
                </div>

                <!-- Publish Date -->
                <div class="form-group form-group--half">
                    <label>Publish Date & Time *</label>
                    <input type="datetime-local" name="publish_date" required
                           value="<?= e($_POST['publish_date'] ?? ($announcement ? date('Y-m-d\TH:i', strtotime($announcement['publish_date'])) : date('Y-m-d\T12:00'))) ?>">
                    <small class="u-text-ash">Announcement will be visible after this date</small>
                </div>

                <!-- Image Upload -->
                <div class="form-group form-group--half">
                    <label>Image (Optional)</label>
                    <div class="image-upload-area <?= (!empty($announcement['image_path']) || !empty($_FILES['image']['name'])) ? 'has-image' : '' ?>" id="uploadArea">
                        <input type="file" name="image" id="imageInput" class="image-upload-input" accept="image/jpeg,image/png,image/webp,image/gif">
                        <div id="uploadText">
                            <p class="ann-drop-title">Click or drag image here</p>
                            <p class="ann-drop-hint">JPG, PNG, WebP, GIF up to 10MB</p>
                        </div>
                        <?php if (!empty($announcement['image_thumb'])): ?>
                            <img src="<?= e(UPLOAD_URL . $announcement['image_thumb']) ?>" alt="Current image" class="image-preview" id="previewImage">
                        <?php endif; ?>
                        <div id="newImagePreview"></div>
                    </div>
                    <small class="ann-img-note">If no image provided, the first linked event/pottery image will be used</small>
                </div>

                <!-- Link Entities Section -->
                <div class="form-group form-group--full">
                    <label class="ann-link-label"><strong>Link Events & Pieces</strong></label>
                    
                    <!-- Events -->
                    <div class="ann-link-group">
                        <h4 class="ann-link-heading">Events</h4>
                        <?php if (empty($allEvents)): ?>
                            <p class="ann-empty-note">No events available</p>
                        <?php else: ?>
                            <div class="entity-checklist">
                                <?php foreach ($allEvents as $event): ?>
                                    <div class="entity-item">
                                        <input type="checkbox" id="event_<?= $event['id'] ?>" name="event_ids[]" 
                                               value="<?= $event['id'] ?>"
                                               <?= in_array($event['id'], $linkedEventIds) ? 'checked' : '' ?>>
                                        <label class="entity-label" for="event_<?= $event['id'] ?>">
                                            <?= e($event['name']) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Pottery Pieces -->
                    <div>
                        <h4 class="ann-link-heading">Pottery Pieces</h4>
                        <?php if (empty($allPottery)): ?>
                            <p class="ann-empty-note">No pottery pieces available</p>
                        <?php else: ?>
                            <div class="entity-checklist">
                                <?php foreach ($allPottery as $piece): ?>
                                    <div class="entity-item">
                                        <input type="checkbox" id="pottery_<?= $piece['id'] ?>" name="piece_ids[]" 
                                               value="<?= $piece['id'] ?>"
                                               <?= in_array($piece['id'], $linkedPotteryIds) ? 'checked' : '' ?>>
                                        <label class="entity-label" for="pottery_<?= $piece['id'] ?>">
                                            <?= e($piece['title']) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Social Media Preview -->
                <div class="form-group form-group--full">
                    <div class="social-preview">
                        <h3>📱 Social Media Preview</h3>
                        <div class="social-preview-text" id="socialPreview">
Your announcement will appear as:

[Title will appear here]

[Linked events/pieces will appear here]

[URL will be included]
                        </div>
                    </div>
                </div>
            </div>

            <div class="ann-form-actions">
                <button type="submit" class="admin-btn admin-btn--primary">
                    <?= $isEdit ? 'Update Announcement' : 'Create Announcement' ?>
                </button>
                <a href="/admin/announcements/" class="admin-btn">Cancel</a>
            </div>
        </form>
    </div>
</main>

<script src="/admin/js/announcements-add.js"></script>
</body>
</html>
