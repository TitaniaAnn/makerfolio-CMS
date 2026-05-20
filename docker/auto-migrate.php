<?php
/**
 * Container-boot helper — applies pending migrations and bootstraps auth
 * settings from .env values. Designed to be safe to re-run: applyMigrations
 * is idempotent (skips ledger entries), bootstrapAuthFromEnv only writes when
 * the target settings rows are blank.
 *
 * Invoked by docker/entrypoint.sh. Reads DB credentials from environment
 * variables (compose passes these in via env_file).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../includes/MigrationRunner.php';
require __DIR__ . '/../includes/Installer.php';

$cfg = [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'name' => (string)getenv('DB_NAME'),
    'user' => (string)getenv('DB_USER'),
    'pass' => (string)getenv('DB_PASS'),
];

if ($cfg['name'] === '' || $cfg['user'] === '') {
    fwrite(STDERR, "[auto-migrate] DB_NAME/DB_USER missing — aborting\n");
    exit(0);
}

$installer = new Installer(dirname(__DIR__));

// Wait for the DB to accept connections. Compose's healthcheck normally gets
// us there first, but be defensive — first boot can race.
$attempts = 0;
$ok = false;
while ($attempts < 30) {
    $r = $installer->testDbConnection($cfg);
    if ($r['ok']) {
        $ok = true;
        break;
    }
    $attempts++;
    sleep(1);
}
if (!$ok) {
    fwrite(STDERR, "[auto-migrate] DB unreachable after 30s — aborting\n");
    exit(0);
}

try {
    $pdo = $installer->buildPdo($cfg);
    $r   = $installer->applyMigrations($pdo);
    if (!empty($r['applied'])) {
        echo "[auto-migrate] applied: " . implode(', ', $r['applied']) . "\n";
    } else {
        echo "[auto-migrate] no pending migrations\n";
    }

    if (method_exists($installer, 'bootstrapAuthFromEnv')) {
        $b = $installer->bootstrapAuthFromEnv($pdo);
        if (!empty($b['written'])) {
            echo "[auto-migrate] bootstrapped auth settings: " . implode(', ', $b['written']) . "\n";
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[auto-migrate] error: " . $e->getMessage() . "\n");
    exit(0);
}
