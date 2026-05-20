<?php
/**
 * PageSections — admin-toggleable visibility + ordering for public-page
 * sections. Backed by the `page_sections` table (one row per page/section).
 *
 * CATALOG is the authoritative list of (page, section_key) pairs and their
 * human-readable admin labels. DB rows for unknown pairs are silently
 * ignored at render time; missing rows fall through as visible (forward-
 * compat: a new catalog entry is shown by default before its seed row exists).
 *
 * Call sites:
 *
 *   // Inner-page guard (about, portfolio, templates):
 *   <?php if (PageSections::isVisible('about', 'social_links')): ?>...<?php endif; ?>
 *
 *   // Homepage dispatch loop:
 *   foreach (PageSections::enabled('home') as $section) { ...include partial... }
 *
 * Adding a new section:
 *   1. Add a CATALOG entry below.
 *   2. Add a default sort_order in DEFAULT_SORT_ORDER.
 *   3. Add a seed row in init.sql + a new migration.
 *   4. Wire the render code (a partial for the homepage, an inline guard for inner pages).
 */
final class PageSections
{
    /** page → section_key → human label (used by the admin UI) */
    public const CATALOG = [
        'home' => [
            'hero'           => 'Hero banner',
            'featured_work'  => 'Featured Work (shown only when featured pieces exist)',
            'announcements'  => 'Announcements (shown only when published announcements exist)',
            'events_preview' => 'Upcoming Events preview',
            'about_strip'    => 'About strip',
            'social'         => 'Social feed (shown only when featured social posts exist)',
            'shop_teaser'    => 'Shop teaser',
        ],
        'about' => [
            'contact'      => 'Contact email CTA (shown only when a contact email is set)',
            'social_links' => 'Social links section (shown only when social links are configured)',
        ],
        'portfolio' => [
            'filters' => 'Technique filter chips',
        ],
        'templates' => [
            'filters' => 'Category filter chips',
        ],
    ];

    /** Default sort_order seed values — must stay in sync with init.sql / migration 017. */
    public const DEFAULT_SORT_ORDER = [
        'home' => [
            'hero' => 10, 'featured_work' => 20, 'announcements' => 30,
            'events_preview' => 40, 'about_strip' => 50, 'social' => 60, 'shop_teaser' => 70,
        ],
        'about'     => ['contact' => 10, 'social_links' => 20],
        'portfolio' => ['filters' => 10],
        'templates' => ['filters' => 10],
    ];

    /** @var array<string, array{enabled:string[], rows:array}> per-request cache */
    private static array $cache = [];

    /**
     * Return the visible section keys for $page in sort_order. Skips section
     * keys absent from CATALOG; catalog entries without a DB row default to
     * visible.
     *
     * @return string[]
     */
    public static function enabled(string $page): array
    {
        if (!isset(self::$cache[$page])) {
            self::$cache[$page] = self::loadPage($page);
        }
        return self::$cache[$page]['enabled'];
    }

    /**
     * True when $section on $page is visible. Unknown CATALOG entries return
     * false (defense against typos); catalog entries with no DB row return
     * true (default-visible).
     */
    public static function isVisible(string $page, string $section): bool
    {
        if (!isset(self::CATALOG[$page][$section])) {
            return false;
        }
        if (!isset(self::$cache[$page])) {
            self::$cache[$page] = self::loadPage($page);
        }
        return in_array($section, self::$cache[$page]['enabled'], true);
    }

    /** Drop the in-process cache. The admin save handler calls this so a subsequent read sees fresh state. */
    public static function resetCache(): void
    {
        self::$cache = [];
    }

    // -- internals -----------------------------------------------------------

    /**
     * @return array{enabled:string[], rows:array<string, array{section_key:string,is_visible:int|string,sort_order:int|string}>}
     */
    private static function loadPage(string $page): array
    {
        $rowsByKey = [];
        if (class_exists('Database')) {
            try {
                $dbRows = Database::fetchAll(
                    "SELECT section_key, is_visible, sort_order
                       FROM page_sections
                      WHERE page = ?
                      ORDER BY sort_order ASC, section_key ASC",
                    [$page]
                );
                foreach ($dbRows as $r) {
                    $rowsByKey[$r['section_key']] = $r;
                }
            } catch (\Throwable $_) {
                // Table not present yet (fresh DB before migration 017 runs) —
                // fall back to defaults so public pages keep rendering.
                $rowsByKey = [];
            }
        }

        $catalog = self::CATALOG[$page] ?? [];
        $entries = [];
        foreach ($catalog as $key => $_label) {
            $row = $rowsByKey[$key] ?? null;
            $visible = $row === null ? true : ((int)$row['is_visible'] === 1);
            if (!$visible) continue;
            $sort = $row === null
                ? (self::DEFAULT_SORT_ORDER[$page][$key] ?? 999)
                : (int)$row['sort_order'];
            $entries[] = ['key' => $key, 'sort' => $sort];
        }
        usort($entries, function ($a, $b) {
            if ($a['sort'] === $b['sort']) {
                return strcmp($a['key'], $b['key']);
            }
            return $a['sort'] <=> $b['sort'];
        });
        return [
            'enabled' => array_column($entries, 'key'),
            'rows'    => $rowsByKey,
        ];
    }
}
