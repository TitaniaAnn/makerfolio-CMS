<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/MigrationRunner.php';
require_once __DIR__ . '/../includes/Installer.php';

/**
 * Pure-function tests for Installer. The DB-touching paths (testDbConnection,
 * initializeSchema, applyMigrations, seedBranding) are NOT covered — they
 * follow the same convention as MigrationRunner / Database (no unit tests,
 * exercised in practice).
 */
final class InstallerTest extends TestCase
{
    public function test_is_valid_db_host_accepts_typical_values(): void
    {
        $this->assertTrue(\Installer::isValidDbHost('localhost'));
        $this->assertTrue(\Installer::isValidDbHost('db'));
        $this->assertTrue(\Installer::isValidDbHost('mysql.example.com'));
        $this->assertTrue(\Installer::isValidDbHost('127.0.0.1'));
        $this->assertTrue(\Installer::isValidDbHost('db:3306'));
    }

    public function test_is_valid_db_host_rejects_dangerous_input(): void
    {
        $this->assertFalse(\Installer::isValidDbHost(''));
        $this->assertFalse(\Installer::isValidDbHost('localhost; DROP TABLE x'));
        $this->assertFalse(\Installer::isValidDbHost('host with space'));
        $this->assertFalse(\Installer::isValidDbHost("host\nwithnewline"));
    }

    public function test_is_valid_db_ident_accepts_mysql_identifiers(): void
    {
        $this->assertTrue(\Installer::isValidDbIdent('pottery_portfolio'));
        $this->assertTrue(\Installer::isValidDbIdent('user'));
        $this->assertTrue(\Installer::isValidDbIdent('a_b$c_1'));
    }

    public function test_is_valid_db_ident_rejects_non_identifiers(): void
    {
        $this->assertFalse(\Installer::isValidDbIdent(''));
        $this->assertFalse(\Installer::isValidDbIdent('with-dash'));
        $this->assertFalse(\Installer::isValidDbIdent('with space'));
        $this->assertFalse(\Installer::isValidDbIdent('drop;table'));
        $this->assertFalse(\Installer::isValidDbIdent(str_repeat('a', 65)), 'over 64 chars');
    }

    public function test_is_valid_url_requires_http_scheme(): void
    {
        $this->assertTrue(\Installer::isValidUrl('https://example.com'));
        $this->assertTrue(\Installer::isValidUrl('http://localhost:8088'));
        $this->assertFalse(\Installer::isValidUrl('example.com'),         'missing scheme');
        $this->assertFalse(\Installer::isValidUrl('ftp://example.com'),   'wrong scheme');
        $this->assertFalse(\Installer::isValidUrl('javascript:alert(1)'), 'rejects javascript: scheme');
        $this->assertFalse(\Installer::isValidUrl(''));
    }

    public function test_is_valid_github_username_matches_github_rules(): void
    {
        $this->assertTrue(\Installer::isValidGithubUsername('octocat'));
        $this->assertTrue(\Installer::isValidGithubUsername('user-1'));
        $this->assertTrue(\Installer::isValidGithubUsername('a'));
        $this->assertTrue(\Installer::isValidGithubUsername(str_repeat('a', 39)));

        $this->assertFalse(\Installer::isValidGithubUsername(''),                'empty');
        $this->assertFalse(\Installer::isValidGithubUsername('-leading'),        'leading hyphen');
        $this->assertFalse(\Installer::isValidGithubUsername('trailing-'),       'trailing hyphen');
        $this->assertFalse(\Installer::isValidGithubUsername('double--dash'),    'consecutive hyphens');
        $this->assertFalse(\Installer::isValidGithubUsername('has space'));
        $this->assertFalse(\Installer::isValidGithubUsername(str_repeat('a', 40)), 'over 39 chars');
    }

    public function test_parse_allowed_users_splits_on_commas_and_whitespace(): void
    {
        $this->assertSame(['alice'],            \Installer::parseAllowedUsers('alice'));
        $this->assertSame(['alice', 'bob'],     \Installer::parseAllowedUsers('alice,bob'));
        $this->assertSame(['alice', 'bob'],     \Installer::parseAllowedUsers('alice, bob'));
        $this->assertSame(['alice', 'bob'],     \Installer::parseAllowedUsers("alice\nbob"));
        $this->assertSame([],                   \Installer::parseAllowedUsers(''));
        $this->assertSame([],                   \Installer::parseAllowedUsers('   '));
    }

    public function test_is_valid_allowed_users_requires_at_least_one_valid(): void
    {
        $this->assertTrue(\Installer::isValidAllowedUsers('alice'));
        $this->assertTrue(\Installer::isValidAllowedUsers('alice, bob, charlie-1'));

        $this->assertFalse(\Installer::isValidAllowedUsers(''),                  'empty');
        $this->assertFalse(\Installer::isValidAllowedUsers('alice, -invalid'),   'one bad entry fails all');
    }

    public function test_quote_env_value_leaves_safe_values_unquoted(): void
    {
        $this->assertSame('',          \Installer::quoteEnvValue(''));
        $this->assertSame('localhost', \Installer::quoteEnvValue('localhost'));
        $this->assertSame('abc-123',   \Installer::quoteEnvValue('abc-123'));
        $this->assertSame('a.b.c:80',  \Installer::quoteEnvValue('a.b.c:80'));
    }

    public function test_quote_env_value_wraps_values_with_special_chars(): void
    {
        $this->assertSame('"has space"', \Installer::quoteEnvValue('has space'));
        $this->assertSame('"a=b"',       \Installer::quoteEnvValue('a=b'));
        $this->assertSame('"hash#sign"', \Installer::quoteEnvValue('hash#sign'));
        $this->assertSame('"dollar$"',   \Installer::quoteEnvValue('dollar$'));
    }

    public function test_quote_env_value_escapes_embedded_quotes_and_backslashes(): void
    {
        $this->assertSame('"with \\"quotes\\""', \Installer::quoteEnvValue('with "quotes"'));
        $this->assertSame('"back\\\\slash"',     \Installer::quoteEnvValue('back\\slash'));
    }

    public function test_render_env_groups_known_keys_by_section(): void
    {
        $env = \Installer::renderEnv([
            'DB_HOST'              => 'db',
            'DB_NAME'              => 'piece',
            'DB_USER'              => 'me',
            'DB_PASS'              => 'p@ss word',
            'SITE_URL'             => 'https://example.com',
            'GITHUB_CLIENT_ID'     => 'gh-id',
            'GITHUB_CLIENT_SECRET' => 'gh-secret',
            'ALLOWED_GITHUB_USERS' => 'alice,bob',
        ]);

        // Section headers present
        $this->assertStringContainsString('# Database',                $env);
        $this->assertStringContainsString('# Site',                    $env);
        $this->assertStringContainsString('# GitHub OAuth (legacy — managed in admin UI)', $env);
        $this->assertStringContainsString('# Stripe (optional)',       $env);
        $this->assertStringContainsString('# Instagram (optional)',    $env);

        // Key/value pairs (quoted where needed)
        $this->assertStringContainsString('DB_HOST=db',                    $env);
        $this->assertStringContainsString('DB_PASS="p@ss word"',           $env);
        $this->assertStringContainsString('ALLOWED_GITHUB_USERS=alice,bob',$env);

        // Missing optional keys still get rendered as blank
        $this->assertStringContainsString('STRIPE_PUBLISHABLE_KEY=' . "\n", $env);
        $this->assertStringContainsString('INSTAGRAM_ACCESS_TOKEN=' . "\n", $env);
    }

    public function test_marker_path_lives_at_project_root(): void
    {
        $i = new \Installer('/srv/app');
        // Installer uses DIRECTORY_SEPARATOR, so on Windows the separator is
        // a backslash. Normalize both sides to forward slashes so this test
        // doesn't fail when run on a Windows dev host (it still passes in CI).
        $norm = fn(string $p) => str_replace(DIRECTORY_SEPARATOR, '/', $p);
        $this->assertSame('/srv/app/.installed', $norm($i->markerPath()));
        $this->assertSame('/srv/app/.env',       $norm($i->envPath()));
    }

    public function test_is_installed_reflects_marker_presence(): void
    {
        $tmp = sys_get_temp_dir() . '/installer_test_' . bin2hex(random_bytes(4));
        mkdir($tmp);
        try {
            $i = new \Installer($tmp);
            $this->assertFalse($i->isInstalled());

            $i->markInstalled('unit test');
            $this->assertTrue($i->isInstalled());
            $this->assertFileExists($i->markerPath());

            $contents = file_get_contents($i->markerPath());
            $this->assertStringContainsString('unit test', $contents);
        } finally {
            @unlink($tmp . '/.installed');
            @rmdir($tmp);
        }
    }

    public function test_write_env_file_refuses_to_overwrite_without_force(): void
    {
        $tmp = sys_get_temp_dir() . '/installer_envtest_' . bin2hex(random_bytes(4));
        mkdir($tmp);
        try {
            $i = new \Installer($tmp);
            $i->writeEnvFile(['DB_HOST' => 'a', 'DB_NAME' => 'b', 'DB_USER' => 'c', 'DB_PASS' => 'd', 'SITE_URL' => 'https://x', 'GITHUB_CLIENT_ID' => 'x', 'GITHUB_CLIENT_SECRET' => 'y', 'ALLOWED_GITHUB_USERS' => 'me']);
            $this->assertFileExists($i->envPath());

            // Second write without force should throw.
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('already exists');
            $i->writeEnvFile(['DB_HOST' => 'a', 'DB_NAME' => 'b', 'DB_USER' => 'c', 'DB_PASS' => 'd', 'SITE_URL' => 'https://x', 'GITHUB_CLIENT_ID' => 'x', 'GITHUB_CLIENT_SECRET' => 'y', 'ALLOWED_GITHUB_USERS' => 'me']);
        } finally {
            @unlink($tmp . '/.env');
            @rmdir($tmp);
        }
    }

    public function test_write_env_file_overwrites_when_forced(): void
    {
        $tmp = sys_get_temp_dir() . '/installer_envtest_' . bin2hex(random_bytes(4));
        mkdir($tmp);
        try {
            $i = new \Installer($tmp);
            $base = ['DB_HOST' => 'a', 'DB_NAME' => 'b', 'DB_USER' => 'c', 'DB_PASS' => 'd', 'SITE_URL' => 'https://x', 'GITHUB_CLIENT_ID' => 'x', 'GITHUB_CLIENT_SECRET' => 'y', 'ALLOWED_GITHUB_USERS' => 'me'];
            $i->writeEnvFile($base);
            $i->writeEnvFile(['DB_HOST' => 'second'] + $base, true);

            $body = file_get_contents($i->envPath());
            $this->assertStringContainsString('DB_HOST=second', $body);
        } finally {
            @unlink($tmp . '/.env');
            @rmdir($tmp);
        }
    }

    public function test_remove_installer_dir_recursively_deletes_install_folder(): void
    {
        $tmp = sys_get_temp_dir() . '/installer_rm_' . bin2hex(random_bytes(4));
        $dir = $tmp . '/install';
        mkdir($dir . '/sub', 0700, true);
        file_put_contents($dir . '/index.php', '<?php');
        file_put_contents($dir . '/sub/asset.css', 'body{}');

        $result = \Installer::removeInstallerDir($dir);

        $this->assertTrue($result['deleted']);
        $this->assertSame([], $result['failed_paths']);
        $this->assertDirectoryDoesNotExist($dir);

        @rmdir($tmp);
    }

    public function test_remove_installer_dir_is_safe_when_folder_missing(): void
    {
        $missing = sys_get_temp_dir() . '/installer_rm_nope_' . bin2hex(random_bytes(4)) . '/install';
        $result = \Installer::removeInstallerDir($missing);
        $this->assertTrue($result['deleted']);
        $this->assertSame([], $result['failed_paths']);
    }

    public function test_remove_installer_dir_refuses_paths_not_named_install(): void
    {
        $tmp = sys_get_temp_dir() . '/installer_rm_guard_' . bin2hex(random_bytes(4));
        mkdir($tmp);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("basename must be 'install'");
            \Installer::removeInstallerDir($tmp);
        } finally {
            @rmdir($tmp);
        }
    }

    public function test_parse_env_file_reads_simple_pairs(): void
    {
        $tmp = sys_get_temp_dir() . '/installer_envparse_' . bin2hex(random_bytes(4)) . '.env';
        file_put_contents($tmp, <<<ENV
# leading comment
DB_HOST=localhost
DB_NAME=mydb
EMPTY_KEY=
# blank line below

QUOTED="value with spaces"
WITH_QUOTE="he said \\"hi\\""
NO_EQUALS_SIGN_LINE
ENV);
        try {
            $env = \Installer::parseEnvFile($tmp);
            $this->assertSame('localhost',            $env['DB_HOST']);
            $this->assertSame('mydb',                 $env['DB_NAME']);
            $this->assertSame('',                     $env['EMPTY_KEY']);
            $this->assertSame('value with spaces',    $env['QUOTED']);
            $this->assertSame('he said "hi"',         $env['WITH_QUOTE']);
            $this->assertArrayNotHasKey('NO_EQUALS_SIGN_LINE', $env);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_is_valid_username_enforces_length_and_charset(): void
    {
        $this->assertTrue(\Installer::isValidUsername('abc'));
        $this->assertTrue(\Installer::isValidUsername('user_1'));
        $this->assertTrue(\Installer::isValidUsername(str_repeat('a', 64)));

        $this->assertFalse(\Installer::isValidUsername(''));
        $this->assertFalse(\Installer::isValidUsername('ab'),        'too short');
        $this->assertFalse(\Installer::isValidUsername(str_repeat('a', 65)), 'too long');
        $this->assertFalse(\Installer::isValidUsername('user-name'), 'hyphen rejected');
        $this->assertFalse(\Installer::isValidUsername('user.name'), 'dot rejected');
        $this->assertFalse(\Installer::isValidUsername('user name'), 'space rejected');
        $this->assertFalse(\Installer::isValidUsername('user@x'),    '@ rejected');
    }

    public function test_is_valid_password_requires_12_chars_no_edge_whitespace(): void
    {
        $this->assertTrue(\Installer::isValidPassword('abcdefghijkl'));         // exactly 12
        $this->assertTrue(\Installer::isValidPassword('verylongpasswordhere'));
        $this->assertTrue(\Installer::isValidPassword('pass word with spaces 9'));

        $this->assertFalse(\Installer::isValidPassword(''),                     'empty');
        $this->assertFalse(\Installer::isValidPassword('short_one'),            '< 12 chars');
        $this->assertFalse(\Installer::isValidPassword(str_repeat('a', 11)),    'eleven');
        $this->assertFalse(\Installer::isValidPassword(' withleadingspace12 '), 'leading whitespace');
        $this->assertFalse(\Installer::isValidPassword("password123\t"),        'trailing tab');
    }

    public function test_is_valid_allowed_emails_requires_at_least_one_valid(): void
    {
        $this->assertTrue(\Installer::isValidAllowedEmails('alice@example.com'));
        $this->assertTrue(\Installer::isValidAllowedEmails('alice@example.com, bob@example.org'));
        $this->assertFalse(\Installer::isValidAllowedEmails(''),                      'empty');
        $this->assertFalse(\Installer::isValidAllowedEmails('not-an-email'),          'malformed');
        $this->assertFalse(\Installer::isValidAllowedEmails('alice@x, also-broken'),  'one bad fails all');
    }

    public function test_parse_env_file_round_trips_with_render_env(): void
    {
        $values = [
            'DB_HOST'              => 'db',
            'DB_NAME'              => 'piece',
            'DB_USER'              => 'user',
            'DB_PASS'              => 'has spaces and $signs',
            'SITE_URL'             => 'https://example.com',
            'GITHUB_CLIENT_ID'     => 'simple',
            'GITHUB_CLIENT_SECRET' => 'contains "quotes" and \\backslash',
            'ALLOWED_GITHUB_USERS' => 'alice,bob',
        ];
        $tmp = sys_get_temp_dir() . '/installer_envtrip_' . bin2hex(random_bytes(4)) . '.env';
        file_put_contents($tmp, \Installer::renderEnv($values));

        try {
            $parsed = \Installer::parseEnvFile($tmp);
            foreach ($values as $k => $v) {
                $this->assertSame($v, $parsed[$k], "round-trip mismatch on $k");
            }
        } finally {
            @unlink($tmp);
        }
    }
}
