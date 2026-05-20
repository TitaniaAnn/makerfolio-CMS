<?php
/**
 * Shared search + pagination toolbar partial for admin list pages.
 *
 * Expects these vars in scope:
 *   $q         (string) current search term
 *   $pg        (array)  output of ListQuery::pagination(...)
 *   $listLabel (string) plural noun for the count message ("pieces" etc.)
 *
 * The form is GET so search state is bookmarkable + shareable. Reorder
 * doesn't show while search/page is active (a partial result set would
 * mis-renumber sort_order) — the page hides drag handles in that case.
 */
?>
<div class="list-toolbar">
    <form method="GET" class="list-toolbar__search">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search <?= e($listLabel) ?>…" aria-label="Search">
        <?php if ($q !== ''): ?>
            <button type="submit" class="admin-btn admin-btn--sm">Search</button>
            <a href="?" class="admin-btn admin-btn--sm" title="Clear search">Clear</a>
        <?php else: ?>
            <button type="submit" class="admin-btn admin-btn--sm">Search</button>
        <?php endif; ?>
    </form>
    <div class="list-toolbar__count">
        <?php if ($pg['total'] === 0): ?>
            <?= $q !== '' ? 'No matches' : 'None yet' ?>
        <?php else: ?>
            Showing <?= $pg['from'] ?>–<?= $pg['to'] ?> of <?= $pg['total'] ?> <?= e($listLabel) ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($pg['totalPages'] > 1): ?>
    <?php
    // Preserve $_GET except `page` so deep links keep their search term.
    $params = $_GET; unset($params['page']);
    $base   = '?' . http_build_query($params);
    $sep    = $params ? '&' : '';
    ?>
    <nav class="list-pagination" aria-label="Pagination">
        <?php if ($pg['hasPrev']): ?>
            <a href="<?= e($base . $sep . 'page=' . $pg['prevPage']) ?>" class="admin-btn admin-btn--sm">← Prev</a>
        <?php else: ?>
            <span class="admin-btn admin-btn--sm" style="opacity:.5;">← Prev</span>
        <?php endif; ?>
        <span class="list-pagination__current">Page <?= $pg['page'] ?> of <?= $pg['totalPages'] ?></span>
        <?php if ($pg['hasNext']): ?>
            <a href="<?= e($base . $sep . 'page=' . $pg['nextPage']) ?>" class="admin-btn admin-btn--sm">Next →</a>
        <?php else: ?>
            <span class="admin-btn admin-btn--sm" style="opacity:.5;">Next →</span>
        <?php endif; ?>
    </nav>
<?php endif; ?>
