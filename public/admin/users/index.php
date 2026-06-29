<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

$me    = Auth::getUser();
$users = Database::fetchAll(
    "SELECT id, username, name, email, github_id, google_sub, google_email,
            password_hash, last_login, created_at
       FROM admin_users
      ORDER BY id ASC"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Users — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        .au-providers { display: flex; gap: .3rem; flex-wrap: wrap; }
        .au-badge { font-size: .7rem; font-weight: 600; padding: .15rem .5rem; border-radius: 4px; letter-spacing: .03em; border: 1px solid var(--sand,#e8e4d8); background: #fff; }
        .au-badge--local  { background: #f4f2ec; color: var(--ink); }
        .au-badge--github { background: #1e2430; color: #fff; border-color: #1e2430; }
        .au-badge--google { background: #fff; color: #1a73e8; border-color: #1a73e8; }
        .au-badge--me     { background: #d4a820; color: #fff; border-color: #d4a820; }
        .au-meta { font-size: .8rem; color: var(--fog,#7a8090); }
    </style>
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Admin Users <span class="badge"><?= count($users) ?></span></h1>
            <a href="/admin/users/add" class="admin-btn admin-btn--primary">+ Add Admin</a>
        </div>

        <?php $flash = getFlash(); if ($flash): ?>
            <div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
        <?php endif; ?>

        <p style="color:var(--fog,#7a8090);">
            Anyone listed here can sign in to the admin. Add additional local
            admins below. To onboard someone via OAuth, add their username to
            <a href="/admin/settings/auth">/admin/settings/auth.php</a> instead —
            a row is auto-created the first time they log in.
        </p>

        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Name / Email</th>
                        <th>Login methods</th>
                        <th>Last login</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <?php $isMe = $me && (int)$me['id'] === (int)$u['id']; ?>
                    <tr>
                        <td>
                            <strong><?= e($u['username'] ?? '(no username)') ?></strong>
                            <?php if ($isMe): ?><span class="au-badge au-badge--me" style="margin-left:.35rem;">YOU</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['name']):  ?><div><?= e($u['name']) ?></div><?php endif; ?>
                            <?php if ($u['email']): ?><div class="au-meta"><?= e($u['email']) ?></div><?php endif; ?>
                            <?php if (!$u['name'] && !$u['email']): ?>—<?php endif; ?>
                        </td>
                        <td>
                            <div class="au-providers">
                                <?php if (!empty($u['password_hash'])): ?>
                                    <span class="au-badge au-badge--local">Local password</span>
                                <?php endif; ?>
                                <?php if (!empty($u['github_id'])): ?>
                                    <span class="au-badge au-badge--github" title="GitHub ID: <?= e($u['github_id']) ?>">GitHub</span>
                                <?php endif; ?>
                                <?php if (!empty($u['google_sub'])): ?>
                                    <span class="au-badge au-badge--google" title="<?= e($u['google_email']) ?>">Google</span>
                                <?php endif; ?>
                                <?php if (empty($u['password_hash']) && empty($u['github_id']) && empty($u['google_sub'])): ?>
                                    <span class="au-meta">⚠ no login method</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="au-meta"><?= $u['last_login'] ? e(date('d M Y H:i', strtotime($u['last_login']))) : '—' ?></td>
                        <td class="au-meta"><?= $u['created_at'] ? e(date('d M Y', strtotime($u['created_at']))) : '—' ?></td>
                        <td class="actions-cell">
                            <a href="/admin/users/edit?id=<?= (int)$u['id'] ?>" class="admin-btn admin-btn--sm">Edit</a>
                            <?php if (!$isMe): ?>
                            <form method="POST" action="/admin/users/delete" style="display:inline;"
                                  data-confirm="Delete admin user '<?= e($u['username'] ?? $u['email'] ?? 'this user') ?>'? This cannot be undone.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Delete</button>
                            </form>
                            <?php else: ?>
                                <span class="au-meta" title="You can't delete the account you're signed in with">— self</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
</body>
</html>
