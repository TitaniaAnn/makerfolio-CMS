<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/ListReorder.php';

/**
 * ListReorder — covers the pure helpers and the allowlist gate. DB-touching
 * paths (update / updatePageSections) are not unit-tested; they're exercised
 * by the Docker smoke verification, consistent with Database / ContentReset.
 */
final class ListReorderTest extends TestCase
{
    public function test_sanitize_ids_coerces_strings_to_positive_ints(): void
    {
        // DOM-extracted strings + numeric strings should both work.
        $this->assertSame([1, 2, 3], \ListReorder::sanitizeIds(['1', '2', '3']));
        $this->assertSame([10, 20], \ListReorder::sanitizeIds([10, 20]));
        $this->assertSame([7, 8], \ListReorder::sanitizeIds(['  7 ', '8']));
    }

    public function test_sanitize_ids_drops_non_positive_and_garbage(): void
    {
        // 0 and negatives never name a real auto-increment row; junk strings
        // become 0 via (int) cast and should be dropped too.
        $this->assertSame([1, 2], \ListReorder::sanitizeIds([0, '1', -5, '2', 'abc', '']));
    }

    public function test_sanitize_ids_dedupes_preserving_first_occurrence(): void
    {
        // A duplicated ID would map to two different sort_order values; only
        // the first occurrence's position wins.
        $this->assertSame([5, 7, 9], \ListReorder::sanitizeIds([5, 7, 5, 9, 7]));
    }

    public function test_update_rejects_unknown_kind(): void
    {
        // Tables not in the allowlist must throw before any SQL runs.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown kind 'admin_users'");
        \ListReorder::update('admin_users', [1, 2]);
    }

    public function test_update_returns_zero_when_no_valid_ids(): void
    {
        // No DB call should be issued when input sanitizes to empty — the
        // function should short-circuit at zero before touching Database.
        // (If it didn't short-circuit, the missing Database class would fatal.)
        $this->assertSame(0, \ListReorder::update('pottery', []));
        $this->assertSame(0, \ListReorder::update('pottery', ['', 'nope', -1]));
    }

    public function test_allowed_kinds_have_required_keys(): void
    {
        // Every entry in ALLOWED needs table + id_column for the SQL builder.
        foreach (\ListReorder::ALLOWED as $kind => $config) {
            $this->assertArrayHasKey('table',     $config, "ALLOWED[$kind] missing 'table'");
            $this->assertArrayHasKey('id_column', $config, "ALLOWED[$kind] missing 'id_column'");
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_$]+$/', $config['table'], "ALLOWED[$kind] table looks unsafe");
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_$]+$/', $config['id_column'], "ALLOWED[$kind] id_column looks unsafe");
        }
    }

    public function test_update_page_sections_requires_pagesections_class(): void
    {
        // updatePageSections gates on PageSections::CATALOG; without that
        // class loaded the helper should throw, not produce silent inserts.
        // (We don't load PageSections in this test's bootstrap.)
        if (class_exists('PageSections')) {
            $this->markTestSkipped('PageSections is loaded — skipping the no-class branch test.');
        }
        $this->expectException(\InvalidArgumentException::class);
        \ListReorder::updatePageSections('home', ['hero']);
    }
}
