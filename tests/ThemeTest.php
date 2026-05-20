<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

// Theme is the unit under test. It defines a pure class with no top-level
// side effects, so requiring it here is safe even though it would normally
// be pulled in by bootstrap.php (which we don't load in tests).
require_once __DIR__ . '/../includes/Theme.php';

final class ThemeTest extends TestCase
{
    public function test_is_valid_hex_accepts_six_digit_hex_with_hash(): void
    {
        $this->assertTrue(\Theme::isValidHex('#000000'));
        $this->assertTrue(\Theme::isValidHex('#D4A820'));
        $this->assertTrue(\Theme::isValidHex('#abcdef'));
    }

    public function test_is_valid_hex_rejects_malformed_input(): void
    {
        $this->assertFalse(\Theme::isValidHex(''));
        $this->assertFalse(\Theme::isValidHex('D4A820'),       'missing #');
        $this->assertFalse(\Theme::isValidHex('#D4A82'),       'five digits');
        $this->assertFalse(\Theme::isValidHex('#D4A8200'),     'seven digits');
        $this->assertFalse(\Theme::isValidHex('#ZZZZZZ'),      'non-hex chars');
        $this->assertFalse(\Theme::isValidHex('rgb(0,0,0)'),   'rgb() form');
    }

    public function test_preset_validation_uses_allowlist(): void
    {
        $this->assertTrue(\Theme::isValidPreset('terra-gold'));
        $this->assertTrue(\Theme::isValidPreset('cool-sage'));
        $this->assertFalse(\Theme::isValidPreset('not-a-preset'));
        $this->assertFalse(\Theme::isValidPreset(''));
    }

    public function test_font_and_scale_validation_uses_allowlists(): void
    {
        $this->assertTrue(\Theme::isValidDisplayFont('playfair-display'));
        $this->assertFalse(\Theme::isValidDisplayFont('comic-sans'));

        $this->assertTrue(\Theme::isValidBodyFont('nunito'));
        $this->assertFalse(\Theme::isValidBodyFont('papyrus'));

        $this->assertTrue(\Theme::isValidRadiusScale('sharp'));
        $this->assertTrue(\Theme::isValidRadiusScale('default'));
        $this->assertTrue(\Theme::isValidRadiusScale('soft'));
        $this->assertFalse(\Theme::isValidRadiusScale('huge'));

        $this->assertTrue(\Theme::isValidShadowScale('lifted'));
        $this->assertFalse(\Theme::isValidShadowScale('extreme'));
    }

    public function test_shade_positive_factor_blends_toward_white(): void
    {
        // shade(black, +1) → white
        $this->assertSame('#FFFFFF', \Theme::shade('#000000', 1.0));
        // shade(midgray, +0.5) → lighter
        $lighter = \Theme::shade('#808080', 0.5);
        [$r] = sscanf($lighter, '#%02x%02x%02x');
        $this->assertGreaterThan(0x80, $r);
    }

    public function test_shade_negative_factor_blends_toward_black(): void
    {
        $this->assertSame('#000000', \Theme::shade('#FFFFFF', -1.0));
        $darker = \Theme::shade('#808080', -0.5);
        [$r] = sscanf($darker, '#%02x%02x%02x');
        $this->assertLessThan(0x80, $r);
    }

    public function test_shade_zero_factor_is_identity(): void
    {
        $this->assertSame('#D4A820', \Theme::shade('#D4A820', 0.0));
    }

    public function test_shade_returns_input_unchanged_for_invalid_hex(): void
    {
        // Defensive guard — callers shouldn't rely on this, but it prevents
        // a sscanf warning from polluting the page if a bad value sneaks in.
        $this->assertSame('not-a-color', \Theme::shade('not-a-color', 0.5));
    }

    public function test_shade_clamps_factor_to_unit_range(): void
    {
        // factor > 1 is clamped to 1 → white
        $this->assertSame('#FFFFFF', \Theme::shade('#123456', 5.0));
        // factor < -1 is clamped to -1 → black
        $this->assertSame('#000000', \Theme::shade('#ABCDEF', -2.0));
    }
}
