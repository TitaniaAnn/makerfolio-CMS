<?php
/**
 * ListQuery — small builder for the admin list pages that need search +
 * pagination (pottery / shop / events / announcements).
 *
 * Each page calls:
 *
 *     $q     = ListQuery::fromRequest($_GET, ['perPage' => 25]);
 *     $where = ListQuery::buildSearchClause($q['q'], ['title', 'description']);
 *     $rows  = Database::fetchAll(
 *         "SELECT * FROM pottery {$where['sql']} ORDER BY sort_order LIMIT {$q['perPage']} OFFSET {$q['offset']}",
 *         $where['params']
 *     );
 *     $total = (int)Database::fetchOne(
 *         "SELECT COUNT(*) AS c FROM pottery {$where['sql']}", $where['params']
 *     )['c'];
 *     $pg    = ListQuery::pagination($total, $q['page'], $q['perPage']);
 *
 * The SQL fragment never has user input substituted directly — only column
 * names (validated against the allowlist passed in) and bind placeholders.
 */
final class ListQuery
{
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PER_PAGE     = 100;

    /**
     * Normalize $_GET into ['q' => string, 'page' => int, 'perPage' => int, 'offset' => int].
     * Coerces junk safely (negative pages → 1; oversized perPage → MAX).
     */
    public static function fromRequest(array $get, array $opts = []): array
    {
        $perPage = (int)($opts['perPage'] ?? self::DEFAULT_PER_PAGE);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page    = max(1, (int)($get['page'] ?? 1));
        $q       = trim((string)($get['q'] ?? ''));
        // Truncate absurdly long queries — prevents oversized LIKE patterns.
        if (mb_strlen($q) > 200) {
            $q = mb_substr($q, 0, 200);
        }
        return [
            'q'       => $q,
            'page'    => $page,
            'perPage' => $perPage,
            'offset'  => ($page - 1) * $perPage,
        ];
    }

    /**
     * Build a "WHERE col LIKE ? OR col2 LIKE ?" fragment from a search term.
     * Returns ['sql' => string, 'params' => array]. When $q is empty, returns
     * ['sql' => '', 'params' => []] — caller can interpolate either way.
     *
     * @param string   $q         Search term.
     * @param string[] $columns   Allowlisted column names to LIKE against.
     */
    public static function buildSearchClause(string $q, array $columns): array
    {
        if ($q === '' || empty($columns)) {
            return ['sql' => '', 'params' => []];
        }
        $safeCols = [];
        foreach ($columns as $col) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $col)) {
                throw new InvalidArgumentException("ListQuery: refused unsafe column name '$col'");
            }
            $safeCols[] = $col;
        }
        $like     = '%' . self::escapeLikeWildcards($q) . '%';
        $parts    = [];
        $params   = [];
        foreach ($safeCols as $col) {
            $parts[]  = "`$col` LIKE ?";
            $params[] = $like;
        }
        return [
            'sql'    => 'WHERE (' . implode(' OR ', $parts) . ')',
            'params' => $params,
        ];
    }

    /**
     * Escape MySQL LIKE wildcards in user input so a search for "50%" doesn't
     * become a wildcard match. Pairs with column LIKE ? (no explicit ESCAPE
     * clause — the default backslash escape character works for these patterns).
     */
    public static function escapeLikeWildcards(string $q): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    }

    /**
     * Compute pagination metadata.
     *
     * @return array{
     *   total:int, page:int, perPage:int, totalPages:int,
     *   hasPrev:bool, hasNext:bool, prevPage:int, nextPage:int,
     *   from:int, to:int
     * }
     */
    public static function pagination(int $total, int $page, int $perPage): array
    {
        $perPage    = max(1, $perPage);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page       = max(1, min($totalPages, $page));
        $from       = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $to         = min($total, $page * $perPage);
        return [
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => $totalPages,
            'hasPrev'    => $page > 1,
            'hasNext'    => $page < $totalPages,
            'prevPage'   => max(1, $page - 1),
            'nextPage'   => min($totalPages, $page + 1),
            'from'       => $from,
            'to'         => $to,
        ];
    }
}
