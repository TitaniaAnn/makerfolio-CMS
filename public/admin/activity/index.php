<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

$perPage     = 50;
$page        = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($page - 1) * $perPage;
$adminFilter = (isset($_GET['admin_id']) && $_GET['admin_id'] !== '') ? (int)$_GET['admin_id'] : null;
$actionFilter= isset($_GET['action']) ? trim((string)$_GET['action']) : '';
if ($actionFilter === '') $actionFilter = null;

$rows  = ActivityLog::recent($perPage, $offset, $adminFilter, $actionFilter);
$total = ActivityLog::totalCount($adminFilter, $actionFilter);
$pages = max(1, (int)ceil($total / $perPage));

$admins = Database::fetchAll("SELECT id, username FROM admin_users ORDER BY username ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        .al-filters { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1rem; align-items: end; }
        .al-filters label { display: block; font-size: .8rem; font-weight: 600; color: var(--fog,#7a8090); }
        .al-filters select, .al-filters input { padding: .4rem .55rem; border: 1px solid var(--sand,#e8e4d8); border-radius: 6px; font: inherit; }
        .al-action  { font-family: ui-monospace, Menlo, monospace; font-size: .82rem; color: var(--clay,#d4a820); white-space: nowrap; }
        .al-details { font-family: ui-monospace, Menlo, monospace; font-size: .78rem; color: var(--ink-lt,#3a4050); max-width: 360px; overflow-wrap: anywhere; }
        .al-ip      { font-family: ui-monospace, Menlo, monospace; font-size: .78rem; color: var(--fog,#7a8090); }
        .al-time    { font-size: .82rem; color: var(--ink-lt,#3a4050); white-space: nowrap; }
        .al-anon    { color: var(--fog,#7a8090); font-style: italic; }
        .al-paginate { display: flex; gap: .3rem; align-items: center; margin-top: 1rem; flex-wrap: wrap; }
        .al-paginate a, .al-paginate span { padding: .25rem .55rem; border: 1px solid var(--sand,#e8e4d8); border-radius: 4px; text-decoration: none; font-size: .8rem; color: var(--ink); }
        .al-paginate .current { background: var(--clay,#d4a820); color: #fff; border-color: var(--clay,#d4a820); }
    </style>
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Activity Log <span class="badge"><?= (int)$total ?></span></h1>
        </div>

        <p style="color:var(--fog,#7a8090);">
            Append-only audit trail of admin actions: logins, settings saves,
            user changes, content reset, backup downloads. Rows are never
            mutated — wipe the table directly if you need to reset the log.
        </p>

        <form method="GET" class="al-filters">
            <div>
                <label>Admin</label>
                <select name="admin_id">
                    <option value="">All admins</option>
                    <?php foreach ($admins as $a): ?>
                        <option value="<?= (int)$a['id'] ?>" <?= ($adminFilter === (int)$a['id']) ? 'selected' : '' ?>>
                            <?= e($a['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Action</label>
                <select name="action">
                    <option value="">All actions</option>
                    <?php foreach (ActivityLog::ACTIONS as $act): ?>
                        <option value="<?= e($act) ?>" <?= ($actionFilter === $act) ? 'selected' : '' ?>>
                            <?= e($act) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="admin-btn">Filter</button>
                <?php if ($adminFilter !== null || $actionFilter !== null): ?>
                    <a href="/admin/activity/" class="admin-btn">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if (empty($rows)): ?>
            <div class="empty-admin"><p>No activity recorded for the current filter.</p></div>
        <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Admin</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>Details</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="al-time"><?= e(date('Y-m-d H:i:s', strtotime($r['created_at']))) ?></td>
                        <td>
                            <?php if ($r['admin_username']): ?>
                                <strong><?= e($r['admin_username']) ?></strong>
                            <?php elseif ($r['admin_id']): ?>
                                <span class="al-anon">deleted #<?= (int)$r['admin_id'] ?></span>
                            <?php else: ?>
                                <span class="al-anon">anonymous</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="al-action"><?= e($r['action']) ?></span></td>
                        <td>
                            <?php if ($r['target_type']): ?>
                                <?= e($r['target_type']) ?>
                                <?php if ($r['target_id'] !== null): ?>
                                    <span class="al-action">#<?= e($r['target_id']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="al-details">
                            <?php if ($r['details']): ?>
                                <?= e($r['details']) ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="al-ip"><?= e($r['ip_address'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
        <div class="al-paginate">
            <?php
            $qs = function (int $p) use ($adminFilter, $actionFilter): string {
                $params = ['page' => $p];
                if ($adminFilter !== null) $params['admin_id'] = $adminFilter;
                if ($actionFilter !== null) $params['action'] = $actionFilter;
                return '?' . http_build_query($params);
            };
            ?>
            <?php if ($page > 1): ?>
                <a href="<?= e($qs($page - 1)) ?>">‹ Prev</a>
            <?php endif; ?>
            <span class="current">Page <?= $page ?> of <?= $pages ?></span>
            <?php if ($page < $pages): ?>
                <a href="<?= e($qs($page + 1)) ?>">Next ›</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
