<?php
/**
 * Installer — core provisioning for a fresh deployment of the pottery template.
 *
 * Drives both the web wizard (public/install.php) and CLI (bin/install.php).
 * Methods are pure helpers: they take config in, perform the work, and either
 * return a structured result or throw RuntimeException. The entrypoints handle
 * I/O (HTML, prompts, stdout) and security gating (CSRF, marker checks).
 *
 * Why this class doesn't go through Database/MigrationRunner:
 *   Database.php is a static singleton that reads DB_HOST/DB_NAME/... constants
 *   at first connection. Those constants only exist once .env is written. The
 *   installer runs BEFORE .env exists, so it manages its own PDO connection
 *   and re-implements the small "apply pending migrations" loop using
 *   MigrationRunner::splitStatements() (which is static and reusable).
 */
final class Installer
{
    public const MARKER_FILE  = '.installed';
    public const ENV_FILENAME = '.env';

    /** Required PHP extensions; install fails closed if any are missing. */
    public const REQUIRED_EXTENSIONS = ['pdo', 'pdo_mysql', 'gd', 'curl', 'mbstring', 'zip', 'json'];

    /** Minimum PHP version. Matches composer.json's `php: >=8.0`. */
    public const MIN_PHP_VERSION = '8.0.0';

    private string $rootPath;

    public function __construct(string $rootPath)
    {
        $this->rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR . '/');
    }

    // -- Install state -------------------------------------------------------

    public function markerPath(): string
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . self::MARKER_FILE;
    }

    public function envPath(): string
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . self::ENV_FILENAME;
    }

    public function isInstalled(): bool
    {
        return file_exists($this->markerPath());
    }

    public function markInstalled(string $notes = ''): void
    {
        $payload = "Installed at: " . date('c') . "\n";
        if ($notes !== '') {
            $payload .= "Notes: $notes\n";
        }
        $payload .= "Delete this file to allow the installer to run again.\n";
        if (file_put_contents($this->markerPath(), $payload) === false) {
            throw new RuntimeException('Could not write install marker at ' . $this->markerPath());
        }
    }

    // -- Prereq checks -------------------------------------------------------

    /**
     * @return array{
     *   php_ok: bool, php_version: string,
     *   extensions: array<string, bool>, missing_extensions: string[],
     *   paths: array<string, array{path:string, exists:bool, writable:bool}>,
     *   all_ok: bool
     * }
     */
    public function checkPrereqs(): array
    {
        $phpOk = version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=');

        $exts = [];
        $missing = [];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $exts[$ext] = $loaded;
            if (!$loaded) {
                $missing[] = $ext;
            }
        }

        $paths = [
            'project_root' => $this->describePath($this->rootPath),
            'uploads'      => $this->describePath($this->rootPath . '/public/uploads'),
            'env_file'     => $this->describeEnvWritability(),
        ];

        $pathsOk = $paths['project_root']['writable']
                && $paths['uploads']['writable']
                && $paths['env_file']['writable'];

        return [
            'php_ok'             => $phpOk,
            'php_version'        => PHP_VERSION,
            'extensions'         => $exts,
            'missing_extensions' => $missing,
            'paths'              => $paths,
            'all_ok'             => $phpOk && !$missing && $pathsOk,
        ];
    }

    private function describePath(string $path): array
    {
        $exists   = file_exists($path);
        $writable = $exists ? is_writable($path) : is_writable(dirname($path));
        return ['path' => $path, 'exists' => $exists, 'writable' => $writable];
    }

    private function describeEnvWritability(): array
    {
        $env = $this->envPath();
        if (file_exists($env)) {
            return ['path' => $env, 'exists' => true, 'writable' => is_writable($env)];
        }
        // Not yet present — must be able to create it in the root dir.
        return ['path' => $env, 'exists' => false, 'writable' => is_writable($this->rootPath)];
    }

    // -- Database ------------------------------------------------------------

    /**
     * @param array{host:string,name:string,user:string,pass:string,charset?:string} $cfg
     * @return array{ok:bool, error:?string, server_version:?string}
     */
    public function testDbConnection(array $cfg): array
    {
        try {
            $pdo = $this->buildPdo($cfg);
            $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            return ['ok' => true, 'error' => null, 'server_version' => $version];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'server_version' => null];
        }
    }

    /**
     * @param array{host:string,name:string,user:string,pass:string,charset?:string} $cfg
     */
    public function buildPdo(array $cfg): PDO
    {
        $charset = $cfg['charset'] ?? 'utf8mb4';
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['name'], $charset);
        return new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    /**
     * Run sql/init.sql against the given PDO. Returns the number of statements
     * executed. Safe to call against an empty DB; init.sql uses CREATE TABLE IF
     * NOT EXISTS so it will skip pre-existing tables (which may have stale
     * schema — see CLAUDE.md's pitfall on re-running init.sql).
     */
    public function initializeSchema(PDO $pdo, ?string $initSqlPath = null): array
    {
        $path = $initSqlPath ?? $this->rootPath . '/sql/init.sql';
        if (!is_file($path)) {
            throw new RuntimeException('init.sql not found at ' . $path);
        }
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Could not read ' . $path);
        }

        $statements = MigrationRunner::splitStatements($sql);
        foreach ($statements as $stmt) {
            $pdo->exec($stmt);
        }
        return ['statements_run' => count($statements)];
    }

    /**
     * Discover sql/NNN_*.sql, apply each one that isn't recorded in
     * schema_migrations, and record the applied version. Returns the list of
     * versions applied (in order).
     *
     * @return array{applied:string[], skipped:string[]}
     */
    public function applyMigrations(PDO $pdo, ?string $sqlDir = null): array
    {
        $sqlDir ??= $this->rootPath . '/sql';
        $this->ensureMigrationLedger($pdo);

        $available = $this->discoverMigrations($sqlDir);
        $applied   = $this->ledgerVersions($pdo);

        $ranNow = [];
        $skipped = [];
        foreach ($available as $version) {
            if (in_array($version, $applied, true)) {
                $skipped[] = $version;
                continue;
            }
            $sql = file_get_contents($sqlDir . DIRECTORY_SEPARATOR . $version);
            if ($sql === false) {
                throw new RuntimeException("Could not read migration $version");
            }
            foreach (MigrationRunner::splitStatements($sql) as $stmt) {
                $pdo->exec($stmt);
            }
            $stmt = $pdo->prepare(
                "INSERT INTO schema_migrations (version, source, notes)
                 VALUES (?, 'run', 'applied by Installer')"
            );
            $stmt->execute([$version]);
            $ranNow[] = $version;
        }
        return ['applied' => $ranNow, 'skipped' => $skipped];
    }

    private function ensureMigrationLedger(PDO $pdo): void
    {
        // Matches the schema in includes/MigrationRunner::ensureLedger().
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                version     VARCHAR(255) PRIMARY KEY,
                applied_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                applied_by  INT NULL,
                source      ENUM('run','mark') NOT NULL DEFAULT 'run',
                notes       TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function discoverMigrations(string $sqlDir): array
    {
        if (!is_dir($sqlDir)) {
            return [];
        }
        $out = [];
        foreach (scandir($sqlDir) ?: [] as $entry) {
            if (preg_match('/^\d+_[A-Za-z0-9_\-]+\.sql$/', $entry)) {
                $out[] = $entry;
            }
        }
        sort($out);
        return $out;
    }

    /** @return string[] */
    private function ledgerVersions(PDO $pdo): array
    {
        $rows = $pdo->query("SELECT version FROM schema_migrations")->fetchAll();
        return array_column($rows, 'version');
    }

    // -- Local admin user ----------------------------------------------------

    /**
     * Create (or update) a local-login admin row in `admin_users`. Returns the
     * admin's primary key. Re-running with the same username updates the
     * password hash + email rather than throwing — this keeps the installer
     * re-runnable after a botched first attempt.
     */
    public function createLocalAdmin(PDO $pdo, string $username, string $password, ?string $email = null): int
    {
        $username = trim($username);
        if (!self::isValidUsername($username)) {
            throw new RuntimeException('Invalid username (must be 3–64 chars, alphanumeric/underscore).');
        }
        if (!self::isValidPassword($password)) {
            throw new RuntimeException('Password must be at least 12 characters.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $emailVal = ($email !== null && $email !== '') ? trim($email) : null;

        $stmt = $pdo->prepare("SELECT id FROM admin_users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $existing = $stmt->fetch();
        if ($existing) {
            $upd = $pdo->prepare(
                "UPDATE admin_users
                    SET password_hash = ?, email = COALESCE(?, email)
                  WHERE id = ?"
            );
            $upd->execute([$hash, $emailVal, $existing['id']]);
            return (int)$existing['id'];
        }
        $ins = $pdo->prepare(
            "INSERT INTO admin_users (username, password_hash, email, name)
             VALUES (?, ?, ?, ?)"
        );
        $ins->execute([$username, $hash, $emailVal, $username]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Write the credentials for an OAuth provider into the settings table and
     * flip its `*_enabled` toggle to '1'. Provider must be 'github' or 'google'.
     *
     * @param array{client_id:string, client_secret:string, allowed:string} $cfg
     *        `allowed` is the comma-separated allowlist (usernames for GitHub,
     *        emails for Google).
     */
    public function seedAuthProvider(PDO $pdo, string $provider, array $cfg): void
    {
        $map = [
            'github' => [
                'client_id'     => 'auth_github_client_id',
                'client_secret' => 'auth_github_client_secret',
                'allowed'       => 'auth_github_allowed_users',
                'enabled'       => 'auth_github_enabled',
            ],
            'google' => [
                'client_id'     => 'auth_google_client_id',
                'client_secret' => 'auth_google_client_secret',
                'allowed'       => 'auth_google_allowed_emails',
                'enabled'       => 'auth_google_enabled',
            ],
        ];
        if (!isset($map[$provider])) {
            throw new RuntimeException("Unknown auth provider: $provider");
        }
        $keys = $map[$provider];
        $upsert = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $upsert->execute([$keys['client_id'],     trim((string)($cfg['client_id']     ?? ''))]);
        $upsert->execute([$keys['client_secret'], trim((string)($cfg['client_secret'] ?? ''))]);
        $upsert->execute([$keys['allowed'],       trim((string)($cfg['allowed']       ?? ''))]);
        $upsert->execute([$keys['enabled'],       '1']);
    }

    /** Set the master toggle for any provider ('local'|'github'|'google') without changing credentials. */
    public function setProviderEnabled(PDO $pdo, string $provider, bool $enabled): void
    {
        $map = ['local' => 'auth_local_enabled', 'github' => 'auth_github_enabled', 'google' => 'auth_google_enabled'];
        if (!isset($map[$provider])) {
            throw new RuntimeException("Unknown auth provider: $provider");
        }
        $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([$map[$provider], $enabled ? '1' : '0']);
    }

    // -- Branding ------------------------------------------------------------

    /**
     * UPSERT branding rows into the `settings` table.
     *
     * @param array<string,string> $values e.g. ['site_name' => 'My Pottery', 'tagline' => '...']
     */
    public function seedBranding(PDO $pdo, array $values): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        foreach ($values as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $stmt->execute([(string)$key, (string)$value]);
        }
    }

    // -- .env writer ---------------------------------------------------------

    /**
     * Render an .env file from $values. Renders deterministically so the
     * output is stable across runs; secrets are written exactly as supplied.
     *
     * @param array<string,string> $values
     */
    public function writeEnvFile(array $values, bool $force = false): void
    {
        $path = $this->envPath();
        if (file_exists($path) && !$force) {
            throw new RuntimeException(self::ENV_FILENAME . ' already exists at ' . $path . ' — refuse to overwrite without force=true.');
        }
        $content = self::renderEnv($values);
        // Write atomically so a crashed write doesn't leave a half-written file.
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new RuntimeException('Could not write ' . $tmp);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not move ' . $tmp . ' to ' . $path);
        }
        @chmod($path, 0640);
    }

    /**
     * Render the .env body. Public + static so InstallerTest can verify the
     * format without filesystem access.
     */
    public static function renderEnv(array $v): string
    {
        $sections = [
            'Database' => [
                'DB_HOST' => $v['DB_HOST'] ?? 'localhost',
                'DB_NAME' => $v['DB_NAME'] ?? '',
                'DB_USER' => $v['DB_USER'] ?? '',
                'DB_PASS' => $v['DB_PASS'] ?? '',
            ],
            'Site' => [
                'SITE_URL' => $v['SITE_URL'] ?? '',
            ],
            'GitHub OAuth (required)' => [
                'GITHUB_CLIENT_ID'     => $v['GITHUB_CLIENT_ID'] ?? '',
                'GITHUB_CLIENT_SECRET' => $v['GITHUB_CLIENT_SECRET'] ?? '',
                'ALLOWED_GITHUB_USERS' => $v['ALLOWED_GITHUB_USERS'] ?? '',
            ],
            'Stripe (optional)' => [
                'STRIPE_PUBLISHABLE_KEY' => $v['STRIPE_PUBLISHABLE_KEY'] ?? '',
                'STRIPE_SECRET_KEY'      => $v['STRIPE_SECRET_KEY'] ?? '',
                'STRIPE_WEBHOOK_SECRET'  => $v['STRIPE_WEBHOOK_SECRET'] ?? '',
            ],
            'Instagram (optional)' => [
                'INSTAGRAM_BUSINESS_ACCOUNT_ID' => $v['INSTAGRAM_BUSINESS_ACCOUNT_ID'] ?? '',
                'INSTAGRAM_ACCESS_TOKEN'        => $v['INSTAGRAM_ACCESS_TOKEN'] ?? '',
            ],
        ];

        $out = "# Generated by Installer on " . date('c') . "\n";
        foreach ($sections as $title => $pairs) {
            $out .= "\n# {$title}\n";
            foreach ($pairs as $k => $val) {
                $out .= $k . '=' . self::quoteEnvValue((string)$val) . "\n";
            }
        }
        return $out;
    }

    /**
     * Wrap a value in double quotes if it contains characters that need
     * escaping in a dotenv file (whitespace, #, =, $). Empty values are left
     * unquoted. Embedded double-quotes get backslash-escaped.
     */
    public static function quoteEnvValue(string $val): string
    {
        if ($val === '') {
            return '';
        }
        if (preg_match('/[\s#"\'$=\\\\]/', $val)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $val) . '"';
        }
        return $val;
    }

    // -- Auth bootstrap (one-time .env → settings migration) -----------------

    /**
     * For installs that pre-date the multi-provider auth refactor, copy GitHub
     * OAuth credentials from .env into the auth_* settings rows. Only writes
     * to a setting row that is currently empty — safe to re-run on every boot.
     *
     * If all three GitHub creds end up populated, also flips
     * `auth_github_enabled` to '1' (but only if it's currently '0', so the
     * admin can disable GitHub later without it being re-enabled on next boot).
     *
     * @return array{written:string[]} Setting keys that were populated this call.
     */
    public function bootstrapAuthFromEnv(PDO $pdo): array
    {
        $envPath = $this->envPath();
        if (!is_file($envPath)) {
            return ['written' => []];
        }
        $env = self::parseEnvFile($envPath);

        $map = [
            'GITHUB_CLIENT_ID'     => 'auth_github_client_id',
            'GITHUB_CLIENT_SECRET' => 'auth_github_client_secret',
            'ALLOWED_GITHUB_USERS' => 'auth_github_allowed_users',
        ];

        $select = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $upsert = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );

        $written = [];
        foreach ($map as $envKey => $settingKey) {
            $envVal = trim((string)($env[$envKey] ?? ''));
            if ($envVal === '') continue;

            $select->execute([$settingKey]);
            $currentRow = $select->fetch();
            $current = $currentRow ? trim((string)$currentRow['setting_value']) : '';
            if ($current !== '') continue;   // already populated — don't overwrite

            $upsert->execute([$settingKey, $envVal]);
            $written[] = $settingKey;
        }

        if (count($written) === 3) {
            $select->execute(['auth_github_enabled']);
            $row = $select->fetch();
            $cur = $row ? trim((string)$row['setting_value']) : '0';
            if ($cur === '0') {
                $upsert->execute(['auth_github_enabled', '1']);
                $written[] = 'auth_github_enabled';
            }
        }

        return ['written' => $written];
    }

    /**
     * Minimal .env parser — just enough to extract KEY=VALUE pairs for the
     * bootstrap step. Doesn't pretend to be a full dotenv impl (no nested
     * substitution, no export prefix); strips surrounding double quotes and
     * handles backslash-escaped quotes the way Installer::writeEnvFile writes
     * them.
     *
     * @return array<string,string>
     */
    public static function parseEnvFile(string $path): array
    {
        $out = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = ltrim($line);
            if ($line === '' || $line[0] === '#') continue;
            $eq = strpos($line, '=');
            if ($eq === false) continue;
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            // Strip surrounding double quotes (matches writeEnvFile's output).
            if (strlen($val) >= 2 && $val[0] === '"' && $val[strlen($val)-1] === '"') {
                $val = substr($val, 1, -1);
                $val = str_replace(['\\"', '\\\\'], ['"', '\\'], $val);
            }
            $out[$key] = $val;
        }
        return $out;
    }

    // -- Self-deletion -------------------------------------------------------

    /**
     * Recursively delete $dir (files first, then the directory itself). Used
     * by the web wizard to remove its own folder on success.
     *
     * Best-effort: returns the list of paths that couldn't be removed instead
     * of throwing. On Linux/mod_php the script can safely unlink itself
     * mid-execution (the inode persists until the process closes the file
     * descriptor). On Windows + locked-down hosts the operation may fail
     * partially — the caller is responsible for surfacing that to the user.
     *
     * Refuses to operate on a path that doesn't end in `/install` (case-
     * sensitive). This is a defensive sanity check so a future caller can't
     * accidentally wipe the whole project root.
     *
     * @return array{deleted:bool, failed_paths:string[]}
     */
    public static function removeInstallerDir(string $dir): array
    {
        $dir = rtrim($dir, DIRECTORY_SEPARATOR . '/');
        if (basename($dir) !== 'install') {
            throw new RuntimeException("removeInstallerDir refuses to delete '$dir' — basename must be 'install'.");
        }
        if (!is_dir($dir)) {
            return ['deleted' => true, 'failed_paths' => []];
        }
        $failed = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $path) {
            $p  = (string)$path;
            $ok = $path->isDir() ? @rmdir($p) : @unlink($p);
            if (!$ok) {
                $failed[] = $p;
            }
        }
        if (!@rmdir($dir)) {
            $failed[] = $dir;
        }
        return ['deleted' => empty($failed), 'failed_paths' => $failed];
    }

    // -- Validators ----------------------------------------------------------

    public static function isValidDbHost(string $v): bool
    {
        // Hostname, IPv4, or "host:port"; reject control chars + spaces.
        return $v !== '' && preg_match('/^[A-Za-z0-9._:\-]+$/', $v) === 1;
    }

    public static function isValidDbIdent(string $v): bool
    {
        // MySQL identifier: letters, digits, _, $. 64-char max.
        return $v !== '' && strlen($v) <= 64 && preg_match('/^[A-Za-z0-9_$]+$/', $v) === 1;
    }

    public static function isValidUrl(string $v): bool
    {
        if ($v === '') return false;
        if (filter_var($v, FILTER_VALIDATE_URL) === false) return false;
        $scheme = parse_url($v, PHP_URL_SCHEME);
        return $scheme === 'http' || $scheme === 'https';
    }

    public static function isValidEmail(string $v): bool
    {
        return $v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Local admin username: 3–64 chars, [A-Za-z0-9_] (no leading digit needed). */
    public static function isValidUsername(string $v): bool
    {
        $len = strlen($v);
        return $len >= 3 && $len <= 64 && preg_match('/^[A-Za-z0-9_]+$/', $v) === 1;
    }

    /** Local admin password: at least 12 chars, must not start/end with whitespace. */
    public static function isValidPassword(string $v): bool
    {
        if (strlen($v) < 12) return false;
        if ($v !== ltrim($v) || $v !== rtrim($v)) return false;
        return true;
    }

    /** Email allowlist (Google): comma/space separated list of valid emails. */
    public static function isValidAllowedEmails(string $v): bool
    {
        $list = self::parseAllowedUsers($v);  // reuse the same splitter
        if (empty($list)) return false;
        foreach ($list as $email) {
            if (!self::isValidEmail($email)) return false;
        }
        return true;
    }

    public static function isValidGithubUsername(string $v): bool
    {
        // GitHub usernames: 1-39 chars, alphanumeric or hyphen, no leading/
        // trailing hyphen, no consecutive hyphens.
        if ($v === '' || strlen($v) > 39) return false;
        return preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/', $v) === 1;
    }

    /** Comma- or whitespace-separated list of GitHub usernames. */
    public static function parseAllowedUsers(string $v): array
    {
        $tokens = preg_split('/[\s,]+/', trim($v)) ?: [];
        return array_values(array_filter($tokens, fn($t) => $t !== ''));
    }

    public static function isValidAllowedUsers(string $v): bool
    {
        $users = self::parseAllowedUsers($v);
        if (empty($users)) return false;
        foreach ($users as $u) {
            if (!self::isValidGithubUsername($u)) return false;
        }
        return true;
    }
}
