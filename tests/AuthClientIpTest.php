<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

// Auth is already loaded by tests/bootstrap.php.

/**
 * Auth::clientIp / isTrustedProxyIp — the rate-limit-bypass fix.
 *
 * The defining property: when REMOTE_ADDR is not a trusted proxy, XFF must be
 * ignored regardless of header content. Otherwise an attacker spoofs XFF and
 * gets unlimited login attempts.
 */
final class AuthClientIpTest extends TestCase
{
    protected function setUp(): void
    {
        // Tests mutate $_SERVER — reset between cases.
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function test_loopback_is_always_trusted(): void
    {
        $this->assertTrue(\Auth::isTrustedProxyIp('127.0.0.1'));
        $this->assertTrue(\Auth::isTrustedProxyIp('::1'));
    }

    public function test_configured_proxies_are_trusted(): void
    {
        // tests/bootstrap.php seeds TRUSTED_PROXIES with these.
        $this->assertTrue(\Auth::isTrustedProxyIp('10.0.0.5'));
        $this->assertTrue(\Auth::isTrustedProxyIp('2001:db8::1'));
    }

    public function test_arbitrary_ip_is_not_trusted(): void
    {
        $this->assertFalse(\Auth::isTrustedProxyIp('192.0.2.1'));
        $this->assertFalse(\Auth::isTrustedProxyIp(''));
        $this->assertFalse(\Auth::isTrustedProxyIp('10.0.0.6'),  'one off from the trusted list');
    }

    public function test_xff_is_ignored_when_remote_is_untrusted(): void
    {
        // The classic spoof: attacker sends XFF claiming to be a different IP
        // on each request. Server must ignore it because REMOTE_ADDR isn't a
        // configured proxy. Pre-fix this returned the spoofed XFF value.
        $_SERVER['REMOTE_ADDR']          = '198.51.100.42';   // attacker's real IP
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';    // spoofed claim
        $this->assertSame('198.51.100.42', \Auth::clientIp());
    }

    public function test_xff_is_honored_when_remote_is_a_trusted_proxy(): void
    {
        // Typical reverse-proxy setup: REMOTE_ADDR is loopback, XFF carries the real client.
        $_SERVER['REMOTE_ADDR']          = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
        $this->assertSame('203.0.113.99', \Auth::clientIp());
    }

    public function test_xff_uses_leftmost_entry_when_proxy_chain_present(): void
    {
        // Proxy chains append, so the leftmost entry is the originating client.
        $_SERVER['REMOTE_ADDR']          = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99, 10.0.0.5, 127.0.0.1';
        $this->assertSame('203.0.113.99', \Auth::clientIp());
    }

    public function test_empty_xff_with_trusted_proxy_falls_back_to_remote(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        // No XFF header set.
        $this->assertSame('127.0.0.1', \Auth::clientIp());
    }

    public function test_missing_remote_addr_returns_empty_string(): void
    {
        // CLI / phpunit context where $_SERVER isn't populated.
        $this->assertSame('', \Auth::clientIp());
    }
}
