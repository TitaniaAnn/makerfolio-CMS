<?php
/**
 * Auth — admin login flow. Supports three provider types:
 *
 *   * local  — username + password_hash (PASSWORD_DEFAULT, currently bcrypt)
 *   * github — OAuth, gated by AuthProviders::githubConfig()['allowed_users']
 *   * google — OAuth, gated by AuthProviders::googleConfig()['allowed_emails']
 *
 * `admin_users` is the single source of truth for who can log in. A row is
 * valid when at least one of {username, github_id, google_sub} is non-null.
 * The same row may carry multiple methods (e.g. a local password AND a linked
 * GitHub account); whichever succeeded keys the session by `admin_users.id`.
 *
 * Provider availability comes from AuthProviders (settings-table backed).
 * The login UI hides any provider that isn't enabled+configured; this class's
 * methods refuse to act on a disabled provider as a defense-in-depth check.
 */
final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'httponly' => true,
                'secure'   => self::isSecureRequest(),
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    /**
     * True when the user-facing request is HTTPS, including the case where
     * TLS is terminated at an upstream proxy/load balancer (Bluehost,
     * Cloudflare, etc.) and only X-Forwarded-Proto reflects the real scheme.
     */
    public static function isSecureRequest(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (strcasecmp($forwardedProto, 'https') === 0) {
            return true;
        }
        if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        return false;
    }

    public static function isLoggedIn(): bool
    {
        self::start();
        return !empty($_SESSION['admin_id']);
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: ' . SITE_URL . '/admin/login.php');
            exit;
        }
        // Authenticated admin pages must never be cached by browsers, proxies,
        // or CDNs. Without these headers, an admin on a shared computer who
        // logs out leaves their last-rendered page in the browser back-button
        // cache; the next user can hit Back and see admin data without
        // re-authenticating. Also defends against a CDN being misconfigured to
        // cache /admin/ responses.
        if (!headers_sent()) {
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }
    }

    public static function getUser(): ?array
    {
        if (!self::isLoggedIn()) return null;
        return $_SESSION['admin_user'] ?? null;
    }

    public static function logout(): void
    {
        self::start();
        if (class_exists('ActivityLog') && self::isLoggedIn()) {
            ActivityLog::log('auth.logout');
        }
        $_SESSION = [];
        session_destroy();
    }

    // -- Local login (username + password) -----------------------------------

    /** After this many failed attempts inside RATE_LIMIT_WINDOW_SEC, an IP is locked out. */
    public const RATE_LIMIT_MAX_FAILURES = 5;
    /** Sliding window for the failure counter (10 minutes). */
    public const RATE_LIMIT_WINDOW_SEC   = 600;

    /** Login result statuses returned by loginLocal. */
    public const LOGIN_OK        = 'ok';        // session established, fully logged in
    public const LOGIN_NEEDS_2FA = 'needs_2fa'; // password OK, awaiting TOTP challenge
    public const LOGIN_FAILED    = 'failed';

    /**
     * Verify a local password and either establish the session (no 2FA), stash
     * a pending-2FA marker (2FA enabled, challenge required), or fail.
     *
     * Rate limiting: failed attempts are logged per IP. Once an IP hits
     * RATE_LIMIT_MAX_FAILURES inside the sliding RATE_LIMIT_WINDOW_SEC
     * window, further attempts are refused without even checking the password.
     * A successful login clears the IP's failure rows. OAuth flows bypass
     * this — they're rate-limited by GitHub/Google.
     *
     * @return string One of LOGIN_OK / LOGIN_NEEDS_2FA / LOGIN_FAILED.
     */
    public static function loginLocal(string $username, string $password): string
    {
        if (!AuthProviders::isLocalEnabled()) {
            return self::LOGIN_FAILED;
        }
        $username = trim($username);
        if ($username === '' || $password === '') {
            return self::LOGIN_FAILED;
        }

        $ip = self::clientIp();
        if (self::isRateLimited($ip)) {
            // Don't even check the password. Log this attempt so persistent
            // attackers stretch their own lockout further.
            self::recordFailedAttempt($ip, $username);
            ActivityLog::log('auth.login_failure', null, null, ['reason' => 'rate_limited', 'username' => $username]);
            return self::LOGIN_FAILED;
        }

        $row = Database::fetchOne(
            "SELECT id, username, password_hash, totp_enabled, name, email, avatar_url
               FROM admin_users
              WHERE username = ?
              LIMIT 1",
            [$username]
        );
        if (!$row || empty($row['password_hash'])) {
            // Avoid leaking "user doesn't exist" vs "no password set" via timing —
            // run a dummy verify so both paths take ~equivalent time.
            password_verify($password, '$2y$10$' . str_repeat('A', 53));
            self::recordFailedAttempt($ip, $username);
            ActivityLog::log('auth.login_failure', null, null, ['reason' => 'unknown_user_or_no_password', 'username' => $username]);
            return self::LOGIN_FAILED;
        }
        if (!password_verify($password, $row['password_hash'])) {
            self::recordFailedAttempt($ip, $username);
            ActivityLog::log('auth.login_failure', 'admin_user', (int)$row['id'], ['reason' => 'wrong_password', 'username' => $username]);
            return self::LOGIN_FAILED;
        }
        // Opportunistic rehash if PHP's default cost has moved.
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('admin_users', [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ], 'id = :id', ['id' => $row['id']]);
        }

        // Password is correct. If 2FA is enabled, stash a pending marker and
        // bounce to the challenge — DO NOT establish the session here.
        if ((int)$row['totp_enabled'] === 1) {
            self::start();
            // Regenerate session id even at the pending stage to prevent
            // session-fixation attacks during the 2FA window.
            session_regenerate_id(true);
            $_SESSION['pending_2fa_admin_id'] = (int)$row['id'];
            $_SESSION['pending_2fa_started_at'] = time();
            ActivityLog::log('auth.login_password_ok_awaiting_2fa', 'admin_user', (int)$row['id']);
            return self::LOGIN_NEEDS_2FA;
        }

        // No 2FA → complete the login now.
        self::completeLocalLogin((int)$row['id'], $row);
        return self::LOGIN_OK;
    }

    /**
     * Finalise a local login (post-password and, when applicable, post-TOTP).
     * Pulled out of loginLocal so the 2FA challenge handler can call it after
     * verifying the TOTP code without re-running the password check.
     *
     * Caller may pass the admin_users row to avoid a second lookup; if null,
     * we fetch it.
     */
    public static function completeLocalLogin(int $adminId, ?array $row = null): void
    {
        if ($row === null) {
            $row = Database::fetchOne(
                "SELECT id, username, name, email, avatar_url FROM admin_users WHERE id = ? LIMIT 1",
                [$adminId]
            );
            if (!$row) return;
        }

        Database::update('admin_users', [
            'last_login' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $adminId]);

        // Clear failure rows for this IP (legitimate user → reset their lockout counter).
        self::clearFailedAttempts(self::clientIp());

        // Drop any pending-2FA marker now that we're fully logged in.
        self::start();
        unset($_SESSION['pending_2fa_admin_id'], $_SESSION['pending_2fa_started_at']);

        self::establishSession($adminId, [
            'id'     => $adminId,
            'login'  => $row['username'] ?? '',
            'name'   => $row['name']     ?: ($row['username'] ?? ''),
            'email'  => $row['email']    ?? null,
            'avatar' => $row['avatar_url'] ?? null,
        ]);
        ActivityLog::log('auth.login_success', 'admin_user', $adminId, ['method' => 'local']);
    }

    /** Returns the admin id awaiting a 2FA challenge, or null when none is pending. */
    public static function pending2faAdminId(): ?int
    {
        self::start();
        if (empty($_SESSION['pending_2fa_admin_id'])) return null;
        // 5-minute ceiling: stale pending markers (browser closed mid-challenge)
        // shouldn't linger indefinitely.
        $started = (int)($_SESSION['pending_2fa_started_at'] ?? 0);
        if ($started > 0 && (time() - $started) > 300) {
            unset($_SESSION['pending_2fa_admin_id'], $_SESSION['pending_2fa_started_at']);
            return null;
        }
        return (int)$_SESSION['pending_2fa_admin_id'];
    }

    /** Clear the pending-2FA marker without completing the login (used by cancel/back). */
    public static function abortPending2fa(): void
    {
        self::start();
        unset($_SESSION['pending_2fa_admin_id'], $_SESSION['pending_2fa_started_at']);
    }

    /**
     * The login_attempts table ships in migration 018. On installs where the
     * migration hasn't run yet, the rate-limit helpers must fail open rather
     * than lock everyone out — but we want to swallow ONLY that specific
     * condition. Any other PDO error (connection lost, syntax error, etc.) is
     * a real bug and should propagate so it surfaces in error logs instead of
     * silently disabling the rate limit.
     *
     * SQLSTATE 42S02 = "Base table or view not found" (the ANSI code MySQL
     * returns for missing-table errors).
     */
    private static function isMissingLoginAttemptsTable(\PDOException $e): bool
    {
        $sqlstate = $e->getCode();
        if ($sqlstate === '42S02') return true;
        // Some PDO drivers leave $code as 0 and put the SQLSTATE in errorInfo[0].
        $info = $e->errorInfo ?? null;
        return is_array($info) && ($info[0] ?? '') === '42S02';
    }

    /**
     * True when the calling IP has exceeded RATE_LIMIT_MAX_FAILURES failed
     * local-login attempts in the last RATE_LIMIT_WINDOW_SEC seconds.
     */
    public static function isRateLimited(string $ip): bool
    {
        if ($ip === '') return false;
        try {
            $row = Database::fetchOne(
                "SELECT COUNT(*) AS c
                   FROM login_attempts
                  WHERE ip_address = ?
                    AND attempted_at >= (NOW() - INTERVAL ? SECOND)",
                [$ip, self::RATE_LIMIT_WINDOW_SEC]
            );
        } catch (\PDOException $e) {
            if (self::isMissingLoginAttemptsTable($e)) return false;
            throw $e;
        }
        return (int)$row['c'] >= self::RATE_LIMIT_MAX_FAILURES;
    }

    /**
     * Seconds remaining before $ip's failure count drops below the threshold.
     * Returns 0 when the IP isn't currently rate-limited. Useful for the
     * "try again in N seconds" message on the login page.
     */
    public static function rateLimitRetryAfter(string $ip): int
    {
        if ($ip === '') return 0;
        try {
            $row = Database::fetchOne(
                "SELECT UNIX_TIMESTAMP(MAX(attempted_at)) AS latest
                   FROM (SELECT attempted_at FROM login_attempts
                          WHERE ip_address = ?
                          ORDER BY attempted_at DESC
                          LIMIT ?) AS recent",
                [$ip, self::RATE_LIMIT_MAX_FAILURES]
            );
        } catch (\PDOException $e) {
            if (self::isMissingLoginAttemptsTable($e)) return 0;
            throw $e;
        }
        if (empty($row['latest'])) return 0;
        $expiresAt = (int)$row['latest'] + self::RATE_LIMIT_WINDOW_SEC;
        return max(0, $expiresAt - time());
    }

    private static function recordFailedAttempt(string $ip, string $username): void
    {
        if ($ip === '') return;
        try {
            Database::insert('login_attempts', [
                'ip_address' => substr($ip, 0, 45),
                'username'   => substr($username, 0, 64) ?: null,
            ]);
        } catch (\PDOException $e) {
            if (!self::isMissingLoginAttemptsTable($e)) throw $e;
            // Table missing — silently drop so login still works pre-migration.
        }
    }

    private static function clearFailedAttempts(string $ip): void
    {
        if ($ip === '') return;
        try {
            Database::delete('login_attempts', 'ip_address = ?', [$ip]);
        } catch (\PDOException $e) {
            if (!self::isMissingLoginAttemptsTable($e)) throw $e;
        }
    }

    /**
     * Client IP for rate-limiting + activity logging.
     *
     * X-Forwarded-For is only honored when REMOTE_ADDR is a trusted reverse
     * proxy (loopback by default; extend via the TRUSTED_PROXIES env var).
     * Without this check an attacker can forge XFF on a direct-exposed host
     * and trivially bypass per-IP rate limits.
     */
    public static function clientIp(): string
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remote === '' || !self::isTrustedProxyIp($remote)) {
            return $remote;
        }
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($fwd !== '') {
            $first = trim(explode(',', $fwd)[0]);
            if ($first !== '') return $first;
        }
        return $remote;
    }

    /**
     * True when $ip is in the trusted-proxy list. Loopback (127.0.0.1, ::1)
     * is always trusted; additional IPs come from the TRUSTED_PROXIES constant
     * (comma-separated). Public + static for unit testing.
     */
    public static function isTrustedProxyIp(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1') return true;
        $env = defined('TRUSTED_PROXIES') ? (string)TRUSTED_PROXIES : '';
        if ($env === '') return false;
        foreach (explode(',', $env) as $extra) {
            if (trim($extra) === $ip) return true;
        }
        return false;
    }

    // -- GitHub OAuth --------------------------------------------------------

    public static function getGitHubAuthUrl(): string
    {
        $cfg = AuthProviders::githubConfig();
        if (!$cfg) {
            throw new RuntimeException('GitHub OAuth is not enabled or not configured.');
        }
        $state = bin2hex(random_bytes(16));
        self::start();
        $_SESSION['oauth_state']    = $state;
        $_SESSION['oauth_provider'] = AuthProviders::GITHUB;

        return 'https://github.com/login/oauth/authorize?' . http_build_query([
            'client_id'    => $cfg['client_id'],
            'redirect_uri' => $cfg['redirect_uri'],
            'scope'        => 'read:user user:email',
            'state'        => $state,
        ]);
    }

    public static function handleGitHubCallback(string $code, string $state): bool
    {
        $cfg = AuthProviders::githubConfig();
        if (!$cfg) return false;

        self::start();
        if ($state === '' || $state !== ($_SESSION['oauth_state'] ?? '')) {
            return false;
        }
        if (($_SESSION['oauth_provider'] ?? '') !== AuthProviders::GITHUB) {
            return false;
        }
        unset($_SESSION['oauth_state'], $_SESSION['oauth_provider']);

        $tokenData = self::httpPost('https://github.com/login/oauth/access_token', [
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'code'          => $code,
            'redirect_uri'  => $cfg['redirect_uri'],
        ], ['Accept: application/json']);
        if (empty($tokenData['access_token'])) {
            return false;
        }

        $githubUser = self::httpGetBearer('https://api.github.com/user', $tokenData['access_token']);
        if (empty($githubUser['login']) || empty($githubUser['id'])) {
            return false;
        }
        $login = (string)$githubUser['login'];
        if (!in_array($login, $cfg['allowed_users'], true)) {
            return false;
        }
        return self::loginViaGithub($githubUser);
    }

    private static function loginViaGithub(array $githubUser): bool
    {
        $githubId = (string)$githubUser['id'];
        $name     = $githubUser['name'] ?? $githubUser['login'];
        $avatar   = $githubUser['avatar_url'] ?? null;
        $email    = $githubUser['email']      ?? null;

        $existing = Database::fetchOne(
            "SELECT id, username, name, email, avatar_url FROM admin_users WHERE github_id = ? LIMIT 1",
            [$githubId]
        );
        if ($existing) {
            $userId = (int)$existing['id'];
            Database::update('admin_users', [
                'name'       => $name,
                'avatar_url' => $avatar,
                'email'      => $existing['email'] ?: $email,
                'last_login' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $userId]);
        } else {
            $userId = Database::insert('admin_users', [
                'github_id'  => $githubId,
                'email'      => $email,
                'name'       => $name,
                'avatar_url' => $avatar,
            ]);
        }

        self::establishSession($userId, [
            'id'     => $userId,
            'login'  => $githubUser['login'],
            'name'   => $name,
            'email'  => $email,
            'avatar' => $avatar,
        ]);
        ActivityLog::log('auth.login_success', 'admin_user', $userId, ['method' => 'github', 'github_login' => $githubUser['login']]);
        return true;
    }

    // -- Google OAuth --------------------------------------------------------

    public static function getGoogleAuthUrl(): string
    {
        $cfg = AuthProviders::googleConfig();
        if (!$cfg) {
            throw new RuntimeException('Google OAuth is not enabled or not configured.');
        }
        $state = bin2hex(random_bytes(16));
        self::start();
        $_SESSION['oauth_state']    = $state;
        $_SESSION['oauth_provider'] = AuthProviders::GOOGLE;

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $cfg['client_id'],
            'redirect_uri'  => $cfg['redirect_uri'],
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ]);
    }

    public static function handleGoogleCallback(string $code, string $state): bool
    {
        $cfg = AuthProviders::googleConfig();
        if (!$cfg) return false;

        self::start();
        if ($state === '' || $state !== ($_SESSION['oauth_state'] ?? '')) {
            return false;
        }
        if (($_SESSION['oauth_provider'] ?? '') !== AuthProviders::GOOGLE) {
            return false;
        }
        unset($_SESSION['oauth_state'], $_SESSION['oauth_provider']);

        $tokenData = self::httpPost('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri'  => $cfg['redirect_uri'],
            'grant_type'    => 'authorization_code',
        ]);
        if (empty($tokenData['access_token'])) {
            return false;
        }

        $googleUser = self::httpGetBearer('https://openidconnect.googleapis.com/v1/userinfo', $tokenData['access_token']);
        if (empty($googleUser['sub']) || empty($googleUser['email'])) {
            return false;
        }
        // Allowlist check uses normalized lowercase email.
        $email = strtolower((string)$googleUser['email']);
        $allowed = array_map('strtolower', $cfg['allowed_emails']);
        if (!in_array($email, $allowed, true)) {
            return false;
        }
        if (!empty($googleUser['email_verified']) && $googleUser['email_verified'] !== true && $googleUser['email_verified'] !== 'true') {
            // Google should always return verified=true for the primary account
            // type we accept, but reject otherwise.
            return false;
        }
        return self::loginViaGoogle($googleUser);
    }

    private static function loginViaGoogle(array $googleUser): bool
    {
        $sub    = (string)$googleUser['sub'];
        $email  = strtolower((string)$googleUser['email']);
        $name   = $googleUser['name']    ?? $email;
        $avatar = $googleUser['picture'] ?? null;

        $existing = Database::fetchOne(
            "SELECT id, username, name, email, avatar_url FROM admin_users WHERE google_sub = ? LIMIT 1",
            [$sub]
        );
        if ($existing) {
            $userId = (int)$existing['id'];
            Database::update('admin_users', [
                'name'         => $name,
                'avatar_url'   => $avatar,
                'google_email' => $email,
                'email'        => $existing['email'] ?: $email,
                'last_login'   => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $userId]);
        } else {
            $userId = Database::insert('admin_users', [
                'google_sub'   => $sub,
                'google_email' => $email,
                'email'        => $email,
                'name'         => $name,
                'avatar_url'   => $avatar,
            ]);
        }

        self::establishSession($userId, [
            'id'     => $userId,
            'login'  => $email,
            'name'   => $name,
            'email'  => $email,
            'avatar' => $avatar,
        ]);
        ActivityLog::log('auth.login_success', 'admin_user', $userId, ['method' => 'google', 'google_email' => $email]);
        return true;
    }

    // -- Session + transport helpers ----------------------------------------

    private static function establishSession(int $adminId, array $userPayload): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['admin_id']   = $adminId;
        $_SESSION['admin_user'] = $userPayload;
    }

    private static function httpPost(string $url, array $data, array $extraHeaders = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => array_merge(
                ['Content-Type: application/x-www-form-urlencoded', 'User-Agent: PotteryPortfolio/1.0', 'Accept: application/json'],
                $extraHeaders
            ),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response === false) return [];
        return json_decode($response, true) ?? [];
    }

    private static function httpGetBearer(string $url, string $accessToken): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $accessToken",
                'User-Agent: PotteryPortfolio/1.0',
                'Accept: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response === false) return [];
        return json_decode($response, true) ?? [];
    }
}
