<?php
/**
 * AuthProviders — registry that resolves which login methods are enabled and
 * provides their configuration. All config lives in the `settings` table
 * (seeded by migration 015 and bootstrapped from .env by the auto-migrate
 * entrypoint).
 *
 * A provider is "enabled" when its master toggle setting is '1'. A provider
 * is "configured" when all its credential rows are non-empty. The login UI
 * shows a provider only when it is BOTH enabled and configured.
 *
 * Currently supported providers: 'local' (username/password), 'github' (OAuth),
 * 'google' (OAuth).
 */
final class AuthProviders
{
    public const LOCAL  = 'local';
    public const GITHUB = 'github';
    public const GOOGLE = 'google';

    // -- Master toggles ------------------------------------------------------

    public static function isLocalEnabled(): bool
    {
        return setting('auth_local_enabled', '1') === '1';
    }

    public static function isGithubEnabled(): bool
    {
        if (setting('auth_github_enabled', '0') !== '1') return false;
        $cfg = self::githubConfigRaw();
        return $cfg['client_id'] !== '' && $cfg['client_secret'] !== '' && $cfg['allowed_users'] !== '';
    }

    public static function isGoogleEnabled(): bool
    {
        if (setting('auth_google_enabled', '0') !== '1') return false;
        $cfg = self::googleConfigRaw();
        return $cfg['client_id'] !== '' && $cfg['client_secret'] !== '' && $cfg['allowed_emails'] !== '';
    }

    /** @return string[] List of currently active provider keys, in display order. */
    public static function enabledProviders(): array
    {
        $out = [];
        if (self::isLocalEnabled())  $out[] = self::LOCAL;
        if (self::isGithubEnabled()) $out[] = self::GITHUB;
        if (self::isGoogleEnabled()) $out[] = self::GOOGLE;
        return $out;
    }

    // -- Configs -------------------------------------------------------------

    /**
     * @return array{client_id:string, client_secret:string, allowed_users:string[], redirect_uri:string}|null
     *         null when the provider is disabled or not configured.
     */
    public static function githubConfig(): ?array
    {
        if (!self::isGithubEnabled()) return null;
        $raw = self::githubConfigRaw();
        return [
            'client_id'     => $raw['client_id'],
            'client_secret' => $raw['client_secret'],
            'allowed_users' => self::parseList($raw['allowed_users']),
            'redirect_uri'  => self::githubRedirectUri(),
        ];
    }

    /**
     * @return array{client_id:string, client_secret:string, allowed_emails:string[], redirect_uri:string}|null
     */
    public static function googleConfig(): ?array
    {
        if (!self::isGoogleEnabled()) return null;
        $raw = self::googleConfigRaw();
        return [
            'client_id'      => $raw['client_id'],
            'client_secret'  => $raw['client_secret'],
            'allowed_emails' => self::parseList($raw['allowed_emails']),
            'redirect_uri'   => self::googleRedirectUri(),
        ];
    }

    public static function githubRedirectUri(): string
    {
        return rtrim(SITE_URL, '/') . '/admin/auth/callback.php';
    }

    public static function googleRedirectUri(): string
    {
        return rtrim(SITE_URL, '/') . '/admin/auth/google-callback.php';
    }

    // -- Internals -----------------------------------------------------------

    /** @return array{client_id:string,client_secret:string,allowed_users:string} */
    private static function githubConfigRaw(): array
    {
        return [
            'client_id'     => trim((string)setting('auth_github_client_id', '')),
            'client_secret' => trim((string)setting('auth_github_client_secret', '')),
            'allowed_users' => trim((string)setting('auth_github_allowed_users', '')),
        ];
    }

    /** @return array{client_id:string,client_secret:string,allowed_emails:string} */
    private static function googleConfigRaw(): array
    {
        return [
            'client_id'      => trim((string)setting('auth_google_client_id', '')),
            'client_secret'  => trim((string)setting('auth_google_client_secret', '')),
            'allowed_emails' => trim((string)setting('auth_google_allowed_emails', '')),
        ];
    }

    /** Split a comma/whitespace separated list, drop empty entries. */
    public static function parseList(string $v): array
    {
        $tokens = preg_split('/[\s,]+/', trim($v)) ?: [];
        return array_values(array_filter($tokens, fn($t) => $t !== ''));
    }
}
