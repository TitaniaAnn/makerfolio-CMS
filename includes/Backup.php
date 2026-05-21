<?php
/**
 * Backup — snapshot the whole site (DB + uploads + optional .env) into a
 * single zip file an admin can download.
 *
 * Pure-PHP SQL dump (no mysqldump dependency — works on shared hosts that
 * disable shell_exec). The output .sql file is written incrementally via
 * fwrite per row rather than concatenated as one string in memory, so the
 * dump itself doesn't OOM on a large table. Note: PDO's default MySQL
 * driver still buffers the result set client-side (MYSQL_ATTR_USE_BUFFERED_QUERY
 * is true), so end-to-end this is not a true streaming backup — it's adequate
 * for small-to-mid sites but not a guarantee against memory pressure on
 * multi-GB tables.
 *
 * NOT IN SCOPE: restore. The zip is for safekeeping / off-site copies; if
 * you need to restore, manually unzip and run `mysql < database.sql` plus
 * rsync the uploads/ tree back into place. A "restore from backup" admin
 * button could be a future addition but is harder (drop existing rows? merge?
 * what about active sessions?). Out of scope here.
 *
 * Caller pattern:
 *   $result = Backup::create($tmpDir, $opts, $pdo, $uploadsPath, $envPath);
 *   Backup::streamAndCleanup($result['zip_path'], $result['filename']);
 */
final class Backup
{
    /**
     * Build the zip. Returns ['zip_path' => ..., 'filename' => ..., 'manifest' => ...].
     *
     * @param string $tmpDir       Writable dir for the temp .sql + .zip files.
     * @param array  $options      ['include_uploads' => bool, 'include_env' => bool]
     *                             Defaults: uploads ON, env OFF.
     * @param PDO    $pdo          Connection used for the SQL dump.
     * @param string $uploadsPath  Absolute path to public/uploads/ (added when include_uploads).
     * @param string $envPath      Absolute path to .env (added when include_env AND the file exists).
     */
    public static function create(
        string $tmpDir,
        array $options,
        PDO $pdo,
        string $uploadsPath,
        string $envPath
    ): array {
        $opts = [
            'include_uploads' => $options['include_uploads'] ?? true,
            'include_env'     => $options['include_env']     ?? false,
        ];

        if (!is_dir($tmpDir) || !is_writable($tmpDir)) {
            throw new RuntimeException("Backup temp dir not writable: $tmpDir");
        }

        $stamp    = date('Y-m-d_His');
        $siteSlug = self::slugify(self::resolveSiteName());
        $base     = $tmpDir . DIRECTORY_SEPARATOR . "pottery-backup-{$siteSlug}-{$stamp}-" . bin2hex(random_bytes(3));
        $sqlPath  = $base . '.sql';
        $zipPath  = $base . '.zip';

        // 1. Stream the SQL dump.
        $fp = @fopen($sqlPath, 'w');
        if ($fp === false) {
            throw new RuntimeException("Could not open temp SQL file for writing: $sqlPath");
        }
        try {
            self::dumpDatabaseTo($pdo, $fp);
        } finally {
            fclose($fp);
        }
        $sqlSize = filesize($sqlPath) ?: 0;

        // 2. Build the zip.
        if (!class_exists('ZipArchive')) {
            @unlink($sqlPath);
            throw new RuntimeException('ZipArchive (ext-zip) is not available on this PHP build.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($sqlPath);
            throw new RuntimeException("Could not create zip: $zipPath");
        }

        $manifest = [
            'site_name'        => self::resolveSiteName(),
            'site_url'         => defined('SITE_URL') ? SITE_URL : '',
            'generated_at'     => date('c'),
            'generated_by'     => 'Backup::create',
            'options'          => $opts,
            'database'         => ['size_bytes' => $sqlSize, 'tables' => self::listTables($pdo)],
        ];

        $uploadsAdded = 0;
        $uploadsBytes = 0;
        if ($opts['include_uploads'] && is_dir($uploadsPath)) {
            [$uploadsAdded, $uploadsBytes] = self::addDirToZip($zip, $uploadsPath, 'uploads');
        }
        $manifest['uploads'] = [
            'included'    => (bool)$opts['include_uploads'],
            'file_count'  => $uploadsAdded,
            'size_bytes'  => $uploadsBytes,
        ];

        if ($opts['include_env'] && is_file($envPath)) {
            $zip->addFile($envPath, '.env');
            $manifest['env'] = ['included' => true, 'size_bytes' => filesize($envPath) ?: 0];
        } else {
            $manifest['env'] = ['included' => false, 'size_bytes' => 0];
        }

        $zip->addFile($sqlPath, 'database.sql');
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (!$zip->close()) {
            @unlink($sqlPath);
            @unlink($zipPath);
            throw new RuntimeException('ZipArchive::close failed.');
        }
        // The sql file is now inside the zip; we can drop the loose copy.
        @unlink($sqlPath);

        return [
            'zip_path' => $zipPath,
            'filename' => "pottery-backup-{$siteSlug}-{$stamp}.zip",
            'manifest' => $manifest,
        ];
    }

    /**
     * Send the zip to the browser and delete the temp file. Exits the script
     * on completion — never returns.
     */
    public static function streamAndCleanup(string $zipPath, string $filename): void
    {
        if (!is_file($zipPath)) {
            http_response_code(500);
            exit('Backup file missing.');
        }
        // Ensure no buffered output corrupts the binary payload.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // Best-effort: even if the client disconnects mid-download, clean up the temp file.
        register_shutdown_function(function () use ($zipPath) {
            @unlink($zipPath);
        });

        // Defense-in-depth header sanitization: the filename is built from
        // slugify() output (already restricted to [a-z0-9-]) + a timestamp +
        // hex random bytes, so CRLF / double-quotes shouldn't appear. But
        // sanitize anyway in case a future caller passes an externally-derived
        // filename — header injection here would be CRLF response splitting.
        $safeFilename = preg_replace('/[\x00-\x1F\x7F"\\\\]+/', '', $filename) ?? 'backup.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Content-Length: ' . (filesize($zipPath) ?: 0));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        readfile($zipPath);
        exit;
    }

    // -- SQL dump ------------------------------------------------------------

    /**
     * Write a full SQL dump of every table in the current DB to $fp.
     * Streaming: one row at a time, never materializing the whole dump.
     */
    public static function dumpDatabaseTo(PDO $pdo, $fp): void
    {
        $header = "-- Pottery portfolio backup\n"
                . "-- Generated: " . date('c') . "\n"
                . "-- PHP " . PHP_VERSION . "\n\n"
                . "SET NAMES utf8mb4;\n"
                . "SET FOREIGN_KEY_CHECKS=0;\n\n";
        fwrite($fp, $header);

        foreach (self::listTables($pdo) as $table) {
            // Validate once per table; reuse the validated name in every
            // raw-SQL fragment below so a poisoned SHOW TABLES result (e.g. a
            // future feature that auto-creates tables from user input) can't
            // sneak through any of the DROP/SHOW CREATE/SELECT/INSERT lines.
            $safe = self::safeTable($table);
            fwrite($fp, "-- ---------- $safe ----------\n");
            fwrite($fp, "DROP TABLE IF EXISTS `$safe`;\n");
            $createRow = $pdo->query("SHOW CREATE TABLE `$safe`")->fetch(PDO::FETCH_NUM);
            fwrite($fp, $createRow[1] . ";\n\n");

            $stmt = $pdo->query("SELECT * FROM `$safe`");
            $cols = '';
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($cols === '') {
                    $cols = '`' . implode('`,`', array_keys($row)) . '`';
                }
                $values = array_map(function ($v) use ($pdo) {
                    return $v === null ? 'NULL' : $pdo->quote((string)$v);
                }, array_values($row));
                fwrite($fp, "INSERT INTO `$safe` ($cols) VALUES (" . implode(',', $values) . ");\n");
            }
            fwrite($fp, "\n");
        }
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    }

    /** @return string[] */
    public static function listTables(PDO $pdo): array
    {
        return $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Defense in depth: only allow standard MySQL identifiers as raw SQL fragments. */
    public static function safeTable(string $table): string
    {
        if (!preg_match('/^[A-Za-z0-9_$]+$/', $table)) {
            throw new RuntimeException("Refusing to backup table with unsafe name: $table");
        }
        return $table;
    }

    // -- Zip helpers ---------------------------------------------------------

    /**
     * Recursively add $localDir into the zip at $zipPrefix. Returns
     * [file_count, total_bytes].
     *
     * @return array{0:int,1:int}
     */
    public static function addDirToZip(ZipArchive $zip, string $localDir, string $zipPrefix): array
    {
        if (!is_dir($localDir)) {
            return [0, 0];
        }
        $count = 0;
        $bytes = 0;
        $iter  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $base = rtrim($localDir, '/\\');
        foreach ($iter as $entry) {
            $abs = (string)$entry;
            $rel = ltrim(str_replace($base, '', $abs), '/\\');
            $rel = str_replace('\\', '/', $rel);
            if ($entry->isDir()) {
                $zip->addEmptyDir($zipPrefix . '/' . $rel);
            } else {
                $zip->addFile($abs, $zipPrefix . '/' . $rel);
                $count++;
                $bytes += $entry->getSize() ?: 0;
            }
        }
        return [$count, $bytes];
    }

    // -- Helpers -------------------------------------------------------------

    /** Filesystem-safe slug of a string; used in the backup filename. */
    public static function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/i', '-', $s) ?? '';
        $s = trim($s, '-');
        return $s === '' ? 'site' : $s;
    }

    private static function resolveSiteName(): string
    {
        if (function_exists('setting')) {
            $name = (string)setting('site_name', '');
            if ($name !== '') return $name;
        }
        return 'pottery';
    }
}
