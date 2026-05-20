<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

// Admin list orders strictly by sort_order so drag-to-reorder is predictable;
// featured stays as a badge here. Public events page does its own ordering.
$query  = ListQuery::fromRequest($_GET);
$q      = $query['q'];
$search = ListQuery::buildSearchClause($q, ['name', 'description', 'location']);

$total = (int)(Database::fetchOne(
    "SELECT COUNT(*) AS c FROM events {$search['sql']}",
    $search['params']
)['c'] ?? 0);
$pg     = ListQuery::pagination($total, $query['page'], $query['perPage']);
$offset = ($pg['page'] - 1) * $pg['perPage'];

$events = Database::fetchAll(
    "SELECT * FROM events {$search['sql']} ORDER BY sort_order ASC, id ASC LIMIT {$pg['perPage']} OFFSET {$offset}",
    $search['params']
);

$canReorder = ($q === '' && $pg['totalPages'] === 1);
$listLabel  = 'events';

// Batch the per-event piece counts into a single query instead of an N+1
// loop. Keyed by event_id; lookup with $pieceCounts[$id] ?? 0.
$pieceCounts = [];
if (!empty($events)) {
    $ids   = array_column($events, 'id');
    $place = implode(',', array_fill(0, count($ids), '?'));
    foreach (Database::fetchAll(
        "SELECT event_id, COUNT(*) AS cnt FROM event_pottery WHERE event_id IN ($place) GROUP BY event_id",
        $ids
    ) as $row) {
        $pieceCounts[(int)$row['event_id']] = (int)$row['cnt'];
    }
}

function getEventStatus($event) {
    $today = date('Y-m-d');
    if (!$event['publish_date']) return 'Unpublished';
    if ($event['publish_date'] > $today) return 'Scheduled';
    if ($event['start_date'] && $event['start_date'] > $today) return 'Upcoming';
    if ($event['end_date'] && $event['end_date'] < $today) return 'Past';
    return 'Active';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Events — Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/admin.css">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Events <span class="badge"><?= (int)$total ?></span></h1>
            <a href="/admin/events/add" class="admin-btn admin-btn--primary">+ Add Event</a>
        </div>

        <?php include __DIR__ . '/../partials/list-toolbar.php'; ?>

        <?php if (empty($events) && $q === ''): ?>
        <div class="empty-admin">
            <p>No events yet.</p>
            <a href="/admin/events/add" class="admin-btn admin-btn--primary">Create your first event</a>
        </div>
        <?php elseif (empty($events)): ?>
        <div class="empty-admin">
            <p>No events match "<?= e($q) ?>".</p>
            <a href="?" class="admin-btn">Clear search</a>
        </div>
        <?php else: ?>
        <?php if ($canReorder): ?>
            <p class="muted" style="margin-bottom:.75rem;">Drag the <span class="reorder-handle" style="display:inline; cursor:default;">⋮⋮</span> handle to reorder events. Order saves automatically.</p>
        <?php endif; ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <?php if ($canReorder): ?><th style="width:32px;"></th><?php endif; ?>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Status</th>
                        <th>Pieces</th>
                        <th>Featured</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody<?= $canReorder ? ' data-reorder-kind="events"' : '' ?>>
                    <?php foreach ($events as $event): ?>
                    <tr<?= $canReorder ? ' data-id="' . (int)$event['id'] . '"' : '' ?>>
                        <?php if ($canReorder): ?><td><span class="reorder-handle" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</span></td><?php endif; ?>
                        <td><strong><?= e($event['name']) ?></strong></td>
                        <td><?= e(EventTypes::label($event['event_type'])) ?></td>
                        <td>
                            <?php if ($event['start_date']): ?>
                                <?= date('M d', strtotime($event['start_date'])) ?>
                                <?php if ($event['end_date']): ?>
                                    – <?= date('M d, Y', strtotime($event['end_date'])) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= getEventStatus($event) === 'Active' ? 'badge--active' : (getEventStatus($event) === 'Unpublished' ? 'badge--secondary' : '') ?>">
                                <?= e(getEventStatus($event)) ?>
                            </span>
                        </td>
                        <td><?= (int)($pieceCounts[(int)$event['id']] ?? 0) ?></td>
                        <td><?= $event['featured'] ? '<span class="badge badge--gold">⭐ Featured</span>' : '—' ?></td>
                        <td class="actions-cell">
                            <a href="/admin/events/edit?id=<?= $event['id'] ?>" class="admin-btn admin-btn--sm">Edit</a>
                            <a href="/admin/events/delete?id=<?= $event['id'] ?>&csrf=<?= e(csrf_token()) ?>"
                               class="admin-btn admin-btn--sm admin-btn--danger"
                               onclick="return confirm('Delete \'<?= e(addslashes($event['name'])) ?>\'? This cannot be undone.')">
                                Delete
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php include __DIR__ . '/../partials/list-toolbar.php'; ?>
        <?php endif; ?>
    </div>
</main>
<?php if ($canReorder): ?>
<script src="/admin/js/sortable.min.js"></script>
<script src="/admin/js/reorder.js"></script>
<?php endif; ?>
</body>
</html>
