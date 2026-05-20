<?php
/**
 * PageMeta — emits the SEO + social-share meta block (description, OpenGraph,
 * Twitter card) for public pages.
 *
 * Each public page calls `PageMeta::renderHead([...])` inside its `<head>`,
 * passing per-page overrides. Anything omitted falls back to defaults derived
 * from the site_name + tagline + hero_image settings.
 *
 *   <?= PageMeta::renderHead([
 *       'title'       => 'Portfolio — ' . setting('site_name'),
 *       'description' => 'A collection of handcrafted ceramics',
 *       'image'       => '/uploads/' . $piece['image_path'],   // optional
 *       'type'        => 'article',                            // default 'website'
 *   ]) ?>
 *
 * Image paths are resolved to absolute URLs (OpenGraph requires absolute).
 * The block does NOT emit <title> — pages keep writing that themselves
 * (they already do; PageMeta just complements with meta tags).
 */
final class PageMeta
{
    /**
     * @param array{
     *   title?:string, description?:string, image?:string, url?:string,
     *   type?:string, site_name?:string
     * } $opts
     */
    public static function renderHead(array $opts = []): string
    {
        $siteName = (string)($opts['site_name'] ?? (function_exists('setting') ? setting('site_name', 'My Pottery') : 'My Pottery'));
        $title    = (string)($opts['title']       ?? $siteName);
        $desc     = (string)($opts['description'] ?? (function_exists('setting') ? setting('tagline', '') : ''));
        $type     = (string)($opts['type']        ?? 'website');
        $url      = (string)($opts['url']         ?? self::currentUrl());
        $image    = self::toAbsoluteUrl((string)($opts['image'] ?? self::defaultImage()));

        $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $out = "\n";
        if ($desc !== '') {
            $out .= '<meta name="description" content="' . $h($desc) . '">' . "\n";
        }
        $out .= '<meta property="og:title" content="' . $h($title) . '">' . "\n";
        if ($desc !== '') {
            $out .= '<meta property="og:description" content="' . $h($desc) . '">' . "\n";
        }
        $out .= '<meta property="og:type" content="' . $h($type) . '">' . "\n";
        if ($url !== '') {
            $out .= '<meta property="og:url" content="' . $h($url) . '">' . "\n";
        }
        $out .= '<meta property="og:site_name" content="' . $h($siteName) . '">' . "\n";
        if ($image !== '') {
            $out .= '<meta property="og:image" content="' . $h($image) . '">' . "\n";
        }
        $out .= '<meta name="twitter:card" content="' . ($image !== '' ? 'summary_large_image' : 'summary') . '">' . "\n";
        $out .= '<meta name="twitter:title" content="' . $h($title) . '">' . "\n";
        if ($desc !== '') {
            $out .= '<meta name="twitter:description" content="' . $h($desc) . '">' . "\n";
        }
        if ($image !== '') {
            $out .= '<meta name="twitter:image" content="' . $h($image) . '">' . "\n";
        }
        return $out;
    }

    /** Site-wide default image (hero_image setting, if any). */
    public static function defaultImage(): string
    {
        if (!function_exists('setting')) return '';
        $hero = (string)setting('hero_image', '');
        return $hero !== '' ? '/uploads/' . ltrim($hero, '/') : '';
    }

    /** Build an absolute URL for the current request, honoring proxy headers. */
    public static function currentUrl(): string
    {
        $base   = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        $path   = $_SERVER['REQUEST_URI'] ?? '/';
        return $base . $path;
    }

    /**
     * Convert a relative image path ('/uploads/foo.jpg', 'pottery/foo.jpg') to
     * an absolute URL using SITE_URL. Already-absolute URLs pass through.
     */
    public static function toAbsoluteUrl(string $maybeRelative): string
    {
        if ($maybeRelative === '') return '';
        if (preg_match('#^https?://#i', $maybeRelative)) {
            return $maybeRelative;
        }
        if (!defined('SITE_URL')) return $maybeRelative;
        $base = rtrim(SITE_URL, '/');
        // Treat anything without a leading slash as relative to /uploads/.
        if ($maybeRelative[0] !== '/') {
            $maybeRelative = '/uploads/' . $maybeRelative;
        }
        return $base . $maybeRelative;
    }
}
