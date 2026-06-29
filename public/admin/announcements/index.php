<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

$announcements = Database::fetchAll(
    "SELECT * FROM announcements ORDER BY publish_date DESC, created_at DESC"
);

// Batch the per-announcement linked-entity lookup into a single JOIN'd query
// instead of an N+1 (one query per announcement) × M+1 (one query per link)
// pattern. Keyed by announcement_id → list of "Event: X" / "Piece: Y" strings.
$linksByAnnouncement = [];
if (!empty($announcements)) {
    $ids   = array_column($announcements, 'id');
    $place = implode(',', array_fill(0, count($ids), '?'));
    // Polymorphic FK (entity_type 'event' or 'pottery') is handled with two
    // conditional LEFT JOINs against the candidate tables; exactly one will
    // contribute a non-null label per row.
    $sql = "SELECT al.announcement_id, al.entity_type,
                   e.name  AS event_name,
                   p.title AS pottery_title
              FROM announcement_links al
         LEFT JOIN events   e ON al.entity_type = 'event'   AND al.entity_id = e.id
         LEFT JOIN piece  p ON al.entity_type = 'piece' AND al.entity_id = p.id
             WHERE al.announcement_id IN ($place)
          ORDER BY al.announcement_id, al.sort_order ASC";
    foreach (Database::fetchAll($sql, $ids) as $row) {
        $label = null;
        if ($row['entity_type'] === 'event' && $row['event_name']) {
            $label = 'Event: ' . $row['event_name'];
        } elseif ($row['entity_type'] === 'piece' && $row['pottery_title']) {
            $label = 'Piece: ' . $row['pottery_title'];
        }
        if ($label !== null) {
            $linksByAnnouncement[(int)$row['announcement_id']][] = $label;
        }
    }
}

// Pure-PHP date check, no DB — kept as a closure so it stays scoped to this file.
$getAnnouncementStatus = function (array $announcement): string {
    return $announcement['publish_date'] > date('Y-m-d H:i:s') ? 'Scheduled' : 'Published';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements — Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/admin.css">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Announcements <span class="badge"><?= count($announcements) ?></span></h1>
            <a href="/admin/announcements/add" class="admin-btn admin-btn--primary">+ Add Announcement</a>
        </div>

        <?php if (!empty($message = getFlash('success'))): ?>
        <div class="alert alert--success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if (!empty($message = getFlash('error'))): ?>
        <div class="alert alert--error"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if (empty($announcements)): ?>
        <div class="empty-admin">
            <p>No announcements yet.</p>
            <a href="/admin/announcements/add" class="admin-btn admin-btn--primary">Create your first announcement</a>
        </div>
        <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Publish Date</th>
                        <th>Status</th>
                        <th>Linked Entities</th>
                        <th>Image</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($announcements as $ann): ?>
                    <tr>
                        <td><strong><?= e($ann['title']) ?></strong></td>
                        <td><?= date('M d, Y h:i A', strtotime($ann['publish_date'])) ?></td>
                        <?php $status = $getAnnouncementStatus($ann); ?>
                        <td>
                            <span class="badge <?= $status === 'Published' ? 'badge--active' : 'badge--secondary' ?>">
                                <?= e($status) ?>
                            </span>
                        </td>
                        <td>
                            <?php $links = $linksByAnnouncement[(int)$ann['id']] ?? []; ?>
                            <?php if (empty($links)): ?>
                                <span style="opacity: 0.5;">—</span>
                            <?php else: ?>
                                <?php foreach (array_slice($links, 0, 2) as $link): ?>
                                    <div style="font-size: 0.85rem; line-height: 1.4;"><?= e($link) ?></div>
                                <?php endforeach; ?>
                                <?php if (count($links) > 2): ?>
                                    <div style="font-size: 0.85rem; opacity: 0.7;">+<?= count($links) - 2 ?> more</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $ann['image_path'] ? '📷' : '—' ?>
                        </td>
                        <td class="actions-cell">
                            <a href="/admin/announcements/edit?id=<?= $ann['id'] ?>" class="admin-btn admin-btn--sm">Edit</a>
                            <?php if ($status === 'Published'): ?>
                                <a href="/admin/announcements/post?id=<?= $ann['id'] ?>&csrf=<?= e(csrf_token()) ?>" class="admin-btn admin-btn--sm admin-btn--secondary">Post Social</a>
                            <?php endif; ?>
                            <a href="javascript:void(0)" onclick="confirmDelete(<?= $ann['id'] ?>)" class="admin-btn admin-btn--sm admin-btn--danger">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</main>

<script>
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
function confirmDelete(id) {
    if (confirm('Are you sure you want to delete this announcement?')) {
        window.location.href = '/admin/announcements/delete?id=' + id + '&csrf=' + encodeURIComponent(CSRF_TOKEN);
    }
}
</script>
</body>
</html>
