<?php
/**
 * ListReorder — shared backend for "drag the rows in admin and save the new
 * order" endpoints.
 *
 * Every reorderable resource ships a tiny POST endpoint that delegates here:
 *
 *     ListReorder::update('pottery', $_POST['ids']);
 *
 * The class enforces a strict allowlist of reorderable tables (because we're
 * about to write the table name into raw SQL) and rewrites `sort_order` for
 * every row in one transaction at gaps of 10 (so an admin who later wants to
 * insert a row between two others by typing a number can do so without
 * renumbering everything).
 *
 * Invalid IDs in the input are ignored, not errored — the UI uses JS that may
 * race with a concurrent delete, and we'd rather save what we can than fail
 * the whole reorder.
 *
 * Auth + CSRF are the caller's job (each endpoint calls Auth::requireLogin()
 * + csrf_verify()). This class assumes the caller has already done both.
 */
final class ListReorder
{
    /**
     * Sort-order gap between adjacent rows. We rewrite with gaps (not 1, 2,
     * 3, …) so an admin who later wants to insert a row between two others
     * by typing a number can do so without renumbering. 10 leaves plenty of
     * room for ~9 manual insertions before the next drag would be needed to
     * spread things out again.
     */
    public const SORT_ORDER_GAP = 10;

    /**
     * Tables this helper is willing to UPDATE. Keys are the public "kind"
     * names exposed to the JS layer; values are (table, id_column) pairs.
     * Adding a new reorderable resource means adding an entry here + a thin
     * endpoint file + a sortable tbody in the admin list page.
     */
    public const ALLOWED = [
        'pottery'  => ['table' => 'pottery',  'id_column' => 'id'],
        'products' => ['table' => 'products', 'id_column' => 'id'],
        'events'   => ['table' => 'events',   'id_column' => 'id'],
        // page_sections is reordered per-page, so the endpoint passes a
        // page filter alongside; see updatePageSections() below.
    ];

    /**
     * Rewrite sort_order for the listed IDs. Returns the number of rows
     * actually updated. Throws on unknown $kind or malformed input.
     *
     * @param string $kind One of self::ALLOWED's keys.
     * @param array  $ids  Ordered list of row IDs; the first ID gets sort_order=10,
     *                     the second 20, etc.
     */
    public static function update(string $kind, array $ids): int
    {
        if (!isset(self::ALLOWED[$kind])) {
            throw new InvalidArgumentException("ListReorder: unknown kind '$kind'");
        }
        $config = self::ALLOWED[$kind];
        $intIds = self::sanitizeIds($ids);
        if (!$intIds) {
            return 0;
        }

        // Defense-in-depth: ALLOWED's values are code-controlled but we still
        // validate them as identifiers in case a future maintainer adds a row
        // sourced from anything user-derived.
        $table = self::safeIdent($config['table']);
        $col   = self::safeIdent($config['id_column']);

        $updated = 0;
        Database::transaction(function () use ($table, $col, $intIds, &$updated) {
            foreach ($intIds as $position => $id) {
                $newSort = ($position + 1) * self::SORT_ORDER_GAP;
                $rows = Database::query(
                    "UPDATE `$table` SET sort_order = ? WHERE `$col` = ?",
                    [$newSort, $id]
                )->rowCount();
                $updated += $rows;
            }
        });
        return $updated;
    }

    /**
     * Reorder a single page's page_sections rows. Separate from update()
     * because page_sections rows are keyed by (page, section_key) rather than
     * by surrogate id, and we want to scope the rewrite to one page so a
     * reorder of "home" can't accidentally touch "about" rows.
     */
    public static function updatePageSections(string $page, array $sectionKeys): int
    {
        // Validate the page against PageSections::CATALOG to keep the SQL
        // constrained to known values.
        if (!class_exists('PageSections') || !isset(PageSections::CATALOG[$page])) {
            throw new InvalidArgumentException("ListReorder: unknown page '$page'");
        }
        $known = array_keys(PageSections::CATALOG[$page]);
        $clean = [];
        foreach ($sectionKeys as $key) {
            $key = (string)$key;
            if (in_array($key, $known, true) && !in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }
        if (!$clean) {
            return 0;
        }

        $updated = 0;
        Database::transaction(function () use ($page, $clean, &$updated) {
            foreach ($clean as $position => $sectionKey) {
                $newSort = ($position + 1) * self::SORT_ORDER_GAP;
                $rows = Database::query(
                    "UPDATE page_sections SET sort_order = ? WHERE page = ? AND section_key = ?",
                    [$newSort, $page, $sectionKey]
                )->rowCount();
                $updated += $rows;
            }
        });
        return $updated;
    }

    /**
     * Filter input down to positive integers, preserving order and dropping
     * duplicates. The JS layer sends DOM-extracted strings, so coerce here.
     *
     * @return int[]
     */
    public static function sanitizeIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $raw) {
            $n = (int)$raw;
            if ($n > 0 && !in_array($n, $clean, true)) {
                $clean[] = $n;
            }
        }
        return $clean;
    }

    /** Defense-in-depth identifier check; matches the convention used in Backup/ContentReset. */
    private static function safeIdent(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_$]+$/', $name)) {
            throw new RuntimeException("ListReorder: refusing unsafe identifier '$name'");
        }
        return $name;
    }
}
