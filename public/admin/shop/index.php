<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

// Admin list orders strictly by sort_order so drag-to-reorder is predictable.
// Public storefront does its own type-based filtering + ordering.
$query  = ListQuery::fromRequest($_GET);
$q      = $query['q'];
// LIKE columns are `products.*`; the join'd shop_categories.name is searched
// too via the alias.
$search = ListQuery::buildSearchClause($q, ['name', 'description', 'technique']);
// buildSearchClause emits unqualified backticks (`name`); rewrite to qualified
// `p.name` so the JOIN doesn't make the column reference ambiguous.
$searchSql = str_replace(['`name`', '`description`', '`technique`'], ['p.name', 'p.description', 'p.technique'], $search['sql']);

$total = (int)(Database::fetchOne(
    "SELECT COUNT(*) AS c FROM products p {$searchSql}",
    $search['params']
)['c'] ?? 0);
$pg    = ListQuery::pagination($total, $query['page'], $query['perPage']);
$offset = ($pg['page'] - 1) * $pg['perPage'];

$products = Database::fetchAll(
    "SELECT p.*, c.name as category_name
     FROM products p
     LEFT JOIN shop_categories c ON p.category_id = c.id
     {$searchSql}
     ORDER BY p.sort_order ASC, p.id ASC
     LIMIT {$pg['perPage']} OFFSET {$offset}",
    $search['params']
);

$canReorder = ($q === '' && $pg['totalPages'] === 1);
$listLabel  = 'products';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop Products — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Shop Products <span class="badge"><?= (int)$total ?></span></h1>
            <a href="/admin/shop/add-product" class="admin-btn admin-btn--primary">+ Add Product</a>
        </div>

        <?php include __DIR__ . '/../partials/list-toolbar.php'; ?>

        <?php if (empty($products) && $q === ''): ?>
        <div class="empty-admin">
            <p>No products yet.</p>
            <a href="/admin/shop/add-product" class="admin-btn admin-btn--primary">Add first product</a>
        </div>
        <?php elseif (empty($products)): ?>
        <div class="empty-admin">
            <p>No products match "<?= e($q) ?>".</p>
            <a href="?" class="admin-btn">Clear search</a>
        </div>
        <?php else: ?>
        <?php if ($canReorder): ?>
            <p class="muted" style="margin-bottom:.75rem;">Drag the <span class="reorder-handle" style="display:inline; cursor:default;">⋮⋮</span> handle to reorder products. Order saves automatically.</p>
        <?php endif; ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <?php if ($canReorder): ?><th style="width:32px;"></th><?php endif; ?>
                        <th>Image</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Visible</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody<?= $canReorder ? ' data-reorder-kind="products"' : '' ?>>
                    <?php foreach ($products as $p): ?>
                    <tr<?= $canReorder ? ' data-id="' . (int)$p['id'] . '"' : '' ?>>
                        <?php if ($canReorder): ?><td><span class="reorder-handle" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</span></td><?php endif; ?>
                        <td>
                            <?php if ($p['image_path']): ?>
                            <img src="/uploads/<?= e($p['image_path']) ?>" alt="" class="admin-table__thumb">
                            <?php else: ?>
                            <span class="no-img">—</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= e($p['name']) ?></strong></td>
                        <td><span class="badge badge--<?= $p['type'] === 'merch' ? 'blue' : 'clay' ?>"><?= e($p['type']) ?></span></td>
                        <td><?= e($p['category_name'] ?? '—') ?></td>
                        <td><?= $p['price'] ? '$' . number_format($p['price'], 2) : '—' ?></td>
                        <td>
                            <span class="status-badge status-badge--<?= e($p['status']) ?>">
                                <?= e($p['status']) ?>
                            </span>
                        </td>
                        <td><?= !empty($p['is_visible']) ? 'Yes' : 'No' ?></td>
                        <td class="actions-cell">
                            <a href="/admin/shop/add-product?id=<?= $p['id'] ?>" class="admin-btn admin-btn--sm">Edit</a>
                            <a href="/admin/shop/toggle-visibility?id=<?= $p['id'] ?>&csrf=<?= e(csrf_token()) ?>"
                               class="admin-btn admin-btn--sm"
                               data-confirm="<?= !empty($p['is_visible']) ? 'Hide' : 'Show' ?> this product in the public shop?">
                                <?= !empty($p['is_visible']) ? 'Hide' : 'Show' ?>
                            </a>
                            <a href="/admin/shop/delete-product?id=<?= $p['id'] ?>&csrf=<?= e(csrf_token()) ?>"
                               class="admin-btn admin-btn--sm admin-btn--danger"
                               data-confirm="Delete this product?">Delete</a>
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
