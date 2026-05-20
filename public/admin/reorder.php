<?php
/**
 * Shared reorder endpoint for the admin drag-to-reorder lists.
 *
 * Accepts POST JSON or form-encoded:
 *   - kind: one of pottery / products / events / page_sections
 *   - ids[]: ordered list of row IDs (for the standard resources)
 *   - page + sections[]: page name + ordered section_keys (for page_sections)
 *
 * Returns JSON: { ok: true, updated: N } on success, { ok: false, error: ... }
 * on validation failure. The admin list pages POST via fetch() and surface
 * the response inline rather than full-page-reloading.
 *
 * Auth + CSRF are enforced before any DB write.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

// Allow CSRF token via header (cleaner for fetch()) or form field (fallback).
if (!isset($_POST['csrf']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $_POST['csrf'] = $_SERVER['HTTP_X_CSRF_TOKEN'];
}
csrf_verify();

$kind = (string)($_POST['kind'] ?? '');

try {
    if ($kind === 'page_sections') {
        $page     = (string)($_POST['page'] ?? '');
        $sections = (array)($_POST['sections'] ?? []);
        $updated  = ListReorder::updatePageSections($page, $sections);
        PageSections::resetCache();
        ActivityLog::log('content.reorder', 'page_sections', null, ['page' => $page, 'count' => $updated]);
    } else {
        $ids     = (array)($_POST['ids'] ?? []);
        $updated = ListReorder::update($kind, $ids);
        ActivityLog::log('content.reorder', $kind, null, ['count' => $updated]);
    }
    echo json_encode(['ok' => true, 'updated' => $updated]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('reorder.php: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Reorder failed; see server logs.']);
}
