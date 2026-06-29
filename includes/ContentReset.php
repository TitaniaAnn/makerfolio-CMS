<?php
/**
 * ContentReset — wipe author-specific content while preserving the schema,
 * admin accounts, auth configuration, and the migration ledger.
 *
 * Intended use: handing the CMS off to another potter as a clean slate.
 * Run via:
 *   - CLI:   php bin/reset-content.php
 *   - Admin: /admin/settings/reset-content.php
 *
 * The reset is partitioned into 5 independent areas (any subset can be run):
 *   - content        : DB rows for pottery, products, events, announcements,
 *                      social, orders, templates (+ their child rows).
 *   - uploads        : Files under public/uploads/{pottery,products,hero,
 *                      profile,templates}/
 *   - branding       : Settings rows for site_name, tagline, bio, hero copy,
 *                      contact_email, profile_photo, privacy policy, nav
 *                      external link.
 *   - text_overrides : Admin page-text overrides (rows in `settings` keyed
 *                      `text.*`).
 *   - email_overrides: Admin email-template overrides (rows in `settings`
 *                      keyed `email.*` — see EmailTemplates::allSettingKeys).
 *   - design         : Theme overrides + event_type_labels reset to defaults;
 *                      page_sections reset to seed values.
 *
 * What is ALWAYS preserved (never touched):
 *   - The schema itself (no DROP / ALTER).
 *   - admin_users (the new owner manages their own admins).
 *   - All auth_* settings (so the admin can keep logging in).
 *   - schema_migrations ledger.
 *   - shop_currency (operational setting, not branding).
 *
 * Atomicity: DB wipes run inside a single Database::transaction. Filesystem
 * deletes happen AFTER the transaction commits — if the FS step fails, the
 * DB is consistent and the orphaned files are reported back to the caller.
 */
final class ContentReset
{
    /**
     * Content tables to wipe, in child-first order so FK constraints (if any)
     * don't trip. Each entry is just a table name; `DELETE FROM {table}` is
     * issued for each (DELETE rather than TRUNCATE so the whole thing fits
     * inside one transaction).
     */
    public const CONTENT_TABLES = [
        // Junction / child tables first.
        'event_pottery',
        'announcement_links',
        'announcement_social_posts',
        'pottery_images',
        'product_images',
        'pottery_template_files',
        'orders',
        'stripe_webhook_events',
        // Then parents.
        'pottery',
        'products',
        'events',
        'announcements',
        'social_posts',
        'social_links',
        'pottery_templates',
        'shop_categories',
    ];

    /** Upload subdirectories whose contents are wiped (the directory itself stays). */
    public const UPLOAD_SUBDIRS = [
        'pottery',
        'products',
        'hero',
        'profile',
        'templates',
    ];

    /**
     * Settings rows always cleared as part of the 'content' partition (in
     * addition to the CONTENT_TABLES wipe). These are book-keeping markers
     * tied to content that just got deleted — leaving the marker set after
     * the content is gone would lie to subsequent logic that reads them.
     */
    public const CONTENT_SETTING_KEYS = [
        'sample_content_seeded',
    ];

    /** Settings rows deleted by the 'branding' partition. */
    public const BRANDING_SETTING_KEYS = [
        'site_name',
        'tagline',
        'bio',
        'about_text',
        'hero_title',
        'hero_subtitle',
        'hero_image',
        'shop_intro',
        'contact_email',
        'profile_photo',
        'privacy_policy_html',
        'privacy_updated',
        'nav_external_url',
        'nav_external_label',
    ];

    /**
     * Settings rows reset to their canonical default by the 'design' partition.
     * Wiping these would leave the system in a default-less state (Theme uses
     * code-side fallbacks but the admin UI expects the rows to exist), so we
     * UPSERT each to its installed default instead of deleting.
     */
    public const DESIGN_DEFAULTS = [
        'theme_preset'              => 'terra-gold',
        'theme_override_primary'    => '',
        'theme_override_accent'     => '',
        'theme_override_background' => '',
        'theme_override_surface'    => '',
        'theme_override_text'       => '',
        'theme_override_cool'       => '',
        'theme_font_display'        => 'playfair-display',
        'theme_font_body'           => 'nunito',
        'theme_font_eyebrow'        => 'caveat',
        'theme_radius_scale'        => 'default',
        'theme_shadow_scale'        => 'default',
        'event_type_labels'         => '{"pottery_show":"Pottery Show","pottery_sale":"Pottery Sale","storefront_sale":"Storefront Sale","class":"Class"}',
        'nav_external_label'        => 'App',  // The URL is wiped under branding; the label has a non-blank default to seed.
    ];

    /** Default shop_categories rows to re-insert after wiping. Mirrors init.sql. */
    public const SHOP_CATEGORY_DEFAULTS = [
        ['name' => 'Original Pots', 'slug' => 'original-pots', 'type' => 'pot'],
        ['name' => 'Studio Merch',  'slug' => 'studio-merch',  'type' => 'merch'],
    ];

    /**
     * @param array{
     *   content?:bool, uploads?:bool, branding?:bool,
     *   text_overrides?:bool, email_overrides?:bool, design?:bool
     * } $options All default to true if absent.
     * @param string $uploadPath Absolute path to public/uploads/.
     * @return array{
     *   db_log:string[],
     *   fs_log:string[],
     *   fs_failed:string[],
     * }
     */
    public static function reset(array $options, string $uploadPath): array
    {
        $opts = [
            'content'         => $options['content']         ?? true,
            'uploads'         => $options['uploads']         ?? true,
            'branding'        => $options['branding']        ?? true,
            'text_overrides'  => $options['text_overrides']  ?? true,
            'email_overrides' => $options['email_overrides'] ?? true,
            'design'          => $options['design']          ?? true,
        ];

        $dbLog = [];
        Database::transaction(function () use ($opts, &$dbLog) {
            if ($opts['content']) {
                foreach (self::CONTENT_TABLES as $table) {
                    // CONTENT_TABLES is a code-defined const, so this guard is
                    // defense-in-depth: it fails loudly if a future maintainer
                    // ever sources table names from anything user-derived.
                    $safe = self::safeTable($table);
                    $deleted = Database::query("DELETE FROM `$safe`")->rowCount();
                    $dbLog[] = "DELETE FROM $safe — $deleted row(s)";
                }
                // Reseed default shop categories (admin can edit afterward).
                foreach (self::SHOP_CATEGORY_DEFAULTS as $cat) {
                    Database::insert('shop_categories', $cat);
                }
                $dbLog[] = "Reseeded " . count(self::SHOP_CATEGORY_DEFAULTS) . " shop_categories";

                // Clear content-linked settings markers so the wiped state is
                // not falsely reported as "still seeded".
                $placeholders = implode(',', array_fill(0, count(self::CONTENT_SETTING_KEYS), '?'));
                $deleted = Database::query(
                    "DELETE FROM settings WHERE setting_key IN ($placeholders)",
                    self::CONTENT_SETTING_KEYS
                )->rowCount();
                $dbLog[] = "DELETE content markers — $deleted row(s)";
            }

            if ($opts['branding']) {
                $placeholders = implode(',', array_fill(0, count(self::BRANDING_SETTING_KEYS), '?'));
                $deleted = Database::query(
                    "DELETE FROM settings WHERE setting_key IN ($placeholders)",
                    self::BRANDING_SETTING_KEYS
                )->rowCount();
                $dbLog[] = "DELETE branding settings — $deleted row(s)";
            }

            if ($opts['text_overrides']) {
                $deleted = Database::query(
                    "DELETE FROM settings WHERE setting_key LIKE 'text.%'"
                )->rowCount();
                $dbLog[] = "DELETE page-text overrides — $deleted row(s)";
            }

            if ($opts['email_overrides']) {
                $deleted = Database::query(
                    "DELETE FROM settings WHERE setting_key LIKE 'email.%'"
                )->rowCount();
                $dbLog[] = "DELETE email-template overrides — $deleted row(s)";
            }

            if ($opts['design']) {
                foreach (self::DESIGN_DEFAULTS as $key => $value) {
                    Database::query(
                        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                        [$key, $value]
                    );
                }
                $dbLog[] = "Reset " . count(self::DESIGN_DEFAULTS) . " design settings to defaults";

                $deleted = Database::query("DELETE FROM page_sections")->rowCount();
                $dbLog[] = "DELETE page_sections — $deleted row(s)";
                self::reseedPageSections();
                $dbLog[] = "Reseeded page_sections defaults";
            }
        });

        $fsLog    = [];
        $fsFailed = [];
        if ($opts['uploads']) {
            foreach (self::UPLOAD_SUBDIRS as $subdir) {
                $path = rtrim($uploadPath, '/\\') . DIRECTORY_SEPARATOR . $subdir;
                $result = self::wipeDirContents($path);
                $fsLog[] = "{$subdir}/ — removed " . $result['removed'] . " file(s)";
                if ($result['failed']) {
                    $fsFailed = array_merge($fsFailed, $result['failed']);
                }
            }
        }

        return [
            'db_log'    => $dbLog,
            'fs_log'    => $fsLog,
            'fs_failed' => $fsFailed,
        ];
    }

    /** Defense in depth: only allow standard MySQL identifiers as raw SQL fragments. */
    private static function safeTable(string $table): string
    {
        if (!preg_match('/^[A-Za-z0-9_$]+$/', $table)) {
            throw new RuntimeException("Refusing to operate on table with unsafe name: $table");
        }
        return $table;
    }

    /**
     * Re-INSERT the default page_sections rows. Source of truth lives in
     * PageSections::DEFAULT_SORT_ORDER so this stays in sync automatically.
     */
    private static function reseedPageSections(): void
    {
        if (!class_exists('PageSections')) {
            require_once __DIR__ . '/PageSections.php';
        }
        foreach (PageSections::DEFAULT_SORT_ORDER as $page => $sections) {
            foreach ($sections as $key => $sort) {
                Database::query(
                    "INSERT INTO page_sections (page, section_key, is_visible, sort_order)
                     VALUES (?, ?, 1, ?)
                     ON DUPLICATE KEY UPDATE is_visible = 1, sort_order = VALUES(sort_order)",
                    [$page, $key, $sort]
                );
            }
        }
    }

    /**
     * Recursively delete the CONTENTS of $dir (the directory itself is kept).
     * Returns counts + a list of paths that couldn't be removed.
     *
     * @return array{removed:int, failed:string[]}
     */
    private static function wipeDirContents(string $dir): array
    {
        if (!is_dir($dir)) {
            return ['removed' => 0, 'failed' => []];
        }
        $removed = 0;
        $failed  = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $path) {
            $p = (string)$path;
            $ok = $path->isDir() ? @rmdir($p) : @unlink($p);
            if ($ok) {
                $removed++;
            } else {
                $failed[] = $p;
            }
        }
        return ['removed' => $removed, 'failed' => $failed];
    }
}
