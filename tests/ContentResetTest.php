<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/ContentReset.php';

/**
 * Sanity tests for the ContentReset catalog. Real wipe behaviour (DB + FS) is
 * exercised by the Docker smoke verification — these tests just make sure the
 * static lists stay coherent and protect what they should.
 */
final class ContentResetTest extends TestCase
{
    /**
     * The most important property: ContentReset must NEVER touch admin_users,
     * schema_migrations, or auth-related settings. A reviewer adding a new
     * table to CONTENT_TABLES should think twice before doing it — this test
     * fails loudly if the wrong table sneaks in.
     */
    public function test_content_tables_never_include_auth_or_infrastructure(): void
    {
        $forbidden = [
            'admin_users',
            'schema_migrations',
            'settings',          // settings has both content (page-text) and infrastructure (auth) — handled separately, never wholesale truncated
            'page_sections',     // reset via INSERT … ON DUPLICATE in the design partition, not truncated as content
        ];
        foreach ($forbidden as $table) {
            $this->assertNotContains(
                $table,
                \ContentReset::CONTENT_TABLES,
                "$table must never appear in CONTENT_TABLES — it would break login or strip infrastructure."
            );
        }
    }

    public function test_content_tables_list_is_non_empty_and_unique(): void
    {
        $tables = \ContentReset::CONTENT_TABLES;
        $this->assertNotEmpty($tables);
        $this->assertSame(
            count($tables),
            count(array_unique($tables)),
            'CONTENT_TABLES must contain no duplicates (each entry triggers a DELETE).'
        );
    }

    public function test_upload_subdirs_are_relative_to_uploads_dir(): void
    {
        foreach (\ContentReset::UPLOAD_SUBDIRS as $sub) {
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9_\-]+$/i',
                $sub,
                "Upload subdir '$sub' must be a single-segment relative name (no slashes, no traversal)."
            );
        }
    }

    public function test_branding_setting_keys_dont_overlap_auth_or_design(): void
    {
        foreach (\ContentReset::BRANDING_SETTING_KEYS as $key) {
            $this->assertStringStartsNotWith(
                'auth_',
                $key,
                "Branding key '$key' must not collide with an auth_* row — that would log the admin out."
            );
            $this->assertStringStartsNotWith(
                'theme_',
                $key,
                "Branding key '$key' must not collide with a theme_* row — design partition handles those."
            );
            $this->assertStringStartsNotWith(
                'text.',
                $key,
                "Branding key '$key' must not collide with page-text overrides — text_overrides partition handles those."
            );
        }
    }

    public function test_design_defaults_include_required_theme_and_event_keys(): void
    {
        $required = [
            'theme_preset', 'theme_font_display', 'theme_font_body',
            'theme_radius_scale', 'theme_shadow_scale',
            'event_type_labels',
        ];
        foreach ($required as $key) {
            $this->assertArrayHasKey(
                $key,
                \ContentReset::DESIGN_DEFAULTS,
                "Design partition must reset '$key' to its canonical default."
            );
        }
    }

    public function test_shop_category_defaults_mirror_init_sql_shape(): void
    {
        foreach (\ContentReset::SHOP_CATEGORY_DEFAULTS as $cat) {
            $this->assertArrayHasKey('name', $cat);
            $this->assertArrayHasKey('slug', $cat);
            $this->assertArrayHasKey('type', $cat);
            $this->assertContains($cat['type'], ['pot', 'merch']);
        }
    }
}
