<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

// Admin list orders strictly by sort_order so drag-to-reorder produces a
// predictable result. Featured is shown as a badge but doesn't override the
// admin's chosen order on this page. Public pages still do their own ordering.
$query  = ListQuery::fromRequest($_GET);
$q      = $query['q'];
$search = ListQuery::buildSearchClause($q, ['title', 'description', 'technique']);

$total  = (int)(Database::fetchOne(
    "SELECT COUNT(*) AS c FROM piece {$search['sql']}",
    $search['params']
)['c'] ?? 0);
$pg     = ListQuery::pagination($total, $query['page'], $query['perPage']);
// Use the resolved page (pagination() clamps page-out-of-range) so the LIMIT
// offset stays in sync with the navigation we render.
$offset = ($pg['page'] - 1) * $pg['perPage'];
$pieces = Database::fetchAll(
    "SELECT * FROM piece {$search['sql']} ORDER BY sort_order ASC, id ASC LIMIT {$pg['perPage']} OFFSET {$offset}",
    $search['params']
);

// Drag-to-reorder only makes sense when viewing the full unfiltered list;
// otherwise dragging a partial result set would rewrite sort_order based on
// a window that doesn't include all rows.
$canReorder = ($q === '' && $pg['totalPages'] === 1);
$listLabel  = 'pieces';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Pieces — Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/admin.css">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Portfolio Pieces <span class="badge"><?= (int)$total ?></span></h1>
            <a href="/admin/pieces/add" class="admin-btn admin-btn--primary">+ Add Piece</a>
        </div>

        <?php include __DIR__ . '/../partials/list-toolbar.php'; ?>

        <?php if (empty($pieces) && $q === ''): ?>
        <div class="empty-admin">
            <p>No pottery pieces yet.</p>
            <a href="/admin/pieces/add" class="admin-btn admin-btn--primary">Add your first piece</a>
        </div>
        <?php elseif (empty($pieces)): ?>
        <div class="empty-admin">
            <p>No pieces match "<?= e($q) ?>".</p>
            <a href="?" class="admin-btn">Clear search</a>
        </div>
        <?php else: ?>
        <?php if ($canReorder): ?>
            <p class="muted u-mb-1">Drag the <span class="reorder-handle u-reorder-handle">⋮⋮</span> handle to reorder pieces. Order saves automatically.</p>
        <?php endif; ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <?php if ($canReorder): ?><th class="u-col-handle"></th><?php endif; ?>
                        <th>Image</th>
                        <th>Title</th>
                        <th>Technique</th>
                        <th>Year</th>
                        <th>Featured</th>
                        <th>Added</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody<?= $canReorder ? ' data-reorder-kind="piece"' : '' ?>>
                    <?php foreach ($pieces as $p): ?>
                    <tr<?= $canReorder ? ' data-id="' . (int)$p['id'] . '"' : '' ?>>
                        <?php if ($canReorder): ?><td><span class="reorder-handle" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</span></td><?php endif; ?>
                        <td>
                            <img src="/uploads/<?= e($p['image_thumb'] ?? $p['image_path']) ?>"
                                 alt="<?= e($p['title']) ?>" class="admin-table__thumb">
                        </td>
                        <td><strong><?= e($p['title']) ?></strong></td>
                        <td><?= e($p['technique'] ?? '—') ?></td>
                        <td><?= e($p['year'] ?? '—') ?></td>
                        <td><?= $p['featured'] ? '<span class="badge badge--gold">⭐ Featured</span>' : '—' ?></td>
                        <td><?= date('d M Y', strtotime($p['created_at'])) ?></td>
                        <td class="actions-cell">
                            <a href="/admin/pieces/edit?id=<?= $p['id'] ?>" class="admin-btn admin-btn--sm">Edit</a>
                            <a href="/admin/pieces/delete?id=<?= $p['id'] ?>&csrf=<?= e(csrf_token()) ?>"
                               class="admin-btn admin-btn--sm admin-btn--danger"
                               data-confirm="Delete '<?= e($p['title']) ?>'? This cannot be undone.">
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
