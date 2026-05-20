<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

// Installer ships the username/password/email validators reused here. Not in
// bootstrap because it's primarily an install-time class.
if (!class_exists('Installer')) {
    require_once ROOT_PATH . '/includes/MigrationRunner.php';
    require_once ROOT_PATH . '/includes/Installer.php';
}

$errors = [];
$prev   = ['username' => '', 'name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $prev = [
        'username' => trim((string)($_POST['username'] ?? '')),
        'name'     => trim((string)($_POST['name']     ?? '')),
        'email'    => trim((string)($_POST['email']    ?? '')),
    ];
    $password = (string)($_POST['password']         ?? '');
    $confirm  = (string)($_POST['password_confirm'] ?? '');

    if (!Installer::isValidUsername($prev['username']))                   $errors[] = 'Username must be 3–64 chars, letters/digits/underscore only.';
    if (!Installer::isValidPassword($password))                           $errors[] = 'Password must be at least 12 characters (no surrounding whitespace).';
    if ($password !== $confirm)                                          $errors[] = 'Password and confirmation do not match.';
    if ($prev['email'] !== '' && !Installer::isValidEmail($prev['email'])) $errors[] = 'Email is malformed.';

    if (!$errors) {
        // Check username + email uniqueness against the existing constraints.
        $clash = Database::fetchOne("SELECT id FROM admin_users WHERE username = ?", [$prev['username']]);
        if ($clash) $errors[] = 'That username is already taken.';

        if (!$errors && $prev['email'] !== '') {
            $clashE = Database::fetchOne("SELECT id FROM admin_users WHERE email = ?", [$prev['email']]);
            if ($clashE) $errors[] = 'That email is already linked to another admin.';
        }
    }

    if (!$errors) {
        $newId = Database::insert('admin_users', [
            'username'      => $prev['username'],
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'name'          => $prev['name']  !== '' ? $prev['name']  : null,
            'email'         => $prev['email'] !== '' ? $prev['email'] : null,
        ]);
        ActivityLog::log('users.create', 'admin_user', $newId, ['username' => $prev['username']]);
        flash('success', 'Admin user "' . $prev['username'] . '" added.');
        redirect(SITE_URL . '/admin/users/');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Admin — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Add Admin User</h1>
            <a href="/admin/users/" class="admin-btn">Back to list</a>
        </div>

        <?php foreach ($errors as $err): ?>
            <div class="flash flash--error"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="POST" class="admin-form" style="max-width: 560px;">
            <?= csrf_field() ?>

            <div class="admin-card">
                <h2>Account</h2>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required value="<?= e($prev['username']) ?>"
                           pattern="^[A-Za-z0-9_]{3,64}$" minlength="3" maxlength="64" autocomplete="username" autofocus>
                    <p class="form-hint">3–64 chars. Letters, digits, and underscores only.</p>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required minlength="12" autocomplete="new-password">
                    <p class="form-hint">At least 12 characters.</p>
                </div>
                <div class="form-group">
                    <label>Confirm password</label>
                    <input type="password" name="password_confirm" required minlength="12" autocomplete="new-password">
                </div>
            </div>

            <div class="admin-card">
                <h2>Profile (optional)</h2>
                <div class="form-group">
                    <label>Display name</label>
                    <input type="text" name="name" value="<?= e($prev['name']) ?>" maxlength="255">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= e($prev['email']) ?>" maxlength="255">
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="admin-btn admin-btn--primary">Add Admin</button>
                <a href="/admin/users/" class="admin-btn">Cancel</a>
            </div>
        </form>
    </div>
</main>
</body>
</html>
