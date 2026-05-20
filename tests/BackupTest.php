<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/Backup.php';

/**
 * Pure-helper tests for Backup. DB dump + ZipArchive + browser streaming are
 * exercised in the Docker smoke verification.
 */
final class BackupTest extends TestCase
{
    public function test_slugify_lowercases_and_replaces_runs(): void
    {
        $this->assertSame('my-pottery-studio', \Backup::slugify('My Pottery Studio'));
        $this->assertSame('foo-bar',           \Backup::slugify('  foo &&& bar  '));
        $this->assertSame('terra-gold-42',     \Backup::slugify('Terra/Gold 42'));
    }

    public function test_slugify_falls_back_when_only_special_chars(): void
    {
        $this->assertSame('site', \Backup::slugify(''));
        $this->assertSame('site', \Backup::slugify('!!!'));
        $this->assertSame('site', \Backup::slugify('  -- '));
    }

    public function test_safe_table_accepts_valid_identifiers(): void
    {
        $this->assertSame('pottery',         \Backup::safeTable('pottery'));
        $this->assertSame('admin_users',     \Backup::safeTable('admin_users'));
        $this->assertSame('a_b$c_1',         \Backup::safeTable('a_b$c_1'));
    }

    public function test_safe_table_throws_on_dangerous_input(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsafe name');
        \Backup::safeTable('drop`; DROP TABLE x; --');
    }

    public function test_safe_table_throws_on_whitespace(): void
    {
        $this->expectException(\RuntimeException::class);
        \Backup::safeTable('table name');
    }

    public function test_safe_table_throws_on_dot_or_hyphen(): void
    {
        $this->expectException(\RuntimeException::class);
        \Backup::safeTable('db.table');
    }

    public function test_add_dir_to_zip_returns_zero_for_missing_dir(): void
    {
        $missing = sys_get_temp_dir() . '/backup_missing_' . bin2hex(random_bytes(4));
        $zip = new \ZipArchive();
        $zipPath = sys_get_temp_dir() . '/backup_test_' . bin2hex(random_bytes(4)) . '.zip';
        $zip->open($zipPath, \ZipArchive::CREATE);
        try {
            [$count, $bytes] = \Backup::addDirToZip($zip, $missing, 'uploads');
            $this->assertSame(0, $count);
            $this->assertSame(0, $bytes);
        } finally {
            $zip->close();
            @unlink($zipPath);
        }
    }

    public function test_add_dir_to_zip_recursively_packs_contents(): void
    {
        $tmp = sys_get_temp_dir() . '/backup_dir_' . bin2hex(random_bytes(4));
        mkdir($tmp . '/sub', 0700, true);
        file_put_contents($tmp . '/top.txt', 'hello');
        file_put_contents($tmp . '/sub/nested.txt', 'world!');

        $zipPath = $tmp . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        try {
            [$count, $bytes] = \Backup::addDirToZip($zip, $tmp, 'uploads');
            $zip->close();

            $this->assertSame(2, $count);
            $this->assertSame(5 + 6, $bytes);

            $verify = new \ZipArchive();
            $verify->open($zipPath);
            $names = [];
            for ($i = 0; $i < $verify->numFiles; $i++) {
                $names[] = $verify->getNameIndex($i);
            }
            $verify->close();
            $this->assertContains('uploads/top.txt',        $names);
            $this->assertContains('uploads/sub/nested.txt', $names);
        } finally {
            @unlink($tmp . '/sub/nested.txt');
            @unlink($tmp . '/top.txt');
            @rmdir($tmp . '/sub');
            @rmdir($tmp);
            @unlink($zipPath);
        }
    }
}
