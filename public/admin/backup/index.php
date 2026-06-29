<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $opts = [
        'include_uploads' => !empty($_POST['include_uploads']),
        'include_env'     => !empty($_POST['include_env']),
    ];

    try {
        $result = Backup::create(
            sys_get_temp_dir(),
            $opts,
            Database::getInstance(),
            UPLOAD_PATH,
            ROOT_PATH . '/.env'
        );
        ActivityLog::log('backup.download', null, null, [
            'include_uploads' => (bool)$opts['include_uploads'],
            'include_env'     => (bool)$opts['include_env'],
            'filename'        => $result['filename'],
        ]);
        // Streams the zip and exits.
        Backup::streamAndCleanup($result['zip_path'], $result['filename']);
    } catch (\Throwable $e) {
        $errors[] = 'Backup failed: ' . $e->getMessage();
    }
}

// Surface a couple of useful numbers so the admin knows what they're about to download.
$tableCount = 0;
try {
    $tableCount = count(Backup::listTables(Database::getInstance()));
} catch (\Throwable $_) {}

$uploadCount = 0;
$uploadBytes = 0;
if (is_dir(UPLOAD_PATH)) {
    foreach (new \RecursiveIteratorIterator(
                 new \RecursiveDirectoryIterator(UPLOAD_PATH, \FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) {
            $uploadCount++;
            $uploadBytes += $f->getSize() ?: 0;
        }
    }
}
$envExists = is_file(ROOT_PATH . '/.env');

function fmtBytes(int $b): string {
    if ($b < 1024)            return "$b B";
    if ($b < 1024 * 1024)     return number_format($b / 1024, 1) . ' KB';
    if ($b < 1024 * 1024 * 1024) return number_format($b / 1024 / 1024, 1) . ' MB';
    return number_format($b / 1024 / 1024 / 1024, 2) . ' GB';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/pages/backup-index.css">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Backup</h1>
        </div>

        <?php foreach ($errors as $err): ?>
            <div class="flash flash--error"><?= e($err) ?></div>
        <?php endforeach; ?>

        <p class="u-muted-fog">
            Download a single zip containing a SQL dump of the database, your uploaded
            files, and a manifest. Useful for off-site copies, migrating to a new host,
            or as a snapshot before destructive admin changes.
        </p>

        <div class="admin-card">
            <h2>What's in this install</h2>
            <div class="bk-stat-row">
                <span class="bk-stat-row__label">Database tables</span>
                <span class="bk-stat-row__value"><?= (int)$tableCount ?></span>
            </div>
            <div class="bk-stat-row">
                <span class="bk-stat-row__label">Upload files</span>
                <span class="bk-stat-row__value"><?= (int)$uploadCount ?> files · <?= e(fmtBytes($uploadBytes)) ?></span>
            </div>
            <div class="bk-stat-row">
                <span class="bk-stat-row__label">.env</span>
                <span class="bk-stat-row__value"><?= $envExists ? 'present' : 'missing' ?></span>
            </div>
        </div>

        <form method="POST" class="admin-form u-maxw-720">
            <?= csrf_field() ?>

            <div class="admin-card">
                <h2>Options</h2>

                <div class="bk-option">
                    <input type="checkbox" id="opt_uploads" name="include_uploads" value="1" checked>
                    <div>
                        <label for="opt_uploads">Include uploads/</label>
                        <small>All your pottery / product / hero / profile / template files. The bulk of the zip's size.</small>
                    </div>
                </div>

                <div class="bk-option">
                    <input type="checkbox" id="opt_env" name="include_env" value="1">
                    <div>
                        <label for="opt_env">Include <code>.env</code> <em>(contains secrets)</em></label>
                        <small>Database credentials and any leftover OAuth secrets. Only check this if you control where the zip ends up — never email or upload it to an untrusted location.</small>
                    </div>
                </div>

                <?php if (!$envExists): ?>
                    <p class="bk-warn">No <code>.env</code> file present at the project root — the include-env option will be a no-op.</p>
                <?php endif; ?>
            </div>

            <div class="bk-warn">
                <strong>Heads-up:</strong> the SQL dump always includes the full <code>admin_users</code>
                and <code>auth_*</code> settings (password hashes, OAuth credentials in DB, customer order
                details). Treat the resulting zip like a master password — store it somewhere encrypted.
            </div>

            <div class="form-actions">
                <button type="submit" class="admin-btn admin-btn--primary">Download backup</button>
                <a href="/admin/dashboard" class="admin-btn">Cancel</a>
            </div>
        </form>
    </div>
</main>
</body>
</html>
