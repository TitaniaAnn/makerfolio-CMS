<?php
/**
 * /admin/auth/reset-password.php?token=… — finalize a password reset.
 *
 * GET:  validate the token (exists + not expired + not used) and render the
 *       new-password form.
 * POST: validate again, update admin_users.password_hash, mark the row used,
 *       clear login_attempts for this admin's recent IPs, redirect to login.
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';

// Installer ships the password validator we reuse here.
if (!class_exists('Installer')) {
    require_once ROOT_PATH . '/includes/MigrationRunner.php';
    require_once ROOT_PATH . '/includes/Installer.php';
}

if (!AuthProviders::isLocalEnabled()) {
    flash('error', 'Local password login is disabled — password reset is unavailable.');
    redirect(SITE_URL . '/admin/login');
}

$rawToken = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$errors   = [];
$reset    = null;

if ($rawToken !== '' && ctype_xdigit($rawToken) && strlen($rawToken) === 64) {
    $reset = Database::fetchOne(
        "SELECT id, admin_id, expires_at, used_at
           FROM password_resets
          WHERE token_hash = ?
          LIMIT 1",
        [hash('sha256', $rawToken)]
    );
}

$tokenStatus = 'invalid';
if ($reset) {
    if (!empty($reset['used_at'])) {
        $tokenStatus = 'used';
    } elseif (strtotime($reset['expires_at']) < time()) {
        $tokenStatus = 'expired';
    } else {
        $tokenStatus = 'valid';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenStatus === 'valid') {
    csrf_verify();
    $password = (string)($_POST['password']         ?? '');
    $confirm  = (string)($_POST['password_confirm'] ?? '');

    if (!Installer::isValidPassword($password)) {
        $errors[] = 'Password must be at least 12 characters (no surrounding whitespace).';
    } elseif ($password !== $confirm) {
        $errors[] = 'Password and confirmation do not match.';
    }

    if (!$errors) {
        Database::update('admin_users', [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ], 'id = :id', ['id' => (int)$reset['admin_id']]);

        Database::update('password_resets', [
            'used_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => (int)$reset['id']]);

        // Clear this IP's failed-login rows — a successful reset implies the
        // human is who they say they are; don't keep them locked out.
        $ip = Auth::clientIp();
        if ($ip !== '') {
            Database::delete('login_attempts', 'ip_address = ?', [$ip]);
        }

        ActivityLog::log('auth.password_reset_completed', 'admin_user', (int)$reset['admin_id']);

        flash('success', 'Password updated — sign in with the new one.');
        redirect(SITE_URL . '/admin/login');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set new password — <?= e(setting('site_name', 'My Pottery')) ?></title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/pages/auth-reset-password.css">
</head>
<body class="login-body">
    <div class="login-card">
        <div class="login-card__header">
            <h1><?= e(setting('site_name', 'My Pottery')) ?></h1>
            <span>Set new password</span>
        </div>

        <?php if ($tokenStatus !== 'valid'): ?>
            <div class="rp-note">
                <?php if ($tokenStatus === 'expired'): ?>
                    This reset link has expired (links are valid for 60 minutes).
                <?php elseif ($tokenStatus === 'used'): ?>
                    This reset link has already been used. Tokens are single-use.
                <?php else: ?>
                    This reset link is invalid.
                <?php endif; ?>
                Request a new one from
                <a href="/admin/auth/forgot-password">Forgot password</a>.
            </div>
            <p style="margin-top:1rem;"><a href="/admin/login">← Back to sign in</a></p>
        <?php else: ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert--error"><?= e($err) ?></div>
            <?php endforeach; ?>
            <form method="POST" class="rp-form">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($rawToken) ?>">
                <label>New password
                    <input type="password" name="password" required minlength="12" autocomplete="new-password" autofocus>
                    <small style="display:block;font-weight:400;color:var(--fog,#7a8090);margin-top:.2rem;">At least 12 characters.</small>
                </label>
                <label>Confirm new password
                    <input type="password" name="password_confirm" required minlength="12" autocomplete="new-password">
                </label>
                <button type="submit">Set new password</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
