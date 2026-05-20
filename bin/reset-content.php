<?php
/**
 * CLI driver for ContentReset — wipe author-specific content while preserving
 * schema, admin accounts, and auth configuration.
 *
 *   # Interactive (asks before each partition):
 *   php bin/reset-content.php
 *
 *   # Non-interactive (Docker / scripted). --force is required to bypass the prompt.
 *   php bin/reset-content.php --non-interactive --force
 *
 *   # Run only a subset (defaults: everything ON). Use --keep-* to skip a partition:
 *   php bin/reset-content.php --non-interactive --force --keep-uploads --keep-design
 *
 *   # Show what would be wiped without doing it:
 *   php bin/reset-content.php --dry-run
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "bin/reset-content.php must be run from the command line.\n");
    exit(2);
}

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/vendor/autoload.php';
if (file_exists(ROOT_PATH . '/.env')) {
    \Dotenv\Dotenv::createImmutable(ROOT_PATH)->safeLoad();
}
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/Database.php';
require_once ROOT_PATH . '/includes/PageSections.php';
require_once ROOT_PATH . '/includes/ContentReset.php';

$opts = getopt('', [
    'non-interactive', 'force', 'dry-run', 'help',
    'keep-content', 'keep-uploads', 'keep-branding',
    'keep-text-overrides', 'keep-email-overrides', 'keep-design',
]);

if (isset($opts['help'])) {
    usage();
    exit(0);
}

$nonInteractive = isset($opts['non-interactive']);
$force          = isset($opts['force']);
$dryRun         = isset($opts['dry-run']);

$selected = [
    'content'         => !isset($opts['keep-content']),
    'uploads'         => !isset($opts['keep-uploads']),
    'branding'        => !isset($opts['keep-branding']),
    'text_overrides'  => !isset($opts['keep-text-overrides']),
    'email_overrides' => !isset($opts['keep-email-overrides']),
    'design'          => !isset($opts['keep-design']),
];

println("== Pottery template content reset ==");
println();
println("This will wipe the following from the current install:");
foreach ($selected as $partition => $on) {
    println(sprintf('  [%s] %s — %s', $on ? '✓' : ' ', $partition, partitionSummary($partition)));
}
println();
println("PRESERVED: schema, admin_users, auth settings, schema_migrations ledger, shop_currency.");
println();

if (!array_filter($selected)) {
    println("Nothing selected. Use --help to see options.");
    exit(0);
}

if ($dryRun) {
    println("[dry-run] No changes will be made.");
    exit(0);
}

if (!$nonInteractive || !$force) {
    if ($nonInteractive && !$force) {
        fwrite(STDERR, "Refusing to proceed in --non-interactive mode without --force.\n");
        exit(3);
    }
    $confirm = readline("Type 'RESET CONTENT' (without quotes) to continue: ");
    if (trim((string)$confirm) !== 'RESET CONTENT') {
        println("Aborted.");
        exit(0);
    }
}

println();
println("→ Resetting...");
try {
    $result = ContentReset::reset($selected, UPLOAD_PATH);
} catch (\Throwable $e) {
    fwrite(STDERR, "Reset failed: " . $e->getMessage() . "\n");
    exit(4);
}

foreach ($result['db_log'] as $entry) {
    println("  $entry");
}
foreach ($result['fs_log'] as $entry) {
    println("  $entry");
}
if (!empty($result['fs_failed'])) {
    println();
    println("WARNING: " . count($result['fs_failed']) . " path(s) couldn't be removed:");
    foreach ($result['fs_failed'] as $p) {
        println("  - $p");
    }
}

println();
println("Reset complete.");
exit(0);

// -- Helpers ---------------------------------------------------------------

function println(string $line = ''): void { fwrite(STDOUT, $line . PHP_EOL); }

function partitionSummary(string $partition): string
{
    return match ($partition) {
        'content'         => count(\ContentReset::CONTENT_TABLES) . ' content tables (pottery, products, events, announcements, social, orders, templates) + reseed shop_categories',
        'uploads'         => 'files under ' . implode(', ', array_map(fn($d) => "uploads/$d/", \ContentReset::UPLOAD_SUBDIRS)),
        'branding'        => count(\ContentReset::BRANDING_SETTING_KEYS) . ' settings (site_name, tagline, hero copy, contact_email, profile photo, privacy policy, nav link)',
        'text_overrides'  => 'all admin page-text overrides (settings rows keyed text.*)',
        'email_overrides' => 'all admin email-template overrides (settings rows keyed email.*)',
        'design'          => 'theme overrides, event-type labels, page_sections all reset to defaults',
        default           => '',
    };
}

function usage(): void
{
    println(<<<TXT
Usage: php bin/reset-content.php [options]

Modes:
  (no flag)             Interactive prompt + confirmation.
  --non-interactive     Take all selections from flags. Requires --force.
  --force               Bypass the typed-confirmation prompt.
  --dry-run             Print what would happen; make no changes.
  --help                This message.

Partition flags (all partitions run by default; --keep-* to skip):
  --keep-content        Don't wipe content tables.
  --keep-uploads        Don't wipe upload subdirs.
  --keep-branding       Don't wipe branding settings (site name, tagline, hero, etc.).
  --keep-text-overrides Don't wipe admin page-text overrides.
  --keep-email-overrides Don't wipe admin email-template overrides.
  --keep-design         Don't reset theme / event-type labels / page sections.

PRESERVED (always):
  - DB schema (no DROP/ALTER).
  - admin_users (handoff target manages their own admins).
  - All auth_* settings (login keeps working).
  - schema_migrations ledger.
  - shop_currency.
TXT);
}
