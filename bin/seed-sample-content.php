<?php
/**
 * CLI driver for SampleContent::seed() — populate a fresh install with demo
 * pottery / events / announcements / shop products / social links so an
 * adopter can see what the site looks like populated before they've added
 * their own content.
 *
 *   # Interactive (asks to confirm):
 *   php bin/seed-sample-content.php
 *
 *   # Non-interactive (Docker entrypoints, CI):
 *   php bin/seed-sample-content.php --force
 *
 * Re-seeding is blocked by a settings-table marker. To re-seed, wipe demo rows
 * first via the admin Reset Content page (content partition) or via
 * `bin/reset-content.php`.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "bin/seed-sample-content.php must be run from the command line.\n");
    exit(2);
}

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/vendor/autoload.php';
if (file_exists(ROOT_PATH . '/.env')) {
    \Dotenv\Dotenv::createImmutable(ROOT_PATH)->safeLoad();
}
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/Database.php';
require_once ROOT_PATH . '/includes/SampleContent.php';

$opts  = getopt('', ['force', 'help']);
$force = isset($opts['force']);

if (isset($opts['help'])) {
    echo "Usage: php bin/seed-sample-content.php [--force]\n";
    echo "  --force   Skip the interactive confirmation prompt.\n";
    exit(0);
}

if (SampleContent::isSeeded()) {
    fwrite(STDERR, "Sample content has already been loaded on this install.\n");
    fwrite(STDERR, "Wipe the demo rows first via bin/reset-content.php (or the admin Reset Content page) to re-seed.\n");
    exit(1);
}

if (!$force) {
    echo "About to seed " . count(SampleContent::SAMPLE_POTTERY) . " pottery pieces, "
        . count(SampleContent::SAMPLE_EVENTS) . " events, "
        . count(SampleContent::SAMPLE_ANNOUNCEMENTS) . " announcements, "
        . count(SampleContent::SAMPLE_PRODUCTS) . " products, "
        . count(SampleContent::SAMPLE_SOCIAL_LINKS) . " social links\n";
    echo "into database " . (defined('DB_NAME') ? DB_NAME : '?') . ".\n";
    echo "Continue? [y/N] ";
    $line = trim((string)fgets(STDIN));
    if (strtolower($line) !== 'y') {
        echo "Aborted.\n";
        exit(0);
    }
}

try {
    $result = SampleContent::seed(rtrim(UPLOAD_PATH, '/\\'));
} catch (\Throwable $e) {
    fwrite(STDERR, "Seeding failed: " . $e->getMessage() . "\n");
    exit(1);
}

if ($result['skipped']) {
    echo "Nothing to do — sample content already loaded.\n";
    exit(0);
}

echo "✓ Sample content loaded:\n";
foreach ($result['created'] as $kind => $n) {
    echo "  - $n $kind\n";
}
if (!empty($result['image_dir_warnings'])) {
    fwrite(STDERR, "Warning: couldn't create these upload dirs (images skipped): "
        . implode(', ', $result['image_dir_warnings']) . "\n");
}
exit(0);
