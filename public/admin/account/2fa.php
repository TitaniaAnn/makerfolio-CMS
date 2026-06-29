<?php
/**
 * /admin/account/2fa.php — admin manages 2FA on their OWN account.
 *
 * Three states:
 *   1. Not enrolled (totp_enabled = 0, no secret): show "Enable 2FA" button.
 *      Clicking generates a fresh secret + shows it + asks for a verification code.
 *   2. Enrolling (totp_enabled = 0, secret present): show the secret + otpauth
 *      URI + manual entry text + verification form. Code must match before
 *      flipping totp_enabled to 1.
 *   3. Enabled (totp_enabled = 1): show "2FA is enabled" + recovery code count
 *      + buttons to regenerate recovery codes or disable 2FA (asks for current
 *      password to confirm disable).
 *
 * Recovery codes are generated on enrollment AND on regenerate — shown ONCE.
 * Admin must save them before leaving the page; we don't store the plain
 * codes anywhere (only bcrypt hashes).
 *
 * Each admin manages their own 2FA — there's no UI for one admin to enable
 * 2FA on another admin's behalf. (Security: only the account holder should
 * see the secret.)
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

$user = Auth::getUser();
$admin = Database::fetchOne(
    "SELECT id, username, password_hash, totp_secret, totp_enabled, recovery_codes_hash
       FROM admin_users WHERE id = ? LIMIT 1",
    [(int)$user['id']]
);
if (!$admin) {
    // Shouldn't happen — session id points at a deleted row. Log the user out.
    Auth::logout();
    redirect(SITE_URL . '/admin/login');
}

$errors = [];
$freshRecoveryCodes = null; // populated once after enroll / regenerate; rendered then cleared

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'start_enroll' && (int)$admin['totp_enabled'] === 0) {
        // Generate a fresh secret. Do NOT enable yet — admin must verify a code first.
        $secret = Totp::generateSecret();
        Database::update('admin_users', [
            'totp_secret' => $secret,
            // recovery_codes_hash stays null until verification completes
        ], 'id = :id', ['id' => $admin['id']]);
        // Reload so the rest of this request sees the new secret.
        $admin['totp_secret'] = $secret;
    }

    if ($action === 'verify_enroll' && (int)$admin['totp_enabled'] === 0 && !empty($admin['totp_secret'])) {
        $code = trim((string)($_POST['code'] ?? ''));
        if (!Totp::verifyCode($admin['totp_secret'], $code)) {
            $errors[] = 'That code did not match. Make sure your authenticator app is in sync (it should refresh every 30 seconds) and try again.';
        } else {
            $freshRecoveryCodes = Totp::generateRecoveryCodes();
            Database::update('admin_users', [
                'totp_enabled'        => 1,
                'recovery_codes_hash' => json_encode(Totp::hashRecoveryCodes($freshRecoveryCodes)),
            ], 'id = :id', ['id' => $admin['id']]);
            $admin['totp_enabled'] = 1;
            $admin['recovery_codes_hash'] = json_encode(Totp::hashRecoveryCodes($freshRecoveryCodes));
            ActivityLog::log('auth.2fa_setup', 'admin_user', (int)$admin['id']);
            flash('success', '2FA is now enabled. Save your recovery codes below — they will not be shown again.');
        }
    }

    if ($action === 'cancel_enroll' && (int)$admin['totp_enabled'] === 0 && !empty($admin['totp_secret'])) {
        Database::update('admin_users', [
            'totp_secret' => null,
        ], 'id = :id', ['id' => $admin['id']]);
        $admin['totp_secret'] = null;
        flash('success', 'Enrollment cancelled. No 2FA on your account.');
        redirect(SITE_URL . '/admin/account/2fa');
    }

    if ($action === 'regenerate_codes' && (int)$admin['totp_enabled'] === 1) {
        $confirmPw = (string)($_POST['password'] ?? '');
        if (!password_verify($confirmPw, $admin['password_hash'])) {
            $errors[] = 'Current password did not match.';
        } else {
            $freshRecoveryCodes = Totp::generateRecoveryCodes();
            Database::update('admin_users', [
                'recovery_codes_hash' => json_encode(Totp::hashRecoveryCodes($freshRecoveryCodes)),
            ], 'id = :id', ['id' => $admin['id']]);
            ActivityLog::log('auth.2fa_recovery_codes_regenerated', 'admin_user', (int)$admin['id']);
            flash('success', 'New recovery codes generated. Save them — old codes no longer work.');
        }
    }

    if ($action === 'disable' && (int)$admin['totp_enabled'] === 1) {
        $confirmPw = (string)($_POST['password'] ?? '');
        if (!password_verify($confirmPw, $admin['password_hash'])) {
            $errors[] = 'Current password did not match.';
        } else {
            Database::update('admin_users', [
                'totp_enabled'        => 0,
                'totp_secret'         => null,
                'recovery_codes_hash' => null,
            ], 'id = :id', ['id' => $admin['id']]);
            ActivityLog::log('auth.2fa_disabled', 'admin_user', (int)$admin['id']);
            flash('success', '2FA disabled. You can re-enable it any time from this page.');
            redirect(SITE_URL . '/admin/account/2fa');
        }
    }
}

// Re-resolve display state after any POST.
$totpEnabled  = (int)$admin['totp_enabled'] === 1;
$enrolling    = !$totpEnabled && !empty($admin['totp_secret']);
$accountName  = $admin['username'] ?: 'admin';
$issuer       = setting('site_name', 'My Pottery');
$otpauthUri   = !empty($admin['totp_secret'])
    ? Totp::otpauthUri($admin['totp_secret'], $accountName, $issuer)
    : '';
$recoveryRemaining = 0;
if ($totpEnabled && !empty($admin['recovery_codes_hash'])) {
    $stored = json_decode((string)$admin['recovery_codes_hash'], true) ?: [];
    $recoveryRemaining = count(array_filter($stored, fn($h) => is_string($h) && $h !== ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-factor authentication — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/pages/account-2fa.css">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Two-factor authentication</h1>
        </div>

        <?php foreach ($errors as $err): ?>
            <div class="flash flash--error"><?= e($err) ?></div>
        <?php endforeach; ?>

        <p class="u-muted-fog">
            Manage TOTP-based 2FA for your own account (<strong><?= e($admin['username']) ?></strong>).
            Each admin manages their own; you can't enable or disable 2FA for someone else.
            OAuth logins (GitHub, Google) use the provider's own 2FA — this page only affects local password login.
        </p>

        <?php if ($freshRecoveryCodes): ?>
            <div class="tfa-warning">
                <strong>Save these recovery codes now — they will not be shown again.</strong>
                Each code works exactly once. Use one if you lose access to your authenticator app.
            </div>
            <ul class="tfa-codes">
                <?php foreach ($freshRecoveryCodes as $code): ?>
                    <li><?= e($code) ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="tfa-p-spaced">
                <a href="/admin/account/2fa" class="admin-btn admin-btn--primary">I've saved them — continue</a>
            </p>
        <?php elseif ($totpEnabled): ?>
            <!-- ENABLED STATE -->
            <div class="tfa-state tfa-state--on">✓ 2FA is enabled on your account.</div>
            <p>Recovery codes remaining: <strong><?= (int)$recoveryRemaining ?> of 10</strong>.
                <?php if ($recoveryRemaining <= 2): ?>
                    <em class="tfa-danger-text">Running low — generate new codes.</em>
                <?php endif; ?>
            </p>

            <div class="admin-card tfa-card-520">
                <h2>Generate new recovery codes</h2>
                <p class="form-hint">Replaces all 10 codes. Old codes stop working immediately.</p>
                <form method="POST" class="tfa-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="regenerate_codes">
                    <label class="tfa-field-label">Confirm with your password</label>
                    <input type="password" name="password" required autocomplete="current-password">
                    <p class="tfa-p-top"><button type="submit" class="admin-btn admin-btn--primary">Generate new codes</button></p>
                </form>
            </div>

            <div class="admin-card tfa-card-520">
                <h2 class="tfa-danger-text">Disable 2FA</h2>
                <p class="form-hint">Turns 2FA off and deletes your secret + recovery codes. You can re-enable any time.</p>
                <form method="POST" class="tfa-form"
                      data-confirm="Disable 2FA on your account? You can re-enable any time.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="disable">
                    <label class="tfa-field-label">Confirm with your password</label>
                    <input type="password" name="password" required autocomplete="current-password">
                    <p class="tfa-p-top"><button type="submit" class="admin-btn admin-btn--danger">Disable 2FA</button></p>
                </form>
            </div>

        <?php elseif ($enrolling): ?>
            <!-- ENROLLING STATE: secret generated, waiting for verification code -->
            <div class="tfa-state tfa-state--off">2FA setup in progress — verify a code to finish.</div>

            <div class="admin-card tfa-w640">
                <h2>Step 1 — Add the secret to your authenticator app</h2>
                <p>Open your authenticator (Google Authenticator, 1Password, Authy, Microsoft Authenticator, etc.) and either tap the link below or paste the secret manually.</p>
                <p class="tfa-p-1">
                    <a href="<?= e($otpauthUri) ?>" class="tfa-uri">Open in authenticator app</a>
                </p>
                <p class="tfa-hint-fog">Manual entry — paste this secret into your app:</p>
                <div class="tfa-secret"><?= e(Totp::formatSecretForDisplay($admin['totp_secret'])) ?></div>
                <p class="form-hint tfa-p-top-sm">Algorithm: SHA1 · Digits: 6 · Period: 30s · Account: <?= e($accountName) ?> · Issuer: <?= e($issuer) ?></p>
            </div>

            <div class="admin-card tfa-w640">
                <h2>Step 2 — Verify a code from your app</h2>
                <p>Once the secret is added, your app will show a 6-digit code that changes every 30 seconds. Enter it here to finish enabling 2FA.</p>
                <form method="POST" class="tfa-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify_enroll">
                    <label class="tfa-field-label">6-digit code</label>
                    <input type="text" name="code" required autofocus inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="123456">
                    <p class="tfa-p-actions">
                        <button type="submit" class="admin-btn admin-btn--primary">Verify and enable 2FA</button>
                    </p>
                </form>
            </div>

            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel_enroll">
                <button type="submit" class="admin-btn tfa-btn-ghost">Cancel and discard this secret</button>
            </form>

        <?php else: ?>
            <!-- NOT ENROLLED STATE -->
            <div class="tfa-state tfa-state--off">2FA is not enabled on your account.</div>
            <p class="tfa-w640">
                With 2FA on, signing in via local password also requires a 6-digit code
                from your authenticator app. Significantly raises the bar against
                credential-stuffing attacks. You'll be given 10 single-use recovery codes
                during setup to use if you lose your phone.
            </p>
            <form method="POST" class="tfa-form-top">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="start_enroll">
                <button type="submit" class="admin-btn admin-btn--primary">Set up 2FA</button>
            </form>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
