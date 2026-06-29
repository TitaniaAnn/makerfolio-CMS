<?php
/**
 * /admin/auth/2fa-challenge.php — second factor after a successful password.
 *
 * Reached only when Auth::loginLocal returned LOGIN_NEEDS_2FA — i.e. the
 * password was correct AND the admin has totp_enabled = 1. Accepts either a
 * 6-digit TOTP code OR one of the 10 single-use recovery codes.
 *
 * On success: completeLocalLogin → /admin/dashboard.php
 * On failure: counts toward the IP login_attempts rate limit (same window),
 *             redisplay form, log auth.2fa_challenge_failed.
 *
 * No back-end check for OAuth flows here — OAuth providers handle their own
 * 2FA, and Auth::handleGitHubCallback / handleGoogleCallback skip this page
 * entirely. Local-login-only.
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';

$pendingId = Auth::pending2faAdminId();
if (!$pendingId) {
    // No active 2FA challenge — bounce back to login.
    redirect(SITE_URL . '/admin/login');
}

$row = Database::fetchOne(
    "SELECT id, username, name, email, avatar_url, totp_secret, totp_enabled, recovery_codes_hash
       FROM admin_users WHERE id = ? LIMIT 1",
    [$pendingId]
);
if (!$row || (int)$row['totp_enabled'] !== 1 || empty($row['totp_secret'])) {
    // Defensive: pending marker references a user that no longer has 2FA.
    // Drop the marker and bounce.
    Auth::abortPending2fa();
    flash('error', 'Two-factor authentication is no longer configured for that account — please sign in again.');
    redirect(SITE_URL . '/admin/login');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'verify');

    if ($action === 'cancel') {
        Auth::abortPending2fa();
        redirect(SITE_URL . '/admin/login');
    }

    $code = trim((string)($_POST['code'] ?? ''));
    $ip   = Auth::clientIp();

    // Reuse the same login_attempts rate limit so failed TOTP tries
    // contribute to the IP's lockout counter.
    if (Auth::isRateLimited($ip)) {
        $retry = Auth::rateLimitRetryAfter($ip);
        $error = "Too many failed attempts. Try again in {$retry} second" . ($retry === 1 ? '' : 's') . '.';
    } elseif ($code === '') {
        $error = 'Enter a 6-digit code from your authenticator app, or a 10-character recovery code.';
    } else {
        // Try TOTP first (most common case), then recovery codes.
        $ok = false;
        if (preg_match('/^\d{6}$/', $code)) {
            $ok = Totp::verifyCode($row['totp_secret'], $code);
            if ($ok) {
                ActivityLog::log('auth.2fa_challenge_passed', 'admin_user', $pendingId, ['method' => 'totp']);
            }
        } else {
            // Recovery code path.
            $hashes = json_decode((string)($row['recovery_codes_hash'] ?? ''), true) ?: [];
            $matchedIndex = Totp::findRecoveryCode($code, $hashes);
            if ($matchedIndex !== null) {
                // Consume the code (blank out the hash; keep array length stable).
                $hashes[$matchedIndex] = '';
                Database::update('admin_users', [
                    'recovery_codes_hash' => json_encode($hashes),
                ], 'id = :id', ['id' => $pendingId]);
                $remaining = count(array_filter($hashes, fn($h) => is_string($h) && $h !== ''));
                ActivityLog::log('auth.2fa_recovery_used', 'admin_user', $pendingId, ['remaining' => $remaining]);
                ActivityLog::log('auth.2fa_challenge_passed', 'admin_user', $pendingId, ['method' => 'recovery']);
                flash('success', "Recovery code accepted. {$remaining} code" . ($remaining === 1 ? '' : 's') . ' remaining — generate fresh ones from Account → 2FA.');
                $ok = true;
            }
        }

        if ($ok) {
            Auth::completeLocalLogin($pendingId);
            redirect(SITE_URL . '/admin/dashboard');
        } else {
            // Failed attempt — count it against the IP and surface error.
            Database::insert('login_attempts', [
                'ip_address' => substr($ip, 0, 45) ?: null,
                'username'   => substr((string)($row['username'] ?? ''), 0, 64) ?: null,
            ]);
            ActivityLog::log('auth.2fa_challenge_failed', 'admin_user', $pendingId);
            $error = 'Code is incorrect or expired. Codes refresh every 30 seconds — wait for the next one and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-factor authentication — <?= e(setting('site_name', 'My Pottery')) ?></title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/pages/auth-2fa-challenge.css">
</head>
<body class="login-body">
    <div class="login-card">
        <div class="login-card__header">
            <h1><?= e(setting('site_name', 'My Pottery')) ?></h1>
            <span>Two-factor authentication</span>
        </div>

        <?php if ($error): ?>
            <div class="alert alert--error"><?= e($error) ?></div>
        <?php endif; ?>

        <p class="tfa-note">
            Signed in as <strong><?= e($row['username']) ?></strong>. Enter the
            6-digit code from your authenticator app (Google Authenticator, 1Password,
            Authy, etc.) to finish signing in.
        </p>

        <form method="POST" class="tfa-form" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="verify">
            <label>Code
                <input type="text" name="code" required autofocus inputmode="numeric"
                       maxlength="12" pattern="[A-Za-z0-9\-]{6,12}"
                       placeholder="123456 or RECOVERY-CODE">
                <small style="display:block;font-weight:400;color:var(--fog,#7a8090);margin-top:.25rem;">
                    6 digits, or a 10-character recovery code from setup (case-insensitive).
                </small>
            </label>
            <button type="submit">Verify and sign in</button>
        </form>

        <form method="POST" style="margin-top:.75rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="tfa-cancel" style="cursor:pointer;">← Cancel and sign in as a different user</button>
        </form>
    </div>
</body>
</html>
