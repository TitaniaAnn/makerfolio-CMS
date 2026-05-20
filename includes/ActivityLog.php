<?php
/**
 * ActivityLog — append-only audit trail for admin actions.
 *
 * Call sites use `ActivityLog::log('group.action', $targetType, $targetId, $details)`.
 * The admin id + client IP are resolved automatically from the current session.
 * Logging never throws — table-missing or DB-error during a write is silently
 * dropped so the caller's path is never interrupted by audit failures.
 *
 * Action naming convention: `group.verb` (`auth.login_success`,
 * `settings.theme_save`, `users.delete`). Keep groups stable so /admin/activity/
 * filter UIs and external dashboards can rely on them.
 */
final class ActivityLog
{
    /** Curated set of known action names — also used by the admin filter UI. */
    public const ACTIONS = [
        // Auth
        'auth.login_success',
        'auth.login_failure',
        'auth.logout',
        'auth.password_reset_requested',
        'auth.password_reset_completed',
        // 2FA / TOTP
        'auth.login_password_ok_awaiting_2fa',
        'auth.2fa_setup',
        'auth.2fa_disabled',
        'auth.2fa_recovery_codes_regenerated',
        'auth.2fa_challenge_passed',
        'auth.2fa_challenge_failed',
        'auth.2fa_recovery_used',
        // Admin users
        'users.create',
        'users.update',
        'users.delete',
        'users.unlink_github',
        'users.unlink_google',
        // Settings — each settings/*.php POST handler logs one of these
        'settings.branding_save',
        'settings.theme_save',
        'settings.auth_save',
        'settings.page_text_save',
        'settings.page_sections_save',
        'settings.email_templates_save',
        'settings.email_templates_send_test',
        // Destructive / heavy operations
        'content.reset',
        'backup.download',
    ];

    public static function log(
        string $action,
        ?string $targetType = null,
        $targetId = null,
        array $details = []
    ): void {
        try {
            $adminId = null;
            if (class_exists('Auth') && Auth::isLoggedIn()) {
                $user = Auth::getUser();
                if ($user && isset($user['id'])) {
                    $adminId = (int)$user['id'];
                }
            }

            $ip = '';
            if (class_exists('Auth')) {
                $ip = Auth::clientIp();
            }

            Database::insert('admin_activity', [
                'admin_id'    => $adminId,
                'action'      => substr($action, 0, 64),
                'target_type' => $targetType !== null ? substr($targetType, 0, 32) : null,
                'target_id'   => $targetId   !== null ? substr((string)$targetId, 0, 64) : null,
                'details'     => $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'ip_address'  => $ip !== '' ? substr($ip, 0, 45) : null,
            ]);
        } catch (\Throwable $_) {
            // Audit failures must never break the caller's path.
        }
    }

    /**
     * Recent activity rows joined with admin_users.username (when admin_id is set).
     * Used by /admin/activity/index.php.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function recent(int $limit = 50, int $offset = 0, ?int $adminFilter = null, ?string $actionFilter = null): array
    {
        $where  = [];
        $params = [];
        if ($adminFilter !== null) { $where[] = 'a.admin_id = ?';  $params[] = $adminFilter; }
        if ($actionFilter !== null && $actionFilter !== '') { $where[] = 'a.action = ?'; $params[] = $actionFilter; }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $rows = Database::fetchAll(
            "SELECT a.id, a.admin_id, a.action, a.target_type, a.target_id, a.details,
                    a.ip_address, a.created_at,
                    u.username AS admin_username
               FROM admin_activity a
               LEFT JOIN admin_users u ON u.id = a.admin_id
               $whereSql
              ORDER BY a.created_at DESC, a.id DESC
              LIMIT $limit OFFSET $offset",
            $params
        );
        return $rows;
    }

    public static function totalCount(?int $adminFilter = null, ?string $actionFilter = null): int
    {
        $where  = [];
        $params = [];
        if ($adminFilter !== null) { $where[] = 'admin_id = ?';  $params[] = $adminFilter; }
        if ($actionFilter !== null && $actionFilter !== '') { $where[] = 'action = ?'; $params[] = $actionFilter; }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $row = Database::fetchOne("SELECT COUNT(*) AS c FROM admin_activity $whereSql", $params);
        return (int)($row['c'] ?? 0);
    }
}
