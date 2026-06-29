<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if (Auth::isLoggedIn()) {
    redirect(SITE_URL . '/admin/dashboard');
}

$loginError = null;
if (!empty($_SESSION['login_error'])) {
    $loginError = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

// Local login form handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && AuthProviders::isLocalEnabled()) {
    csrf_verify();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ip       = Auth::clientIp();

    // Pre-check the rate limit so we can show a useful "try again in N seconds"
    // message instead of the generic "invalid username or password".
    if (Auth::isRateLimited($ip)) {
        $retry = Auth::rateLimitRetryAfter($ip);
        $loginError = "Too many failed login attempts. Try again in {$retry} second" . ($retry === 1 ? '' : 's') . '.';
    } else {
        $result = Auth::loginLocal($username, $password);
        if ($result === Auth::LOGIN_OK) {
            redirect(SITE_URL . '/admin/dashboard');
        } elseif ($result === Auth::LOGIN_NEEDS_2FA) {
            redirect(SITE_URL . '/admin/auth/2fa-challenge');
        } else {
            $loginError = 'Invalid username or password.';
            if (Auth::isRateLimited($ip)) {
                $retry = Auth::rateLimitRetryAfter($ip);
                $loginError .= " Too many failed attempts — locked out for {$retry} second" . ($retry === 1 ? '' : 's') . '.';
            }
        }
    }
}

$localOn  = AuthProviders::isLocalEnabled();
$githubOn = AuthProviders::isGithubEnabled();
$googleOn = AuthProviders::isGoogleEnabled();
$noneOn   = !$localOn && !$githubOn && !$googleOn;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — <?= e(setting('site_name')) ?></title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/pages/login.css">
</head>
<body class="login-body">
    <div class="login-card">
        <div class="login-card__header">
            <h1><?= e(setting('site_name', 'My Pottery')) ?></h1>
            <span>Studio Admin</span>
        </div>

        <?php if ($loginError): ?>
            <div class="alert alert--error"><?= e($loginError) ?></div>
        <?php endif; ?>

        <?php if ($noneOn): ?>
            <div class="empty-state">
                <strong>No login methods are enabled.</strong>
                Visit <code>/install/</code> (if still present) to set one up, or enable a provider in the database <code>settings</code> table.
            </div>
        <?php endif; ?>

        <?php if ($localOn): ?>
            <form method="POST" class="local-form" autocomplete="on">
                <?= csrf_field() ?>
                <label>Username
                    <input name="username" type="text" required autofocus autocomplete="username">
                </label>
                <label>Password
                    <input name="password" type="password" required autocomplete="current-password">
                </label>
                <button type="submit">Sign in</button>
                <p class="login-forgot">
                    <a href="/admin/auth/forgot-password">Forgot password?</a>
                </p>
            </form>
        <?php endif; ?>

        <?php if ($localOn && ($githubOn || $googleOn)): ?>
            <div class="login-card__divider"><span>or</span></div>
        <?php endif; ?>

        <?php if ($githubOn): ?>
            <a href="<?= e(Auth::getGitHubAuthUrl()) ?>" class="oauth-btn oauth-btn--github">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.374 0 0 5.373 0 12c0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0112 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576C20.566 21.797 24 17.3 24 12c0-6.627-5.373-12-12-12z"/></svg>
                Sign in with GitHub
            </a>
        <?php endif; ?>

        <?php if ($googleOn): ?>
            <a href="<?= e(Auth::getGoogleAuthUrl()) ?>" class="oauth-btn oauth-btn--google">
                <svg width="20" height="20" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.75 3.27-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.99.66-2.25 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M5.84 14.1A6.61 6.61 0 0 1 5.5 12c0-.73.13-1.44.34-2.1V7.07H2.18a11 11 0 0 0 0 9.86l3.66-2.83z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.2 1.64l3.15-3.15A11 11 0 0 0 2.18 7.07L5.84 9.9C6.71 7.31 9.14 5.38 12 5.38z"/></svg>
                Sign in with Google
            </a>
        <?php endif; ?>
    </div>
</body>
</html>
