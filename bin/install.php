<?php
/**
 * CLI installer — same Installer core as public/install/index.php, no browser needed.
 *
 *   # Interactive
 *   php bin/install.php
 *
 *   # Non-interactive (Docker entrypoint, CI, scripted provisioning).
 *   # Local admin is REQUIRED; OAuth providers are optional.
 *   php bin/install.php --non-interactive \
 *       --db-host=db --db-name=pottery_portfolio \
 *       --db-user=pottery --db-pass=potterypw \
 *       --site-url=http://localhost:8088 \
 *       --site-name="My Pottery" --tagline="Handcrafted" \
 *       --admin-username=me --admin-password='supersecretpw12'
 *
 *   # With OAuth providers wired:
 *   php bin/install.php --non-interactive ... \
 *       --github-client-id=ID --github-client-secret=SEC --allowed-users="me,friend" \
 *       --google-client-id=ID --google-client-secret=SEC --allowed-google-emails="me@x.com"
 *
 *   # Override the marker safety check (USE WITH CARE — overwrites .env)
 *   php bin/install.php --force ...
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "bin/install.php must be run from the command line.\n");
    exit(2);
}

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/vendor/autoload.php';
require_once ROOT_PATH . '/includes/MigrationRunner.php';
require_once ROOT_PATH . '/includes/Installer.php';

$opts = getopt('', [
    'non-interactive', 'force', 'help',
    'db-host:', 'db-name:', 'db-user:', 'db-pass:',
    'site-url:', 'site-name:', 'tagline:', 'contact-email:',
    'admin-username:', 'admin-password:', 'admin-email:',
    'github-client-id:', 'github-client-secret:', 'allowed-users:',
    'google-client-id:', 'google-client-secret:', 'allowed-google-emails:',
    'stripe-pub:', 'stripe-secret:', 'stripe-webhook:',
]);

if (isset($opts['help'])) {
    usage();
    exit(0);
}

$installer = new Installer(ROOT_PATH);
$nonInteractive = isset($opts['non-interactive']);
$force          = isset($opts['force']);

if ($installer->isInstalled() && !$force) {
    fwrite(STDERR, "Already installed — marker exists at " . $installer->markerPath() . "\n");
    fwrite(STDERR, "Delete the marker to re-run, or pass --force.\n");
    exit(3);
}

// ---- Prereq check ---------------------------------------------------------
println("== Pottery installer (CLI) ==");
$checks = $installer->checkPrereqs();
println("PHP " . $checks['php_version'] . ' ' . tick($checks['php_ok']));
foreach ($checks['extensions'] as $name => $ok) {
    println("  ext $name " . tick($ok));
}
foreach ($checks['paths'] as $label => $p) {
    println("  path $label (" . $p['path'] . ') ' . tick($p['writable']));
}
if (!$checks['all_ok']) {
    fwrite(STDERR, "\nPrereq check failed. Fix the issues above and retry.\n");
    exit(4);
}

// ---- Collect values -------------------------------------------------------
println("\n== Database ==");
$db = [
    'host' => collect('db-host', 'DB host', 'localhost'),
    'name' => collect('db-name', 'DB name', 'pottery_portfolio'),
    'user' => collect('db-user', 'DB user'),
    'pass' => collect('db-pass', 'DB password', '', true),
];
if (!Installer::isValidDbHost($db['host']))  bail('DB host looks invalid.');
if (!Installer::isValidDbIdent($db['name'])) bail('DB name must be alphanumeric/_/$.');
if (!Installer::isValidDbIdent($db['user'])) bail('DB user must be alphanumeric/_/$.');

println("Testing connection...");
$test = $installer->testDbConnection($db);
if (!$test['ok']) {
    bail('Connection failed: ' . $test['error']);
}
println("Connected to " . $test['server_version'] . " " . tick(true));

println("\n== Site ==");
$site = [
    'SITE_URL'      => collect('site-url',      'Site URL (https://...)'),
    'site_name'     => collect('site-name',     'Site name'),
    'tagline'       => collect('tagline',       'Tagline', ''),
    'contact_email' => collect('contact-email', 'Contact email', ''),
];
if (!Installer::isValidUrl($site['SITE_URL'])) bail('Site URL must be a full http(s):// URL.');
if ($site['site_name'] === '')                 bail('Site name is required.');
if ($site['contact_email'] !== '' && !Installer::isValidEmail($site['contact_email'])) bail('Contact email is malformed.');

println("\n== Admin user ==");
$admin = [
    'username' => collect('admin-username', 'Admin username (3-64 chars, [A-Za-z0-9_])'),
    'password' => collect('admin-password', 'Admin password (min 12 chars)', '', true),
    'email'    => collect('admin-email',    'Admin email (optional)', ''),
];
if (!Installer::isValidUsername($admin['username'])) bail('Admin username must be 3-64 chars, alphanumeric/underscore only.');
if (!Installer::isValidPassword($admin['password'])) bail('Admin password must be at least 12 characters (no surrounding whitespace).');
if ($admin['email'] !== '' && !Installer::isValidEmail($admin['email'])) bail('Admin email is malformed.');

// OAuth providers — both optional. Enable a provider only when all three of
// its required values are supplied (interactive mode silently skips empty
// provider input; non-interactive only enables when explicitly opted in).
$githubCfg = null;
$ghClientId  = $opts['github-client-id']     ?? '';
$ghSecret    = $opts['github-client-secret'] ?? '';
$ghAllowed   = $opts['allowed-users']        ?? '';
if ($ghClientId !== '' || $ghSecret !== '' || $ghAllowed !== '' || !$nonInteractive) {
    println("\n== GitHub OAuth (optional — leave blank to skip) ==");
    println("Callback URL: " . rtrim($site['SITE_URL'], '/') . '/admin/auth/callback.php');
    $ghClientId  = $ghClientId  !== '' ? $ghClientId  : collect('github-client-id',     'GitHub Client ID',     '');
    $ghSecret    = $ghSecret    !== '' ? $ghSecret    : collect('github-client-secret', 'GitHub Client Secret', '', true);
    $ghAllowed   = $ghAllowed   !== '' ? $ghAllowed   : collect('allowed-users',        'Allowed GitHub usernames (comma-separated)', '');
    if ($ghClientId !== '' && $ghSecret !== '' && $ghAllowed !== '') {
        if (!Installer::isValidAllowedUsers($ghAllowed)) bail('Allowed GitHub usernames invalid.');
        $githubCfg = ['client_id' => $ghClientId, 'client_secret' => $ghSecret, 'allowed' => $ghAllowed];
    } elseif ($ghClientId !== '' || $ghSecret !== '' || $ghAllowed !== '') {
        bail('GitHub OAuth requires all three of client-id, client-secret, allowed-users (or leave all three blank to skip).');
    }
}

$googleCfg = null;
$gClientId = $opts['google-client-id']      ?? '';
$gSecret   = $opts['google-client-secret']  ?? '';
$gAllowed  = $opts['allowed-google-emails'] ?? '';
if ($gClientId !== '' || $gSecret !== '' || $gAllowed !== '' || !$nonInteractive) {
    println("\n== Google OAuth (optional — leave blank to skip) ==");
    println("Authorized redirect URI: " . rtrim($site['SITE_URL'], '/') . '/admin/auth/google-callback.php');
    $gClientId = $gClientId !== '' ? $gClientId : collect('google-client-id',      'Google Client ID',      '');
    $gSecret   = $gSecret   !== '' ? $gSecret   : collect('google-client-secret',  'Google Client Secret',  '', true);
    $gAllowed  = $gAllowed  !== '' ? $gAllowed  : collect('allowed-google-emails', 'Allowed Google emails (comma-separated)', '');
    if ($gClientId !== '' && $gSecret !== '' && $gAllowed !== '') {
        if (!Installer::isValidAllowedEmails($gAllowed)) bail('Allowed Google emails invalid.');
        $googleCfg = ['client_id' => $gClientId, 'client_secret' => $gSecret, 'allowed' => $gAllowed];
    } elseif ($gClientId !== '' || $gSecret !== '' || $gAllowed !== '') {
        bail('Google OAuth requires all three of client-id, client-secret, allowed-google-emails (or leave all three blank to skip).');
    }
}

// Optional Stripe — CLI only.
$stripe = [
    'STRIPE_PUBLISHABLE_KEY' => $opts['stripe-pub']     ?? '',
    'STRIPE_SECRET_KEY'      => $opts['stripe-secret']  ?? '',
    'STRIPE_WEBHOOK_SECRET'  => $opts['stripe-webhook'] ?? '',
];

// ---- Confirm + execute ----------------------------------------------------
if (!$nonInteractive) {
    println("\n== Ready to install ==");
    println("Will write .env, run sql/init.sql, apply migrations, create admin user '" . $admin['username'] . "', "
          . "enable: local" . ($githubCfg ? ', github' : '') . ($googleCfg ? ', google' : '') . ".");
    $go = readline("Proceed? [y/N] ");
    if (strtolower(trim((string)$go)) !== 'y') {
        bail('Aborted.', 0);
    }
}

try {
    $pdo = $installer->buildPdo($db);
    println("\n→ Initializing schema...");
    $init = $installer->initializeSchema($pdo);
    println("  " . $init['statements_run'] . " statements " . tick(true));

    println("→ Applying migrations...");
    $mig = $installer->applyMigrations($pdo);
    println("  applied " . count($mig['applied']) . ", skipped " . count($mig['skipped']) . " " . tick(true));

    println("→ Seeding branding...");
    $installer->seedBranding($pdo, [
        'site_name'     => $site['site_name'],
        'tagline'       => $site['tagline'],
        'contact_email' => $site['contact_email'],
    ]);
    println("  " . tick(true));

    println("→ Creating admin user '" . $admin['username'] . "'...");
    $adminId = $installer->createLocalAdmin($pdo, $admin['username'], $admin['password'], $admin['email'] ?: null);
    println("  id=$adminId " . tick(true));

    if ($githubCfg) {
        println("→ Enabling GitHub OAuth...");
        $installer->seedAuthProvider($pdo, 'github', $githubCfg);
        println("  " . tick(true));
    }
    if ($googleCfg) {
        println("→ Enabling Google OAuth...");
        $installer->seedAuthProvider($pdo, 'google', $googleCfg);
        println("  " . tick(true));
    }

    println("→ Writing .env...");
    $installer->writeEnvFile([
        'DB_HOST' => $db['host'], 'DB_NAME' => $db['name'], 'DB_USER' => $db['user'], 'DB_PASS' => $db['pass'],
        'SITE_URL' => $site['SITE_URL'],
        'GITHUB_CLIENT_ID'     => $githubCfg['client_id']     ?? '',
        'GITHUB_CLIENT_SECRET' => $githubCfg['client_secret'] ?? '',
        'ALLOWED_GITHUB_USERS' => $githubCfg['allowed']       ?? '',
    ] + $stripe, $force);
    println("  " . $installer->envPath() . ' ' . tick(true));

    println("→ Marking installed...");
    $installer->markInstalled('CLI');
    println("  " . $installer->markerPath() . ' ' . tick(true));

    println("\nInstall complete.");
    println("Sign in: " . rtrim($site['SITE_URL'], '/') . "/admin/login.php");
    println("Manage providers later at: " . rtrim($site['SITE_URL'], '/') . "/admin/settings/auth.php");
    println("Delete public/install/ on the server for defense-in-depth.");
    exit(0);
} catch (\Throwable $e) {
    bail("\nFailed: " . $e->getMessage());
}

// ---- Helpers --------------------------------------------------------------
function tick(bool $ok): string { return $ok ? '✓' : '✗'; }
function println(string $line = ''): void { fwrite(STDOUT, $line . PHP_EOL); }
function bail(string $msg, int $code = 1): never
{
    fwrite(STDERR, $msg . PHP_EOL);
    exit($code);
}

function collect(string $optName, string $label, string $default = '', bool $secret = false): string
{
    global $opts, $nonInteractive;
    if (isset($opts[$optName]) && $opts[$optName] !== false) {
        return (string)$opts[$optName];
    }
    if ($nonInteractive) {
        if ($default !== '') return $default;
        bail("Missing required --$optName (running in --non-interactive mode).");
    }
    $prompt = $label . ($default !== '' ? " [$default]" : '') . ': ';
    if ($secret) {
        $val = read_silent($prompt);
    } else {
        $val = readline($prompt);
    }
    $val = trim((string)$val);
    return $val === '' ? $default : $val;
}

function read_silent(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    if (DIRECTORY_SEPARATOR === '\\') {
        return (string)fgets(STDIN);
    }
    system('stty -echo');
    $val = (string)fgets(STDIN);
    system('stty echo');
    fwrite(STDOUT, PHP_EOL);
    return $val;
}

function usage(): void
{
    println(<<<TXT
Usage: php bin/install.php [options]

Modes:
  (no flag)            Interactive prompts.
  --non-interactive    Take all values from --options; fail if any required is missing.
  --force              Overwrite an existing .env and ignore the .installed marker.
  --help               This message.

Required (always):
  --db-host=HOST              Default: localhost
  --db-name=NAME              Default: pottery_portfolio
  --db-user=USER
  --db-pass=PASS
  --site-url=URL              Full https://… URL.
  --site-name=NAME
  --admin-username=NAME       3–64 chars, [A-Za-z0-9_].
  --admin-password=PASS       Min 12 chars.

Optional (always):
  --tagline=STR
  --contact-email=EMAIL
  --admin-email=EMAIL
  --stripe-pub=KEY, --stripe-secret=KEY, --stripe-webhook=KEY

GitHub OAuth (all three together, or none):
  --github-client-id=ID
  --github-client-secret=SEC
  --allowed-users=LIST        Comma-separated GitHub usernames.

Google OAuth (all three together, or none):
  --google-client-id=ID
  --google-client-secret=SEC
  --allowed-google-emails=LIST
TXT);
}
