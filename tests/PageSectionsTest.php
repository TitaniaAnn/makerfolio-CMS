<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/PageSections.php';

/**
 * Pure-function / catalog sanity tests for PageSections.
 *
 * Database-touching paths (enabled, isVisible against actual rows) are
 * exercised by the Docker smoke verification.
 */
final class PageSectionsTest extends TestCase
{
    public function test_catalog_and_default_sort_order_share_the_same_keys(): void
    {
        foreach (\PageSections::CATALOG as $page => $sections) {
            $this->assertArrayHasKey($page, \PageSections::DEFAULT_SORT_ORDER, "DEFAULT_SORT_ORDER missing page '$page'");
            $catalogKeys = array_keys($sections);
            $sortKeys    = array_keys(\PageSections::DEFAULT_SORT_ORDER[$page]);
            sort($catalogKeys);
            sort($sortKeys);
            $this->assertSame($catalogKeys, $sortKeys, "CATALOG and DEFAULT_SORT_ORDER keys disagree for page '$page' — adding a section must update both.");
        }
    }

    public function test_every_catalog_label_is_a_non_empty_string(): void
    {
        foreach (\PageSections::CATALOG as $page => $sections) {
            foreach ($sections as $key => $label) {
                $this->assertIsString($label, "label for $page.$key must be a string");
                $this->assertNotSame('', trim($label), "label for $page.$key must not be blank");
            }
        }
    }

    public function test_home_section_keys_match_their_partial_filenames(): void
    {
        // The dispatch loop in public/index.php converts snake_case section
        // keys to kebab-case partial filenames. This test ensures each home
        // section key has a corresponding partial file on disk.
        $partialsDir = __DIR__ . '/../public/sections/home';
        $this->assertDirectoryExists($partialsDir, "homepage partials directory missing");

        foreach (array_keys(\PageSections::CATALOG['home']) as $key) {
            $expected = $partialsDir . '/' . str_replace('_', '-', $key) . '.php';
            $this->assertFileExists($expected, "homepage partial missing for section '$key' (expected $expected)");
        }
    }

    public function test_is_visible_returns_false_for_unknown_pair_without_touching_db(): void
    {
        // Unknown pair short-circuits before any DB call, so this is safe to
        // exercise even though Database isn't loaded.
        $this->assertFalse(\PageSections::isVisible('not_a_page', 'whatever'));
        $this->assertFalse(\PageSections::isVisible('home', 'not_a_section'));
    }

    public function test_default_sort_orders_use_gaps_of_10_for_homepage(): void
    {
        // Convention: homepage sort_orders are 10, 20, 30, … so admins can
        // insert between without renumbering. Inner pages don't dispatch by
        // sort_order, so they're not constrained.
        $sorts = array_values(\PageSections::DEFAULT_SORT_ORDER['home']);
        sort($sorts);
        foreach ($sorts as $i => $v) {
            $expected = ($i + 1) * 10;
            $this->assertSame($expected, $v, "homepage default sort_order at index $i should be $expected, got $v");
        }
    }
}
