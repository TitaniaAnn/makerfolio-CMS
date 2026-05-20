<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/Totp.php';

/**
 * Pure-function tests for Totp. The RFC 6238 vectors below are the canonical
 * test cases; any TOTP implementation that disagrees with them is wrong.
 */
final class TotpTest extends TestCase
{
    public function test_base32_round_trips_arbitrary_bytes(): void
    {
        $samples = [
            "\x00",
            "\xFF",
            "\x00\xFF\x00\xFF\x00",
            random_bytes(20),
            random_bytes(31),
        ];
        foreach ($samples as $raw) {
            $encoded = \Totp::base32Encode($raw);
            $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $encoded);
            $this->assertSame($raw, \Totp::base32Decode($encoded));
        }
    }

    public function test_base32_decode_is_padding_and_case_tolerant(): void
    {
        $raw = "\x48\x65\x6c\x6c\x6f"; // "Hello"
        $b32 = \Totp::base32Encode($raw);  // "JBSWY3DPEE" + padding
        $this->assertSame($raw, \Totp::base32Decode($b32 . '======'));
        $this->assertSame($raw, \Totp::base32Decode(strtolower($b32)));
    }

    public function test_base32_decode_throws_on_invalid_char(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid base32 character');
        \Totp::base32Decode('JBSWY3DP!!');
    }

    public function test_generate_secret_returns_valid_base32_of_expected_length(): void
    {
        $secret = \Totp::generateSecret();
        // 20 bytes → 160 bits → 32 base32 chars (no padding in our encoder).
        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        // Two consecutive generations must differ (random_bytes is cryptographic).
        $this->assertNotSame($secret, \Totp::generateSecret());
    }

    /**
     * RFC 6238 §B test vectors using key "12345678901234567890" (ASCII).
     * The base32 encoding of that key is "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ".
     * Codes are computed at specific timesteps.
     */
    public function test_rfc6238_test_vectors_with_sha1(): void
    {
        $secret = \Totp::base32Encode('12345678901234567890');

        // (unix_time, expected 6-digit code) — from RFC 6238 Appendix B, SHA1 column.
        $vectors = [
            [59,          '94287082'],
            [1111111109,  '07081804'],
            [1111111111,  '14050471'],
            [1234567890,  '89005924'],
            [2000000000,  '69279037'],
        ];
        foreach ($vectors as [$ts, $expected8]) {
            // RFC vectors use 8 digits; our impl uses 6 — compare the last 6.
            $expected6 = substr($expected8, -6);
            $timestep  = intdiv($ts, 30);
            $this->assertSame($expected6, \Totp::computeCode($secret, $timestep));
        }
    }

    public function test_verify_code_accepts_current_timestep(): void
    {
        $secret = \Totp::generateSecret();
        $now    = intdiv(time(), 30);
        $code   = \Totp::computeCode($secret, $now);
        $this->assertTrue(\Totp::verifyCode($secret, $code));
    }

    public function test_verify_code_accepts_previous_and_next_timestep_by_default(): void
    {
        $secret = \Totp::generateSecret();
        $now    = intdiv(time(), 30);

        $prev = \Totp::computeCode($secret, $now - 1);
        $next = \Totp::computeCode($secret, $now + 1);

        $this->assertTrue(\Totp::verifyCode($secret, $prev), 'prev timestep should be accepted');
        $this->assertTrue(\Totp::verifyCode($secret, $next), 'next timestep should be accepted');
    }

    public function test_verify_code_rejects_far_out_of_window_timestep(): void
    {
        $secret = \Totp::generateSecret();
        $now    = intdiv(time(), 30);
        $far    = \Totp::computeCode($secret, $now + 5);
        $this->assertFalse(\Totp::verifyCode($secret, $far));
    }

    public function test_verify_code_rejects_malformed_input(): void
    {
        $secret = \Totp::generateSecret();
        $this->assertFalse(\Totp::verifyCode($secret, ''));
        $this->assertFalse(\Totp::verifyCode($secret, '12345'));    // 5 digits
        $this->assertFalse(\Totp::verifyCode($secret, '1234567'));  // 7 digits
        $this->assertFalse(\Totp::verifyCode($secret, 'abcdef'));   // non-digits
    }

    public function test_otpauth_uri_includes_all_required_params(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $uri    = \Totp::otpauthUri($secret, 'alice@example.com', 'My Pottery');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('My%20Pottery:alice%40example.com', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
        $this->assertStringContainsString('issuer=My+Pottery', $uri);
        $this->assertStringContainsString('algorithm=SHA1', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    public function test_format_secret_for_display_groups_in_fours(): void
    {
        $this->assertSame('ABCD EFGH IJKL MNOP', \Totp::formatSecretForDisplay('ABCDEFGHIJKLMNOP'));
        $this->assertSame('ABCD EFGH IJ', \Totp::formatSecretForDisplay('abcdefghij'));
    }

    public function test_recovery_codes_are_unique_and_in_safe_alphabet(): void
    {
        $codes = \Totp::generateRecoveryCodes(10);
        $this->assertCount(10, $codes);
        $this->assertSame(10, count(array_unique($codes)), 'recovery codes must be unique');
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}$/', $code,
                "Code '$code' must be XXXXX-XXXXX from no-look-alike alphabet (no 0/O/1/I/L)");
        }
    }

    public function test_recovery_code_lookup_round_trips(): void
    {
        $plain  = \Totp::generateRecoveryCodes(5);
        $hashes = \Totp::hashRecoveryCodes($plain);
        $this->assertCount(5, $hashes);

        // Each plain code finds its corresponding hash.
        foreach ($plain as $i => $code) {
            $this->assertSame($i, \Totp::findRecoveryCode($code, $hashes), "Plain code at index $i must match");
        }
        // Case-insensitive match (codes are uppercased during hash + verify).
        $this->assertSame(0, \Totp::findRecoveryCode(strtolower($plain[0]), $hashes));
        // Unknown code → null.
        $this->assertNull(\Totp::findRecoveryCode('ZZZZZ-ZZZZZ', $hashes));
        // Empty input → null without checking any hash.
        $this->assertNull(\Totp::findRecoveryCode('', $hashes));
    }

    public function test_recovery_code_lookup_skips_consumed_entries(): void
    {
        $plain  = \Totp::generateRecoveryCodes(3);
        $hashes = \Totp::hashRecoveryCodes($plain);
        $hashes[1] = ''; // Mark index 1 as consumed.
        $this->assertNull(\Totp::findRecoveryCode($plain[1], $hashes), 'consumed slot must not match');
        $this->assertSame(0, \Totp::findRecoveryCode($plain[0], $hashes));
        $this->assertSame(2, \Totp::findRecoveryCode($plain[2], $hashes));
    }
}
