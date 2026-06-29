<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

$isEdit       = !empty($_GET['id']);
$eventId      = $isEdit ? (int) $_GET['id'] : 0;
$event        = null;
$assignedIds  = [];

if ($isEdit) {
    $event = Database::fetchOne("SELECT * FROM events WHERE id = ?", [$eventId]);
    if (!$event) {
        flash('error', 'Event not found.');
        redirect(SITE_URL . '/admin/events/');
    }
    $assignedRows = Database::fetchAll(
        "SELECT piece_id FROM event_piece WHERE event_id = ?", [$eventId]
    );
    $assignedIds = array_column($assignedRows, 'piece_id');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        // Event type is locked after creation: read from the existing record on edit.
        $eventType = $isEdit ? $event['event_type'] : trim($_POST['event_type'] ?? '');
        $name      = trim($_POST['name'] ?? '');

        if (empty($eventType)) throw new RuntimeException('Event type is required.');
        if (empty($name))      throw new RuntimeException('Event name is required.');

        $data = [
            'event_type'   => $eventType,
            'name'         => $name,
            'description'  => trim($_POST['description'] ?? ''),
            'location'     => trim($_POST['location'] ?? ''),
            'url'          => trim($_POST['url'] ?? ''),
            'start_date'   => !empty($_POST['start_date'])   ? $_POST['start_date']   : null,
            'end_date'     => !empty($_POST['end_date'])     ? $_POST['end_date']     : null,
            'publish_date' => !empty($_POST['publish_date']) ? $_POST['publish_date'] : ($event['publish_date'] ?? date('Y-m-d')),
            'featured'     => isset($_POST['featured']) ? 1 : 0,
            'sort_order'   => (int)($_POST['sort_order'] ?? 0),
        ];

        if (in_array($eventType, ['pottery_sale', 'storefront_sale'], true)) {
            $data['daily_open_times'] = trim($_POST['daily_open_times'] ?? '');
        } else {
            $data['daily_open_times'] = null;
        }

        if ($eventType === 'class') {
            $classType = trim($_POST['class_type'] ?? '');
            if (empty($classType)) throw new RuntimeException('Class type is required for class events.');
            $data['class_type']       = $classType;
            $data['class_age_range']  = trim($_POST['class_age_range'] ?? '');
            $data['class_date_start'] = !empty($_POST['class_date_start']) ? $_POST['class_date_start'] : null;
            $data['class_date_end']   = !empty($_POST['class_date_end'])   ? $_POST['class_date_end']   : null;
            $data['class_time_start'] = !empty($_POST['class_time_start']) ? $_POST['class_time_start'] : null;
            $data['class_time_end']   = !empty($_POST['class_time_end'])   ? $_POST['class_time_end']   : null;
        } else {
            $data['class_type']       = null;
            $data['class_age_range']  = null;
            $data['class_date_start'] = null;
            $data['class_date_end']   = null;
            $data['class_time_start'] = null;
            $data['class_time_end']   = null;
        }

        if ($isEdit) {
            Database::update('events', $data, 'id = :id', ['id' => $eventId]);
            $finalId = $eventId;
            Database::delete('event_piece', 'event_id = ?', [$finalId]);
        } else {
            $finalId = Database::insert('events', $data);
        }

        $selectedPieces = $_POST['poetry_ids'] ?? [];
        foreach ($selectedPieces as $pieceId) {
            $pieceId = (int) $pieceId;
            if ($pieceId > 0) {
                Database::insert('event_piece', [
                    'event_id'   => $finalId,
                    'piece_id' => $pieceId,
                ]);
            }
        }

        flash('success', $isEdit ? 'Event updated!' : 'Event created successfully!');
        redirect(SITE_URL . '/admin/events/add?id=' . $finalId);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$allPieces = Database::fetchAll(
    "SELECT id, title, image_thumb, image_path FROM piece ORDER BY featured DESC, sort_order ASC"
);

$formData = $_POST + ($event ?? []);
$selectedPostedIds = $_POST['poetry_ids'] ?? null;
$activeAssignedIds = $selectedPostedIds !== null ? array_map('intval', $selectedPostedIds) : $assignedIds;

// Event-type labels are sourced from EventTypes::label() (admin-editable via
// /admin/settings/index.php). No local copy here — see [includes/EventTypes.php].
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEdit ? 'Edit Event' : 'Add Event' ?> — Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link rel="stylesheet" href="/admin/css/pages/events-add.css">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1><?= $isEdit ? 'Edit: ' . e($event['name']) : 'Add Event' ?></h1>
            <a href="/admin/events/" class="admin-btn">← Back</a>
        </div>
        <?php if (!empty($error)): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>

        <form method="POST" class="admin-form" id="eventForm" data-is-edit="<?= $isEdit ? 'true' : 'false' ?>">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="form-group form-group--full">
                    <?php if ($isEdit): ?>
                        <label>Event Type</label>
                        <div style="padding:.75rem;background:var(--cream);border-radius:4px;border:1px solid var(--cream-dk);">
                            <?= e(EventTypes::label($event['event_type'])) ?>
                        </div>
                        <small style="color:var(--ash);display:block;margin-top:.25rem;">(Cannot change event type after creation)</small>
                    <?php else: ?>
                        <label>Event Type *</label>
                        <select name="event_type" id="eventType" required>
                            <option value="">Select type...</option>
                            <option value="pottery_show"    <?= ($formData['event_type'] ?? '') === 'pottery_show' ? 'selected' : '' ?>>Show</option>
                            <option value="pottery_sale"    <?= ($formData['event_type'] ?? '') === 'pottery_sale' ? 'selected' : '' ?>>Sale</option>
                            <option value="storefront_sale" <?= ($formData['event_type'] ?? '') === 'storefront_sale' ? 'selected' : '' ?>>Storefront Sale</option>
                            <option value="class"           <?= ($formData['event_type'] ?? '') === 'class' ? 'selected' : '' ?>>Class</option>
                        </select>
                    <?php endif; ?>
                </div>

                <div class="form-group form-group--full">
                    <label>Event Name *</label>
                    <input type="text" name="name" required value="<?= e($formData['name'] ?? '') ?>" placeholder="e.g. Winter Pottery Showcase">
                </div>

                <div class="form-group form-group--full">
                    <label>Description</label>
                    <textarea name="description" rows="3" placeholder="Describe the event..."><?= e($formData['description'] ?? '') ?></textarea>
                </div>

                <div class="form-group form-group--half">
                    <label>Location</label>
                    <input type="text" name="location" value="<?= e($formData['location'] ?? '') ?>" placeholder="e.g. Main Studio">
                </div>

                <div class="form-group form-group--half">
                    <label>Event Website URL</label>
                    <input type="url" name="url" value="<?= e($formData['url'] ?? '') ?>" placeholder="https://...">
                </div>

                <div class="form-group form-group--half">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?= e($formData['start_date'] ?? '') ?>">
                </div>

                <div class="form-group form-group--half">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?= e($formData['end_date'] ?? '') ?>">
                </div>

                <div class="form-group form-group--full">
                    <label>Publish Date <small style="font-weight:400;color:var(--ash)">Event and piece assignments won't be visible until this date</small></label>
                    <input type="date" name="publish_date" value="<?= e($formData['publish_date'] ?? date('Y-m-d')) ?>">
                </div>

                <?php $eventType = $formData['event_type'] ?? ''; ?>
                <div id="salesFields" class="type-specific form-group form-group--full <?= in_array($eventType, ['pottery_sale', 'storefront_sale'], true) ? 'active' : '' ?>">
                    <label>Daily Open Times</label>
                    <textarea name="daily_open_times" rows="2" placeholder="e.g. 10am-5pm Daily"><?= e($formData['daily_open_times'] ?? '') ?></textarea>
                </div>

                <div id="classFields" class="type-specific form-group form-group--full <?= $eventType === 'class' ? 'active' : '' ?>">
                    <div class="form-group form-group--half">
                        <label>Class Type *</label>
                        <select name="class_type">
                            <option value="">Select...</option>
                            <option value="handbuilding"  <?= ($formData['class_type'] ?? '') === 'handbuilding'  ? 'selected' : '' ?>>Handbuilding</option>
                            <option value="wheelthrowing" <?= ($formData['class_type'] ?? '') === 'wheelthrowing' ? 'selected' : '' ?>>Wheel Throwing</option>
                            <option value="month_long"    <?= ($formData['class_type'] ?? '') === 'month_long'    ? 'selected' : '' ?>>Month Long</option>
                            <option value="workshop"      <?= ($formData['class_type'] ?? '') === 'workshop'      ? 'selected' : '' ?>>Workshop</option>
                        </select>
                    </div>
                    <div class="form-group form-group--half">
                        <label>Age Range</label>
                        <input type="text" name="class_age_range" value="<?= e($formData['class_age_range'] ?? '') ?>" placeholder="e.g. 12-18">
                    </div>
                    <div class="form-group form-group--half">
                        <label>Class Start Date</label>
                        <input type="date" name="class_date_start" value="<?= e($formData['class_date_start'] ?? '') ?>">
                    </div>
                    <div class="form-group form-group--half">
                        <label>Class End Date</label>
                        <input type="date" name="class_date_end" value="<?= e($formData['class_date_end'] ?? '') ?>">
                    </div>
                    <div class="form-group form-group--half">
                        <label>Start Time</label>
                        <input type="time" name="class_time_start" value="<?= e($formData['class_time_start'] ?? '') ?>">
                    </div>
                    <div class="form-group form-group--half">
                        <label>End Time</label>
                        <input type="time" name="class_time_end" value="<?= e($formData['class_time_end'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group form-group--half">
                    <label>Sort Order</label>
                    <input type="number" name="sort_order" value="<?= e($formData['sort_order'] ?? '0') ?>">
                    <small>Lower numbers appear first</small>
                </div>

                <div class="form-group form-group--half">
                    <label class="checkbox-label">
                        <input type="checkbox" name="featured" value="1" <?= !empty($formData['featured']) ? 'checked' : '' ?>>
                        <span>Feature on homepage</span>
                    </label>
                </div>

                <div class="form-group form-group--full">
                    <label>Assign Pottery Pieces</label>
                    <small style="color:var(--ash);display:block;margin-bottom:.5rem;">Select which pieces are featured in this event</small>
                    <?php if (empty($allPieces)): ?>
                        <p style="color:var(--ash)"><em>No pottery pieces available yet. <a href="/admin/pieces/add">Add pieces first</a>.</em></p>
                    <?php else: ?>
                        <div class="piece-checklist">
                            <?php foreach ($allPieces as $piece): ?>
                            <label class="piece-item">
                                <input type="checkbox" name="poetry_ids[]" value="<?= $piece['id'] ?>" <?= in_array($piece['id'], $activeAssignedIds, false) ? 'checked' : '' ?>>
                                <div class="piece-item__content">
                                    <img src="/uploads/<?= e($piece['image_thumb'] ?? $piece['image_path']) ?>" alt="<?= e($piece['title']) ?>" class="piece-item__img">
                                    <div class="piece-item__title"><?= e($piece['title']) ?></div>
                                    <div class="piece-item__check" style="display:none;">✓</div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="admin-btn admin-btn--primary"><?= $isEdit ? 'Save Changes' : 'Create Event' ?></button>
                <a href="/admin/events/" class="admin-btn">Cancel</a>
            </div>
        </form>
    </div>
</main>

<script src="/admin/js/events-add.js"></script>
</body>
</html>
