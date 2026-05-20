<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/SampleContent.php';

/**
 * SampleContent — covers the pure helpers and the catalog sanity.
 *
 * The DB-touching seed() path is exercised by the Docker smoke verification,
 * not here — consistent with how MigrationRunner / Database / ContentReset
 * are handled (no unit tests for live DB code).
 */
final class SampleContentTest extends TestCase
{
    public function test_catalog_constants_are_populated(): void
    {
        // Sanity: the seeder isn't useful if any of these are empty.
        $this->assertNotEmpty(\SampleContent::SAMPLE_POTTERY);
        $this->assertNotEmpty(\SampleContent::SAMPLE_EVENTS);
        $this->assertNotEmpty(\SampleContent::SAMPLE_ANNOUNCEMENTS);
        $this->assertNotEmpty(\SampleContent::SAMPLE_PRODUCTS);
        $this->assertNotEmpty(\SampleContent::SAMPLE_SOCIAL_LINKS);
    }

    public function test_pottery_entries_have_required_keys(): void
    {
        // Every pottery row needs the keys the seeder reads. Without this
        // sanity check, a typo in the const would only surface at runtime.
        $required = ['title', 'technique', 'dimensions', 'year', 'featured', 'color', 'description'];
        foreach (\SampleContent::SAMPLE_POTTERY as $i => $row) {
            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $row, "SAMPLE_POTTERY[$i] missing '$key'");
            }
            $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $row['color'], "SAMPLE_POTTERY[$i] color must be 6-digit hex");
        }
    }

    public function test_event_entries_have_required_keys_and_valid_types(): void
    {
        $validTypes = ['pottery_show', 'pottery_sale', 'storefront_sale', 'class'];
        $required   = ['event_type', 'name', 'location', 'description', 'days_out', 'duration_days', 'featured'];
        foreach (\SampleContent::SAMPLE_EVENTS as $i => $row) {
            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $row, "SAMPLE_EVENTS[$i] missing '$key'");
            }
            $this->assertContains($row['event_type'], $validTypes, "SAMPLE_EVENTS[$i] uses unknown event_type");
        }
    }

    public function test_announcements_reference_real_events(): void
    {
        // A linked_event name that doesn't match any SAMPLE_EVENTS entry would
        // silently produce an announcement with no link — surface that here.
        $eventNames = array_column(\SampleContent::SAMPLE_EVENTS, 'name');
        foreach (\SampleContent::SAMPLE_ANNOUNCEMENTS as $i => $ann) {
            if ($ann['linked_event'] !== null) {
                $this->assertContains(
                    $ann['linked_event'],
                    $eventNames,
                    "SAMPLE_ANNOUNCEMENTS[$i] linked_event refers to unknown event"
                );
            }
        }
    }

    public function test_products_use_known_category_slugs(): void
    {
        // shop_categories ships with these slugs in init.sql + ContentReset
        // reseeds. A typo here would silently NULL the category_id on insert.
        $knownSlugs = ['original-pots', 'studio-merch'];
        foreach (\SampleContent::SAMPLE_PRODUCTS as $i => $p) {
            $this->assertContains($p['category_slug'], $knownSlugs, "SAMPLE_PRODUCTS[$i] category_slug not in shop_categories defaults");
            $this->assertContains($p['status'], ['available', 'sold', 'coming_soon'], "SAMPLE_PRODUCTS[$i] invalid status");
            $this->assertContains($p['type'], ['pot', 'merch'], "SAMPLE_PRODUCTS[$i] invalid type");
        }
    }

    public function test_svg_filename_is_slug_safe(): void
    {
        $this->assertSame('sample-river-stone-vase.svg', \SampleContent::svgFilenameFor('pottery', 'River Stone Vase'));
        $this->assertSame('sample-tea-bowl-2025.svg',   \SampleContent::svgFilenameFor('pottery', 'Tea Bowl 2025'));
        // Pure punctuation gets stripped down to the kind fallback.
        $this->assertSame('sample-product.svg', \SampleContent::svgFilenameFor('product', '!!!'));
        // Non-ASCII collapses to the fallback rather than producing a confusing slug.
        $this->assertSame('sample-pottery.svg', \SampleContent::svgFilenameFor('pottery', '陶器'));
    }

    public function test_contrasting_text_color_picks_dark_on_light_bg(): void
    {
        // The threshold is luminance > 0.55; cream + sand should pick dark text.
        $this->assertSame('#3a2e22', \SampleContent::contrastingTextColor('#fdf8ef'));
        $this->assertSame('#3a2e22', \SampleContent::contrastingTextColor('#c9b58a'));
    }

    public function test_contrasting_text_color_picks_light_on_dark_bg(): void
    {
        $this->assertSame('#fdf8ef', \SampleContent::contrastingTextColor('#5c4a3a'));
        $this->assertSame('#fdf8ef', \SampleContent::contrastingTextColor('#bf6b45'));
    }

    public function test_contrasting_text_color_handles_malformed_input(): void
    {
        // Not a 6-digit hex → safe fallback rather than a PHP warning.
        $this->assertSame('#222', \SampleContent::contrastingTextColor('not-a-color'));
        $this->assertSame('#222', \SampleContent::contrastingTextColor('#fff'));
    }

    public function test_write_svg_placeholder_produces_valid_svg(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sc_svg_') . '.svg';
        try {
            \SampleContent::writeSvgPlaceholder($tmp, '#7a8068', 'Test Title');
            $svg = file_get_contents($tmp);
            $this->assertNotFalse($svg);
            $this->assertStringStartsWith('<svg', $svg);
            $this->assertStringContainsString('Test Title', $svg);
            $this->assertStringContainsString('#7a8068', $svg);
            $this->assertStringEndsWith('</svg>', trim($svg));
        } finally {
            @unlink($tmp);
        }
    }

    public function test_write_svg_placeholder_escapes_xml_special_chars(): void
    {
        // A title containing < or & must be escaped or the SVG won't parse.
        $tmp = tempnam(sys_get_temp_dir(), 'sc_svg_') . '.svg';
        try {
            \SampleContent::writeSvgPlaceholder($tmp, '#7a8068', 'A & B <c>');
            $svg = file_get_contents($tmp);
            $this->assertStringContainsString('&amp;', $svg);
            $this->assertStringContainsString('&lt;c&gt;', $svg);
            $this->assertStringNotContainsString('<c>', $svg, 'Raw <c> would break SVG parsing');
        } finally {
            @unlink($tmp);
        }
    }
}
