<?php
/**
 * Totp — RFC 6238 TOTP implementation for the 2FA challenge.
 *
 * Self-contained, no composer deps. Uses HMAC-SHA1 with a 30-second timestep
 * and 6-digit codes (the standard combination every authenticator app speaks).
 *
 * Convention for this codebase:
 *   - Secrets are stored base32-encoded in admin_users.totp_secret (so they
 *     fit in any printable text column and round-trip through otpauth URIs).
 *   - Code verification accepts ±1 timestep for clock drift (±30 seconds total).
 *   - Recovery codes are 10 single-use codes, each 10 chars from a no-look-alike
 *     alphabet, stored as bcrypt hashes in admin_users.recovery_codes_hash
 *     (JSON array). A used code is replaced with an empty string so the array
 *     index stays stable.
 *
 * NO QR code generation — the enrollment page surfaces the secret as plain
 * text + an `otpauth://` deep link that mobile authenticator apps recognize.
 * Adding a QR code later means dropping in a JS lib at the enrollment page;
 * this class doesn't care either way.
 */
final class Totp
{
    public const PERIOD = 30;           // seconds per timestep
    public const DIGITS = 6;
    public const HASH_ALGO = 'sha1';    // every authenticator supports this; sha256 is optional but breaks some

    /** Generate a fresh 20-byte secret, base32-encoded. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Compute the current 6-digit TOTP code for $base32Secret at the given
     * timestep (defaults to now). Returns a zero-padded string.
     */
    public static function computeCode(string $base32Secret, ?int $timestep = null): string
    {
        $timestep ??= intdiv(time(), self::PERIOD);
        $key = self::base32Decode($base32Secret);

        // 8-byte big-endian timestep counter.
        $counter = pack('J', $timestep);
        $hmac = hash_hmac(self::HASH_ALGO, $counter, $key, true);

        // Dynamic truncation per RFC 4226 §5.3.
        $offset    = ord($hmac[strlen($hmac) - 1]) & 0x0F;
        $binary    = ((ord($hmac[$offset])     & 0x7F) << 24)
                   | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
                   | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
                   |  (ord($hmac[$offset + 3]) & 0xFF);
        $code = $binary % (10 ** self::DIGITS);

        return str_pad((string)$code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * True when $code matches the current timestep ±$window steps. The default
     * window of 1 accepts the previous, current, and next 30-second slots
     * (~90s wall-clock window) to tolerate device clock skew.
     */
    public static function verifyCode(string $base32Secret, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) return false;
        $now = intdiv(time(), self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::computeCode($base32Secret, $now + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build the otpauth:// URI authenticator apps consume.
     *
     *   otpauth://totp/<issuer>:<account>?secret=<base32>&issuer=<issuer>&algorithm=SHA1&digits=6&period=30
     */
    public static function otpauthUri(string $base32Secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);
        $params = http_build_query([
            'secret'    => $base32Secret,
            'issuer'    => $issuer,
            'algorithm' => strtoupper(self::HASH_ALGO),
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }

    /** Format a base32 secret as space-separated groups of 4 for manual entry. */
    public static function formatSecretForDisplay(string $base32Secret): string
    {
        return trim(chunk_split(strtoupper($base32Secret), 4, ' '));
    }

    // -- Recovery codes ------------------------------------------------------

    /**
     * Generate $count fresh recovery codes — each is a 10-character string
     * from a no-look-alike alphabet (no 0/O/1/I/L). Return them in plain form;
     * caller is responsible for hashing them before storage AND showing them
     * to the user exactly once.
     *
     * @return string[]
     */
    public static function generateRecoveryCodes(int $count = 10): array
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $alLen    = strlen($alphabet);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $code = '';
            for ($j = 0; $j < 10; $j++) {
                $code .= $alphabet[random_int(0, $alLen - 1)];
            }
            // Group as XXXXX-XXXXX for readability.
            $out[] = substr($code, 0, 5) . '-' . substr($code, 5, 5);
        }
        return $out;
    }

    /** bcrypt each plain code (case-insensitive — we uppercase on verify). */
    public static function hashRecoveryCodes(array $plain): array
    {
        $out = [];
        foreach ($plain as $code) {
            $out[] = password_hash(strtoupper(trim($code)), PASSWORD_DEFAULT);
        }
        return $out;
    }

    /**
     * Returns the index of the matching hash if $code matches any non-empty
     * entry in $hashes; null otherwise. The matched index is what callers
     * blank out to mark the code consumed.
     *
     * Constant-time iteration: walks every slot even after finding a match,
     * so an attacker can't time-side-channel which slot held the matching
     * code (which would narrow the brute-force search space).
     * password_verify() itself is already timing-safe per-call.
     */
    public static function findRecoveryCode(string $code, array $hashes): ?int
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') return null;
        $matchedIndex = null;
        foreach ($hashes as $i => $hash) {
            if (!is_string($hash) || $hash === '') continue;
            if (password_verify($normalized, $hash) && $matchedIndex === null) {
                $matchedIndex = (int)$i;
                // Don't return — keep iterating so total time is independent
                // of which slot matched.
            }
        }
        return $matchedIndex;
    }

    // -- base32 (RFC 4648, no padding on encode; tolerant of padding on decode) ----

    public static function base32Encode(string $raw): string
    {
        if ($raw === '') return '';
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bin = '';
        for ($i = 0, $n = strlen($raw); $i < $n; $i++) {
            $bin .= str_pad(decbin(ord($raw[$i])), 8, '0', STR_PAD_LEFT);
        }
        // Pad to a multiple of 5 bits.
        $bin = str_pad($bin, (int)ceil(strlen($bin) / 5) * 5, '0', STR_PAD_RIGHT);

        $out = '';
        foreach (str_split($bin, 5) as $group) {
            $out .= $alphabet[bindec($group)];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(rtrim($b32, "= \t\r\n"));
        if ($b32 === '') return '';

        $bin = '';
        for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
            $pos = strpos($alphabet, $b32[$i]);
            if ($pos === false) {
                throw new \InvalidArgumentException("Invalid base32 character: {$b32[$i]}");
            }
            $bin .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bin, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
