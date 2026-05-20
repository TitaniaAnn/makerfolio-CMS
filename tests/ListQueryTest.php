<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/ListQuery.php';

/**
 * ListQuery — pure helpers, no DB. The actual LIMIT/OFFSET behavior is
 * exercised by the Docker smoke verification.
 */
final class ListQueryTest extends TestCase
{
    public function test_from_request_defaults_when_empty(): void
    {
        $r = \ListQuery::fromRequest([]);
        $this->assertSame('', $r['q']);
        $this->assertSame(1, $r['page']);
        $this->assertSame(\ListQuery::DEFAULT_PER_PAGE, $r['perPage']);
        $this->assertSame(0, $r['offset']);
    }

    public function test_from_request_clamps_negative_page_to_one(): void
    {
        $r = \ListQuery::fromRequest(['page' => '-3']);
        $this->assertSame(1, $r['page']);
        $this->assertSame(0, $r['offset']);
    }

    public function test_from_request_clamps_per_page_to_max(): void
    {
        // Oversized perPage requests would let a hostile query exhaust memory;
        // the cap is enforced server-side regardless of the URL.
        $r = \ListQuery::fromRequest([], ['perPage' => 99999]);
        $this->assertSame(\ListQuery::MAX_PER_PAGE, $r['perPage']);
    }

    public function test_from_request_truncates_long_query(): void
    {
        $long = str_repeat('a', 500);
        $r    = \ListQuery::fromRequest(['q' => $long]);
        $this->assertSame(200, mb_strlen($r['q']));
    }

    public function test_from_request_computes_offset_from_page(): void
    {
        $r = \ListQuery::fromRequest(['page' => '3'], ['perPage' => 25]);
        $this->assertSame(50, $r['offset']); // (3-1) * 25
    }

    public function test_build_search_clause_empty_query_returns_empty_sql(): void
    {
        // Caller can interpolate "" into SQL without breaking — no WHERE added.
        $out = \ListQuery::buildSearchClause('', ['title']);
        $this->assertSame(['sql' => '', 'params' => []], $out);
    }

    public function test_build_search_clause_wraps_query_with_wildcards(): void
    {
        $out = \ListQuery::buildSearchClause('vase', ['title', 'description']);
        $this->assertSame('WHERE (`title` LIKE ? OR `description` LIKE ?)', $out['sql']);
        $this->assertSame(['%vase%', '%vase%'], $out['params']);
    }

    public function test_build_search_clause_escapes_like_wildcards_in_input(): void
    {
        // Without escaping, a search for "10%" would match "100" too.
        $out = \ListQuery::buildSearchClause('10%_off', ['title']);
        $this->assertSame(['%10\\%\\_off%'], $out['params']);
    }

    public function test_build_search_clause_rejects_unsafe_column_names(): void
    {
        // Column names are interpolated into raw SQL, so they MUST be
        // allowlist-validated. Anything outside [A-Za-z0-9_] throws.
        $this->expectException(\InvalidArgumentException::class);
        \ListQuery::buildSearchClause('x', ['title; DROP TABLE users--']);
    }

    public function test_pagination_basic_math(): void
    {
        $p = \ListQuery::pagination(73, 2, 25);
        $this->assertSame(73, $p['total']);
        $this->assertSame(2,  $p['page']);
        $this->assertSame(3,  $p['totalPages']);
        $this->assertSame(26, $p['from']);
        $this->assertSame(50, $p['to']);
        $this->assertTrue($p['hasPrev']);
        $this->assertTrue($p['hasNext']);
    }

    public function test_pagination_clamps_out_of_range_page(): void
    {
        // Requesting page 99 of a 3-page result clamps to the last page,
        // never produces a negative offset.
        $p = \ListQuery::pagination(73, 99, 25);
        $this->assertSame(3, $p['page']);
        $this->assertFalse($p['hasNext']);
        $this->assertSame(51, $p['from']);
        $this->assertSame(73, $p['to']);
    }

    public function test_pagination_zero_total_returns_one_page_with_zero_range(): void
    {
        $p = \ListQuery::pagination(0, 1, 25);
        $this->assertSame(1, $p['totalPages']);
        $this->assertSame(0, $p['from']);
        $this->assertSame(0, $p['to']);
        $this->assertFalse($p['hasPrev']);
        $this->assertFalse($p['hasNext']);
    }

    public function test_escape_like_wildcards_handles_all_metacharacters(): void
    {
        // % and _ are MySQL LIKE wildcards; \ is the default escape char and
        // must itself be doubled or it'd escape the following character.
        $this->assertSame('\\\\\\%\\_test', \ListQuery::escapeLikeWildcards('\\%_test'));
    }
}
