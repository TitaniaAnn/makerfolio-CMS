<?php
/**
 * Theme — resolves the active theme from the `settings` table and renders the
 * CSS-variable / Google-Fonts blocks injected by templates/nav.php.
 *
 * The public site's existing stylesheet (public/css/style.css) declares a
 * fixed set of CSS variable names. This class re-binds those variable names
 * from a small palette (primary / accent / background / surface / text / cool)
 * so a single preset can drive every alias.
 *
 * Admin pages have their own palette and are NOT themed by this class.
 *
 * All admin writes go through validate*() — anything not in an allowlist is
 * rejected before reaching the DB or being emitted as CSS.
 */
final class Theme
{
    // Settings keys this class reads from / writes to.
    public const SETTING_PRESET             = 'theme_preset';
    public const SETTING_OVERRIDE_PRIMARY   = 'theme_override_primary';
    public const SETTING_OVERRIDE_ACCENT    = 'theme_override_accent';
    public const SETTING_OVERRIDE_BG        = 'theme_override_background';
    public const SETTING_OVERRIDE_TEXT      = 'theme_override_text';
    public const SETTING_FONT_DISPLAY       = 'theme_font_display';
    public const SETTING_FONT_BODY          = 'theme_font_body';
    public const SETTING_RADIUS_SCALE       = 'theme_radius_scale';
    public const SETTING_SHADOW_SCALE       = 'theme_shadow_scale';

    public const DEFAULT_PRESET = 'terra-gold';

    /**
     * Preset catalog. Each preset provides the six palette roles; everything
     * else (lighter/darker shades) is derived. `swatches` is what the admin UI
     * shows in the preset picker.
     */
    public static function presets(): array
    {
        return [
            'terra-gold' => [
                'label'      => 'Terra & Gold',
                'description'=> 'Warm gold on cream — the original pottery palette.',
                'palette' => [
                    'primary'    => '#D4A820',
                    'accent'     => '#E8C84A',
                    'background' => '#F8F6F0',
                    'surface'    => '#F0EDE5',
                    'text'       => '#1E2430',
                    'cool'       => '#3A4455',
                ],
            ],
            'cool-sage' => [
                'label'      => 'Cool Sage',
                'description'=> 'Muted green-grey on warm white. Calmer, more botanical.',
                'palette' => [
                    'primary'    => '#5A7560',
                    'accent'     => '#8FAA8F',
                    'background' => '#F4F2EC',
                    'surface'    => '#E8E6DC',
                    'text'       => '#1F2A1F',
                    'cool'       => '#2C3D2D',
                ],
            ],
            'monochrome' => [
                'label'      => 'Monochrome',
                'description'=> 'Charcoal on cream. Editorial, type-forward.',
                'palette' => [
                    'primary'    => '#2B2B2B',
                    'accent'     => '#7A7A7A',
                    'background' => '#FAFAF7',
                    'surface'    => '#EFEFEA',
                    'text'       => '#1A1A1A',
                    'cool'       => '#4A4A4A',
                ],
            ],
            'coastal-blue' => [
                'label'      => 'Coastal Blue',
                'description'=> 'Deep teal-blue on misty grey. Crisp and contemporary.',
                'palette' => [
                    'primary'    => '#2C5F7C',
                    'accent'     => '#4A90A4',
                    'background' => '#F4F7F9',
                    'surface'    => '#E6EDF1',
                    'text'       => '#1B2E3A',
                    'cool'       => '#1F3A4D',
                ],
            ],
        ];
    }

    /** Display-font allowlist (paired with Google Fonts query slugs). */
    public static function displayFonts(): array
    {
        return [
            'playfair-display'   => ['label' => 'Playfair Display', 'family' => "'Playfair Display', Georgia, serif", 'gf' => 'Playfair+Display:ital,wght@0,400;0,700;1,400;1,700'],
            'lora'               => ['label' => 'Lora',             'family' => "'Lora', Georgia, serif",             'gf' => 'Lora:ital,wght@0,400;0,700;1,400;1,700'],
            'cormorant-garamond' => ['label' => 'Cormorant Garamond','family' => "'Cormorant Garamond', Georgia, serif", 'gf' => 'Cormorant+Garamond:ital,wght@0,400;0,700;1,400;1,700'],
            'dm-serif-display'   => ['label' => 'DM Serif Display', 'family' => "'DM Serif Display', Georgia, serif",  'gf' => 'DM+Serif+Display:ital@0;1'],
        ];
    }

    /** Body-font allowlist (paired with Google Fonts query slugs). */
    public static function bodyFonts(): array
    {
        return [
            'nunito'         => ['label' => 'Nunito',         'family' => "'Nunito', 'Segoe UI', sans-serif",         'gf' => 'Nunito:wght@400;600;700'],
            'inter'          => ['label' => 'Inter',          'family' => "'Inter', 'Segoe UI', sans-serif",          'gf' => 'Inter:wght@400;600;700'],
            'source-sans-3'  => ['label' => 'Source Sans 3',  'family' => "'Source Sans 3', 'Segoe UI', sans-serif",  'gf' => 'Source+Sans+3:wght@400;600;700'],
            'work-sans'      => ['label' => 'Work Sans',      'family' => "'Work Sans', 'Segoe UI', sans-serif",      'gf' => 'Work+Sans:wght@400;600;700'],
        ];
    }

    public static function radiusScales(): array
    {
        return [
            'sharp'   => ['label' => 'Sharp',   'radius' => '0',    'radius_lg' => '0'],
            'default' => ['label' => 'Default', 'radius' => '8px',  'radius_lg' => '16px'],
            'soft'    => ['label' => 'Soft',    'radius' => '16px', 'radius_lg' => '24px'],
        ];
    }

    public static function shadowScales(): array
    {
        return [
            'flat'    => ['label' => 'Flat',    'shadow' => 'none',                           'shadow_lg' => 'none'],
            'default' => ['label' => 'Default', 'shadow' => '0 2px 16px rgba(30,36,48,.09)',  'shadow_lg' => '0 8px 40px rgba(30,36,48,.16)'],
            'lifted'  => ['label' => 'Lifted',  'shadow' => '0 4px 24px rgba(30,36,48,.14)',  'shadow_lg' => '0 16px 60px rgba(30,36,48,.22)'],
        ];
    }

    /**
     * Resolve the active theme: preset palette with overrides applied, plus
     * font and radius/shadow selections. Always returns a fully-populated array
     * (falls back to defaults if settings are missing).
     *
     * If `?_theme_preview=<base64-json>` is present in the current request AND
     * the viewer is an admin (Auth::isLoggedIn), the preview values override
     * the persisted ones for this render only — nothing is written to DB.
     * Used by the iframe-based live preview in /admin/settings/theme.php.
     */
    public static function current(): array
    {
        $preview = self::previewFromRequest();

        $presets = self::presets();
        $presetKey = $preview['preset'] ?? setting(self::SETTING_PRESET, self::DEFAULT_PRESET);
        if (!isset($presets[$presetKey])) {
            $presetKey = self::DEFAULT_PRESET;
        }
        $palette = $presets[$presetKey]['palette'];

        foreach ([
            'primary'    => self::SETTING_OVERRIDE_PRIMARY,
            'accent'     => self::SETTING_OVERRIDE_ACCENT,
            'background' => self::SETTING_OVERRIDE_BG,
            'text'       => self::SETTING_OVERRIDE_TEXT,
        ] as $role => $key) {
            $val = $preview['overrides'][$role] ?? setting($key, '');
            $val = trim((string)$val);
            if (self::isValidHex($val)) {
                $palette[$role] = strtoupper($val);
            }
        }

        $displayFonts = self::displayFonts();
        $bodyFonts    = self::bodyFonts();
        $displayKey   = $preview['fonts']['display'] ?? setting(self::SETTING_FONT_DISPLAY, 'playfair-display');
        $bodyKey      = $preview['fonts']['body']    ?? setting(self::SETTING_FONT_BODY,    'nunito');
        if (!isset($displayFonts[$displayKey])) $displayKey = 'playfair-display';
        if (!isset($bodyFonts[$bodyKey]))       $bodyKey    = 'nunito';

        $radiusScales = self::radiusScales();
        $shadowScales = self::shadowScales();
        $radiusKey    = $preview['radius'] ?? setting(self::SETTING_RADIUS_SCALE, 'default');
        $shadowKey    = $preview['shadow'] ?? setting(self::SETTING_SHADOW_SCALE, 'default');
        if (!isset($radiusScales[$radiusKey])) $radiusKey = 'default';
        if (!isset($shadowScales[$shadowKey])) $shadowKey = 'default';

        return [
            'preset_key'    => $presetKey,
            'palette'       => $palette,
            'display_font'  => ['key' => $displayKey] + $displayFonts[$displayKey],
            'body_font'     => ['key' => $bodyKey]    + $bodyFonts[$bodyKey],
            'radius'        => ['key' => $radiusKey]  + $radiusScales[$radiusKey],
            'shadow'        => ['key' => $shadowKey]  + $shadowScales[$shadowKey],
            'is_preview'    => $preview !== null,
        ];
    }

    /**
     * Decode + validate the `_theme_preview` query parameter when an admin is
     * logged in. Returns null (preview off) for anonymous requests, missing
     * param, decode failure, or unknown slugs — so a malformed/forged blob
     * silently falls back to the persisted theme.
     *
     * @return array{preset?:string, overrides?:array<string,string>, fonts?:array<string,string>, radius?:string, shadow?:string}|null
     */
    public static function previewFromRequest(): ?array
    {
        $blob = (string)($_GET['_theme_preview'] ?? '');
        if ($blob === '') return null;
        if (!class_exists('Auth') || !Auth::isLoggedIn()) return null;

        $json = base64_decode($blob, true);
        if ($json === false) return null;
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) return null;

        $out = [];
        if (isset($decoded['preset']) && isset(self::presets()[$decoded['preset']])) {
            $out['preset'] = $decoded['preset'];
        }
        if (isset($decoded['overrides']) && is_array($decoded['overrides'])) {
            $overrides = [];
            foreach (['primary', 'accent', 'background', 'text'] as $role) {
                $v = (string)($decoded['overrides'][$role] ?? '');
                if (self::isValidHex($v)) $overrides[$role] = strtoupper($v);
            }
            if ($overrides) $out['overrides'] = $overrides;
        }
        if (isset($decoded['fonts']) && is_array($decoded['fonts'])) {
            $fonts = [];
            $d = (string)($decoded['fonts']['display'] ?? '');
            $b = (string)($decoded['fonts']['body']    ?? '');
            if (isset(self::displayFonts()[$d])) $fonts['display'] = $d;
            if (isset(self::bodyFonts()[$b]))    $fonts['body']    = $b;
            if ($fonts) $out['fonts'] = $fonts;
        }
        if (isset($decoded['radius']) && isset(self::radiusScales()[$decoded['radius']])) {
            $out['radius'] = $decoded['radius'];
        }
        if (isset($decoded['shadow']) && isset(self::shadowScales()[$decoded['shadow']])) {
            $out['shadow'] = $decoded['shadow'];
        }
        return $out;
    }

    /**
     * Emit the `<style>` block that remaps style.css's CSS variables to the
     * active theme. Returned as a complete element (open/close tags included).
     */
    public static function styleBlock(): string
    {
        $t = self::current();
        $p = $t['palette'];

        // Derived shades for primary / accent / cool (-lt is lighter, -dk is darker).
        $primaryLt = self::shade($p['primary'],  0.20);
        $primaryDk = self::shade($p['primary'], -0.20);
        $accentLt  = self::shade($p['accent'],   0.20);
        $accentDk  = self::shade($p['accent'],  -0.20);
        $coolLt    = self::shade($p['cool'],     0.20);
        $coolDk    = self::shade($p['cool'],    -0.20);
        $coolPale  = self::shade($p['cool'],     0.85);
        $textLt    = self::shade($p['text'],     0.30);
        $surfaceDk = self::shade($p['surface'], -0.05);
        $surfaceLn = self::shade($p['surface'], -0.10);
        $bgSoft    = self::shade($p['background'], 0.05);

        $css = <<<CSS
:root {
    --terra: {$p['primary']}; --terra-lt: {$primaryLt}; --terra-dk: {$primaryDk};
    --rose: {$p['primary']};  --rose-lt: {$primaryLt};  --rose-dk: {$primaryDk};
    --clay: {$p['primary']};  --clay-lt: {$accentLt};
    --amber: {$p['accent']};
    --parchment: {$p['background']}; --cream: {$p['background']}; --cream-dk: {$surfaceDk};
    --warm-white: {$bgSoft};
    --sand: {$p['surface']}; --sand-dk: {$surfaceDk}; --linen: {$surfaceLn};
    --bark: {$p['text']}; --bark-lt: {$textLt}; --ink: {$p['text']}; --ink-lt: {$textLt};
    --stone: {$coolLt};
    --sage: {$p['cool']}; --sage-lt: {$coolLt}; --sage-dk: {$coolDk}; --sage-pale: {$coolPale};
    --moss: {$p['cool']}; --moss-lt: {$coolLt}; --moss-dk: {$coolDk}; --moss-pale: {$coolPale};
    --font-display: {$t['display_font']['family']};
    --font-body:    {$t['body_font']['family']};
    --radius:    {$t['radius']['radius']};
    --radius-lg: {$t['radius']['radius_lg']};
    --shadow:    {$t['shadow']['shadow']};
    --shadow-lg: {$t['shadow']['shadow_lg']};
}
CSS;
        return "<style id=\"theme-overrides\">{$css}</style>";
    }

    /**
     * Google Fonts `<link>` for the active display + body fonts. The script
     * font (Caveat) is always included since it's not themeable but is used by
     * style.css for accents.
     */
    public static function googleFontsLink(): string
    {
        $t = self::current();
        $families = [
            $t['display_font']['gf'],
            $t['body_font']['gf'],
            'Caveat:wght@400;600',
        ];
        $query = 'family=' . implode('&family=', $families) . '&display=swap';
        return '<link href="https://fonts.googleapis.com/css2?' . htmlspecialchars($query, ENT_QUOTES, 'UTF-8') . '" rel="stylesheet">';
    }

    // --- Validation helpers (used by the admin save handler) -----------------

    public static function isValidHex(string $v): bool
    {
        return (bool)preg_match('/^#[0-9a-fA-F]{6}$/', $v);
    }

    public static function isValidPreset(string $v): bool   { return isset(self::presets()[$v]); }
    public static function isValidDisplayFont(string $v): bool { return isset(self::displayFonts()[$v]); }
    public static function isValidBodyFont(string $v): bool    { return isset(self::bodyFonts()[$v]); }
    public static function isValidRadiusScale(string $v): bool { return isset(self::radiusScales()[$v]); }
    public static function isValidShadowScale(string $v): bool { return isset(self::shadowScales()[$v]); }

    /**
     * Blend a hex color toward white (factor > 0) or black (factor < 0).
     * factor is clamped to [-1, 1]. Used to derive -lt / -dk variants.
     */
    public static function shade(string $hex, float $factor): string
    {
        if (!self::isValidHex($hex)) {
            return $hex;
        }
        $factor = max(-1.0, min(1.0, $factor));
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        if ($factor >= 0) {
            $r = (int)round($r + (255 - $r) * $factor);
            $g = (int)round($g + (255 - $g) * $factor);
            $b = (int)round($b + (255 - $b) * $factor);
        } else {
            $f = 1 + $factor;
            $r = (int)round($r * $f);
            $g = (int)round($g * $f);
            $b = (int)round($b * $f);
        }
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }
}
