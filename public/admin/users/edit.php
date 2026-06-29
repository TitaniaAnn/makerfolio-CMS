<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

// Installer ships the username/password/email validators reused here. Not in
// bootstrap because it's primarily an install-time class.
if (!class_exists('Installer')) {
    require_once ROOT_PATH . '/includes/MigrationRunner.php';
    require_once ROOT_PATH . '/includes/Installer.php';
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Invalid user id.');
    redirect(SITE_URL . '/admin/users/');
}

$user = Database::fetchOne(
    "SELECT id, username, name, email, github_id, google_sub, google_email, password_hash
       FROM admin_users WHERE id = ? LIMIT 1",
    [$id]
);
if (!$user) {
    flash('error', 'Admin user not found.');
    redirect(SITE_URL . '/admin/users/');
}

$me     = Auth::getUser();
$isMe   = $me && (int)$me['id'] === (int)$user['id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'unlink_github') {
        // Refuse if this would leave the user with no login method.
        if (empty($user['password_hash']) && empty($user['google_sub'])) {
            $errors[] = "Can't unlink GitHub — this is the user's only login method. Set a password or link Google first.";
        } else {
            Database::update('admin_users', ['github_id' => null], 'id = :id', ['id' => $id]);
            ActivityLog::log('users.unlink_github', 'admin_user', $id);
            flash('success', 'GitHub identity unlinked.');
            redirect(SITE_URL . '/admin/users/edit?id=' . $id);
        }
    } elseif ($action === 'unlink_google') {
        if (empty($user['password_hash']) && empty($user['github_id'])) {
            $errors[] = "Can't unlink Google — this is the user's only login method. Set a password or link GitHub first.";
        } else {
            Database::update('admin_users', ['google_sub' => null, 'google_email' => null], 'id = :id', ['id' => $id]);
            ActivityLog::log('users.unlink_google', 'admin_user', $id);
            flash('success', 'Google identity unlinked.');
            redirect(SITE_URL . '/admin/users/edit?id=' . $id);
        }
    } else { // 'save'
        $newUsername = trim((string)($_POST['username'] ?? ''));
        $newName     = trim((string)($_POST['name']     ?? ''));
        $newEmail    = trim((string)($_POST['email']    ?? ''));
        $newPassword = (string)($_POST['password']         ?? '');
        $confirm     = (string)($_POST['password_confirm'] ?? '');

        if (!Installer::isValidUsername($newUsername))                   $errors[] = 'Username must be 3–64 chars, letters/digits/underscore only.';
        if ($newEmail !== '' && !Installer::isValidEmail($newEmail))      $errors[] = 'Email is malformed.';
        if ($newPassword !== '') {
            if (!Installer::isValidPassword($newPassword))                $errors[] = 'New password must be at least 12 characters (no surrounding whitespace).';
            if ($newPassword !== $confirm)                                $errors[] = 'New password and confirmation do not match.';
        }

        // Check username/email uniqueness (excluding this row).
        if (!$errors) {
            $clashU = Database::fetchOne("SELECT id FROM admin_users WHERE username = ? AND id <> ?", [$newUsername, $id]);
            if ($clashU) $errors[] = 'That username is already taken.';
            if ($newEmail !== '') {
                $clashE = Database::fetchOne("SELECT id FROM admin_users WHERE email = ? AND id <> ?", [$newEmail, $id]);
                if ($clashE) $errors[] = 'That email is already linked to another admin.';
            }
        }

        if (!$errors) {
            $update = [
                'username' => $newUsername,
                'name'     => $newName  !== '' ? $newName  : null,
                'email'    => $newEmail !== '' ? $newEmail : null,
            ];
            if ($newPassword !== '') {
                $update['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
            }
            Database::update('admin_users', $update, 'id = :id', ['id' => $id]);
            ActivityLog::log('users.update', 'admin_user', $id, [
                'changed_fields' => array_keys($update),
                'password_changed' => $newPassword !== '',
            ]);
            flash('success', 'Admin user updated.');
            redirect(SITE_URL . '/admin/users/edit?id=' . $id);
        }
    }

    // On error: re-fetch the row so the form values reflect current state
    // (the user may have changed but the failed input is what we want to show).
    $user = Database::fetchOne(
        "SELECT id, username, name, email, github_id, google_sub, google_email, password_hash
           FROM admin_users WHERE id = ? LIMIT 1",
        [$id]
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Admin — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        .au-link-row { display: flex; align-items: center; justify-content: space-between; padding: .65rem 0; border-top: 1px solid var(--sand,#e8e4d8); }
        .au-link-row:first-of-type { border-top: 0; }
        .au-link-row__info strong { display: block; }
        .au-link-row__info small  { display: block; color: var(--fog,#7a8090); font-size: .8rem; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Edit Admin User</h1>
            <a href="/admin/users/" class="admin-btn">Back to list</a>
        </div>

        <?php $flash = getFlash(); if ($flash): ?>
            <div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $err): ?>
            <div class="flash flash--error"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="POST" class="admin-form" style="max-width: 720px;">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
            <input type="hidden" name="action" value="save">

            <div class="admin-card">
                <h2>Account</h2>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required value="<?= e($user['username'] ?? '') ?>"
                           pattern="^[A-Za-z0-9_]{3,64}$" minlength="3" maxlength="64" autocomplete="off">
                </div>
                <div class="form-group">
                    <label>Display name</label>
                    <input type="text" name="name" value="<?= e($user['name'] ?? '') ?>" maxlength="255">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= e($user['email'] ?? '') ?>" maxlength="255">
                </div>
            </div>

            <div class="admin-card">
                <h2>Change password <small style="font-weight:400; color:var(--fog,#7a8090);">(optional)</small></h2>
                <p class="form-hint">Leave blank to keep the existing password. <?php if (empty($user['password_hash'])): ?><strong>(No password set — fill in to enable local login.)</strong><?php endif; ?></p>
                <div class="form-group">
                    <label>New password</label>
                    <input type="password" name="password" minlength="12" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label>Confirm new password</label>
                    <input type="password" name="password_confirm" minlength="12" autocomplete="new-password">
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="admin-btn admin-btn--primary">Save Changes</button>
                <a href="/admin/users/" class="admin-btn">Cancel</a>
            </div>
        </form>

        <div class="admin-card" style="max-width: 720px;">
            <h2>Linked OAuth identities</h2>
            <p class="form-hint">Unlinking just clears the link on this user — it doesn't remove them from the GitHub/Google allowlist. Re-linking happens automatically on the user's next successful OAuth login.</p>

            <div class="au-link-row">
                <div class="au-link-row__info">
                    <strong>GitHub</strong>
                    <small><?= !empty($user['github_id']) ? 'Linked — id ' . e($user['github_id']) : 'Not linked' ?></small>
                </div>
                <?php if (!empty($user['github_id'])): ?>
                <form method="POST" style="margin:0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                    <input type="hidden" name="action" value="unlink_github">
                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger"
                            data-confirm="Unlink GitHub identity from this admin? They'll need to log in via GitHub again to re-link.">
                        Unlink GitHub
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <div class="au-link-row">
                <div class="au-link-row__info">
                    <strong>Google</strong>
                    <small><?= !empty($user['google_sub']) ? 'Linked — ' . e($user['google_email'] ?: $user['google_sub']) : 'Not linked' ?></small>
                </div>
                <?php if (!empty($user['google_sub'])): ?>
                <form method="POST" style="margin:0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                    <input type="hidden" name="action" value="unlink_google">
                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger"
                            data-confirm="Unlink Google identity from this admin? They'll need to log in via Google again to re-link.">
                        Unlink Google
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isMe): ?>
            <p style="max-width:720px; color:var(--fog,#7a8090); font-size:.85rem;">
                You're editing your own account. Changing your username or password will still
                require you to keep your current session open — log out and back in afterward
                to verify the new credentials work.
            </p>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
