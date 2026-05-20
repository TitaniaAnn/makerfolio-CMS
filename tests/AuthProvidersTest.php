<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/AuthProviders.php';

/**
 * Pure-function tests for AuthProviders. The toggle/config getters depend on
 * the `setting()` helper from bootstrap.php (which depends on Database) and
 * are exercised end-to-end in the Docker smoke tests, not here.
 */
final class AuthProvidersTest extends TestCase
{
    public function test_parse_list_splits_on_commas_and_whitespace(): void
    {
        $this->assertSame(['alice'],            \AuthProviders::parseList('alice'));
        $this->assertSame(['alice', 'bob'],     \AuthProviders::parseList('alice,bob'));
        $this->assertSame(['alice', 'bob'],     \AuthProviders::parseList('alice, bob'));
        $this->assertSame(['alice', 'bob'],     \AuthProviders::parseList("alice\nbob"));
        $this->assertSame(['alice', 'bob'],     \AuthProviders::parseList('  alice ,, bob  '));
    }

    public function test_parse_list_returns_empty_for_blank_input(): void
    {
        $this->assertSame([], \AuthProviders::parseList(''));
        $this->assertSame([], \AuthProviders::parseList('   '));
        $this->assertSame([], \AuthProviders::parseList(','));
        $this->assertSame([], \AuthProviders::parseList(",, ,\n,"));
    }

    public function test_provider_keys_are_defined(): void
    {
        $this->assertSame('local',  \AuthProviders::LOCAL);
        $this->assertSame('github', \AuthProviders::GITHUB);
        $this->assertSame('google', \AuthProviders::GOOGLE);
    }
}
