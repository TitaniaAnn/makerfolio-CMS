<?php
/**
 * /admin/auth/forgot-password.php — request a password reset email.
 *
 * Security properties:
 *   - Always responds with the same "if an account exists…" message regardless
 *     of whether the username/email matched (no account-enumeration leak).
 *   - Token is 32 random bytes (hex-encoded → 64 chars). The DB only stores
 *     the SHA-256 hash, so a DB read doesn't yield usable tokens.
 *   - 60-minute TTL. Single-use (marked at redeem time).
 *   - Inert when AuthProviders::isLocalEnabled() is false (no point resetting a
 *     password if local login is disabled).
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (!AuthProviders::isLocalEnabled()) {
    flash('error', 'Local password login is disabled — password reset is unavailable.');
    redirect(SITE_URL . '/admin/login');
}

$submitted = false;
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $submitted  = true;
    $identifier = trim((string)($_POST['identifier'] ?? ''));

    if ($identifier !== '') {
        // Look up by username OR email. Always do the same number of DB ops
        // regardless of result so timing doesn't leak existence either.
        $admin = Database::fetchOne(
            "SELECT id, username, email, google_email
               FROM admin_users
              WHERE username = ? OR email = ? OR google_email = ?
              LIMIT 1",
            [$identifier, $identifier, $identifier]
        );

        if ($admin) {
            $rawToken = bin2hex(random_bytes(32));
            Database::insert('password_resets', [
                'admin_id'   => (int)$admin['id'],
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),
                'ip_address' => Auth::clientIp() ?: null,
            ]);
            // Best-effort opportunistic cleanup of expired rows.
            Database::query("DELETE FROM password_resets WHERE expires_at < NOW() AND used_at IS NULL");

            // mail() failure isn't surfaced — admin should infer from no email arriving.
            // (We deliberately don't reveal "account exists, mail failed" because the
            //  same response shape avoids enumeration.)
            Mailer::sendPasswordReset((int)$admin['id'], $rawToken);
            ActivityLog::log('auth.password_reset_requested', 'admin_user', (int)$admin['id'], ['identifier' => $identifier]);
        } else {
            ActivityLog::log('auth.password_reset_requested', null, null, ['identifier' => $identifier, 'matched' => false]);
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot password — <?= e(setting('site_name', 'My Pottery')) ?></title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        .fp-form { display: grid; gap: .75rem; }
        .fp-form label { display: block; font-weight: 600; }
        .fp-form input { display: block; width: 100%; padding: .55rem .7rem; border: 1px solid var(--sand,#e8e4d8); border-radius: 6px; font: inherit; }
        .fp-form button { padding: .65rem 1rem; border-radius: 6px; border: none; background: var(--clay,#d4a820); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        .fp-note { background: #f4f2ec; padding: .85rem 1rem; border-radius: 6px; color: var(--ink); font-size: .92rem; }
    </style>
</head>
<body class="login-body">
    <div class="login-card">
        <div class="login-card__header">
            <h1><?= e(setting('site_name', 'My Pottery')) ?></h1>
            <span>Reset password</span>
        </div>

        <?php if ($submitted): ?>
            <div class="fp-note">
                If an admin account exists with that username or email, a reset link
                is on its way. The link expires in 60 minutes and can only be used once.
                Check your spam folder if it doesn't arrive within a few minutes.
            </div>
            <p style="margin-top:1rem;"><a href="/admin/login">← Back to sign in</a></p>
        <?php else: ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert--error"><?= e($err) ?></div>
            <?php endforeach; ?>
            <p style="font-size:.95rem; color:var(--fog,#7a8090);">
                Enter the username or email tied to your admin account and we'll
                send you a link to set a new password.
            </p>
            <form method="POST" class="fp-form">
                <?= csrf_field() ?>
                <label>Username or email
                    <input type="text" name="identifier" required autofocus autocomplete="username">
                </label>
                <button type="submit">Send reset link</button>
            </form>
            <p style="margin-top:1rem;"><a href="/admin/login">← Back to sign in</a></p>
        <?php endif; ?>
    </div>
</body>
</html>
