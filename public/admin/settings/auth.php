<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

// Installer ships the validators used by save_github / save_google; load it
// before the POST handler runs. (Was previously loaded only after the handler,
// which would fatal on save_github / save_google.)
if (!class_exists('Installer')) {
    require_once ROOT_PATH . '/includes/MigrationRunner.php';
    require_once ROOT_PATH . '/includes/Installer.php';
}

$errors  = [];
$notices = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'local_toggle') {
        $on = !empty($_POST['auth_local_enabled']);
        // Refuse to disable local if it's the only enabled provider AND the
        // current admin user is local-only (would lock them out).
        if (!$on) {
            $current = Auth::getUser();
            if ($current) {
                $row = Database::fetchOne("SELECT username, github_id, google_sub FROM admin_users WHERE id = ?", [$current['id']]);
                $localOnly = $row && $row['username'] && !$row['github_id'] && !$row['google_sub'];
                if ($localOnly) {
                    $errors[] = "Can't disable local login — your account has no OAuth identity linked, you would be locked out.";
                }
            }
        }
        if (!$errors) {
            Database::query(
                "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                ['auth_local_enabled', $on ? '1' : '0']
            );
            ActivityLog::log('settings.auth_save', null, null, ['provider' => 'local', 'enabled' => $on]);
            $notices[] = 'Local login ' . ($on ? 'enabled' : 'disabled') . '.';
        }
    }

    if ($action === 'save_github') {
        $cid    = trim((string)($_POST['gh_client_id']     ?? ''));
        $secret = trim((string)($_POST['gh_client_secret'] ?? ''));
        // Keep existing secret if blank submitted.
        if ($secret === '') {
            $existing = setting('auth_github_client_secret', '');
            $secret   = $existing;
        }
        $allow  = trim((string)($_POST['gh_allowed_users'] ?? ''));
        $enable = !empty($_POST['gh_enable']);

        if ($enable) {
            if ($cid === '')                                  $errors[] = 'GitHub Client ID is required to enable GitHub.';
            if ($secret === '')                               $errors[] = 'GitHub Client Secret is required to enable GitHub.';
            if (!Installer::isValidAllowedUsers($allow))      $errors[] = 'Allowed GitHub usernames must list one or more valid GitHub usernames.';
        }
        if (!$errors) {
            $writes = [
                'auth_github_client_id'     => $cid,
                'auth_github_client_secret' => $secret,
                'auth_github_allowed_users' => $allow,
                'auth_github_enabled'       => $enable ? '1' : '0',
            ];
            foreach ($writes as $k => $v) {
                Database::query(
                    "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                    [$k, $v]
                );
            }
            ActivityLog::log('settings.auth_save', null, null, ['provider' => 'github', 'enabled' => $enable]);
            $notices[] = 'GitHub OAuth ' . ($enable ? 'enabled' : 'disabled') . '.';
        }
    }

    if ($action === 'save_google') {
        $cid    = trim((string)($_POST['g_client_id']     ?? ''));
        $secret = trim((string)($_POST['g_client_secret'] ?? ''));
        if ($secret === '') {
            $secret = (string)setting('auth_google_client_secret', '');
        }
        $allow  = trim((string)($_POST['g_allowed_emails'] ?? ''));
        $enable = !empty($_POST['g_enable']);

        if ($enable) {
            if ($cid === '')                               $errors[] = 'Google Client ID is required to enable Google.';
            if ($secret === '')                            $errors[] = 'Google Client Secret is required to enable Google.';
            if (!Installer::isValidAllowedEmails($allow))  $errors[] = 'Allowed Google emails must list one or more valid email addresses.';
        }
        if (!$errors) {
            $writes = [
                'auth_google_client_id'      => $cid,
                'auth_google_client_secret'  => $secret,
                'auth_google_allowed_emails' => $allow,
                'auth_google_enabled'        => $enable ? '1' : '0',
            ];
            foreach ($writes as $k => $v) {
                Database::query(
                    "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                    [$k, $v]
                );
            }
            ActivityLog::log('settings.auth_save', null, null, ['provider' => 'google', 'enabled' => $enable]);
            $notices[] = 'Google OAuth ' . ($enable ? 'enabled' : 'disabled') . '.';
        }
    }
}

$localOn       = setting('auth_local_enabled', '1') === '1';
$ghEnabled     = setting('auth_github_enabled', '0') === '1';
$ghClientId    = setting('auth_github_client_id', '');
$ghHasSecret   = setting('auth_github_client_secret', '') !== '';
$ghAllowed     = setting('auth_github_allowed_users', '');
$gEnabled      = setting('auth_google_enabled', '0') === '1';
$gClientId     = setting('auth_google_client_id', '');
$gHasSecret    = setting('auth_google_client_secret', '') !== '';
$gAllowed      = setting('auth_google_allowed_emails', '');
$ghCallback    = rtrim(SITE_URL, '/') . '/admin/auth/callback.php';
$gCallback     = rtrim(SITE_URL, '/') . '/admin/auth/google-callback.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Providers — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/pages/settings-auth.css">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Login Providers</h1>
            <a href="/admin/settings/" class="admin-btn">Back to Settings</a>
        </div>

        <?php foreach ($errors as $err): ?>
            <div class="flash flash--error"><?= e($err) ?></div>
        <?php endforeach; ?>
        <?php foreach ($notices as $n): ?>
            <div class="flash flash--success"><?= e($n) ?></div>
        <?php endforeach; ?>

        <p style="color:var(--fog,#7a8090);">
            Configure which login methods the admin login page exposes. Disabled
            providers are hidden from the login form. Local username/password
            authentication is required to be enabled if it's the only way to
            access your account.
        </p>

        <!-- Local -->
        <div class="provider-card">
            <div class="provider-card__head">
                <h2>Local (username + password)</h2>
                <span class="toggle <?= $localOn ? 'on' : '' ?>"><?= $localOn ? 'Enabled' : 'Disabled' ?></span>
            </div>
            <p>Username + password admin accounts stored in <code>admin_users</code>. The first one is created during install.</p>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="local_toggle">
                <div class="checkbox-row">
                    <input type="checkbox" id="local_on" name="auth_local_enabled" value="1" <?= $localOn ? 'checked' : '' ?>>
                    <label for="local_on">Enable local login</label>
                </div>
                <div class="form-actions">
                    <button type="submit" class="admin-btn admin-btn--primary">Save</button>
                </div>
            </form>
        </div>

        <!-- GitHub -->
        <div class="provider-card">
            <div class="provider-card__head">
                <h2>GitHub OAuth</h2>
                <span class="toggle <?= $ghEnabled ? 'on' : '' ?>"><?= $ghEnabled ? 'Enabled' : 'Disabled' ?></span>
            </div>
            <p>Sign in with a GitHub account on the allowlist. Configure an OAuth app at <a href="https://github.com/settings/developers" target="_blank" rel="noopener">github.com/settings/developers</a>.</p>
            <div class="callback-line">Callback URL: <?= e($ghCallback) ?></div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_github">
                <div class="form-row">
                    <label for="gh_client_id">Client ID</label>
                    <input id="gh_client_id" name="gh_client_id" value="<?= e($ghClientId) ?>">
                </div>
                <div class="form-row">
                    <label for="gh_client_secret">Client Secret</label>
                    <input id="gh_client_secret" name="gh_client_secret" type="password" value="" placeholder="<?= $ghHasSecret ? '(unchanged — leave blank to keep current secret)' : '' ?>">
                </div>
                <div class="form-row">
                    <label for="gh_allowed_users">Allowed GitHub usernames</label>
                    <input id="gh_allowed_users" name="gh_allowed_users" value="<?= e($ghAllowed) ?>" placeholder="your-username, friend-username">
                    <small>Comma-separated.</small>
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" id="gh_enable" name="gh_enable" value="1" <?= $ghEnabled ? 'checked' : '' ?>>
                    <label for="gh_enable">Enable GitHub OAuth</label>
                </div>
                <div class="form-actions">
                    <button type="submit" class="admin-btn admin-btn--primary">Save</button>
                </div>
            </form>
        </div>

        <!-- Google -->
        <div class="provider-card">
            <div class="provider-card__head">
                <h2>Google OAuth</h2>
                <span class="toggle <?= $gEnabled ? 'on' : '' ?>"><?= $gEnabled ? 'Enabled' : 'Disabled' ?></span>
            </div>
            <p>Sign in with a Google account on the allowlist. Configure an OAuth 2.0 Client ID at <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console → Credentials</a>.</p>
            <div class="callback-line">Authorized redirect URI: <?= e($gCallback) ?></div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_google">
                <div class="form-row">
                    <label for="g_client_id">Client ID</label>
                    <input id="g_client_id" name="g_client_id" value="<?= e($gClientId) ?>">
                </div>
                <div class="form-row">
                    <label for="g_client_secret">Client Secret</label>
                    <input id="g_client_secret" name="g_client_secret" type="password" value="" placeholder="<?= $gHasSecret ? '(unchanged — leave blank to keep current secret)' : '' ?>">
                </div>
                <div class="form-row">
                    <label for="g_allowed_emails">Allowed Google emails</label>
                    <input id="g_allowed_emails" name="g_allowed_emails" value="<?= e($gAllowed) ?>" placeholder="you@example.com, partner@example.com">
                    <small>Comma-separated. Only accounts with verified emails on this list will be allowed.</small>
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" id="g_enable" name="g_enable" value="1" <?= $gEnabled ? 'checked' : '' ?>>
                    <label for="g_enable">Enable Google OAuth</label>
                </div>
                <div class="form-actions">
                    <button type="submit" class="admin-btn admin-btn--primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</main>
</body>
</html>
