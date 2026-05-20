<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/ActivityLog.php';

/**
 * Catalog sanity tests for ActivityLog. DB-touching paths (log, recent,
 * totalCount) are exercised in the Docker smoke verification.
 */
final class ActivityLogTest extends TestCase
{
    public function test_actions_use_group_dot_verb_convention(): void
    {
        foreach (\ActivityLog::ACTIONS as $action) {
            $this->assertMatchesRegularExpression(
                // group must start with a letter; verb can start with a letter
                // or digit (e.g. '2fa_setup'). Both parts are lowercase alnum + _.
                '/^[a-z][a-z0-9_]*\.[a-z0-9][a-z0-9_]*$/',
                $action,
                "Action '$action' must be 'group.verb', lowercase, alphanumeric/underscore."
            );
        }
    }

    public function test_actions_are_unique(): void
    {
        $this->assertSame(
            count(\ActivityLog::ACTIONS),
            count(array_unique(\ActivityLog::ACTIONS)),
            'Duplicate action names in ActivityLog::ACTIONS — every constant must be unique.'
        );
    }

    public function test_actions_fit_db_column_width(): void
    {
        // Schema declares action VARCHAR(64).
        foreach (\ActivityLog::ACTIONS as $action) {
            $this->assertLessThanOrEqual(64, strlen($action), "Action '$action' exceeds 64 chars.");
        }
    }

    public function test_log_silently_swallows_db_errors(): void
    {
        // No Database class loaded in test bootstrap → call would normally throw.
        // ActivityLog::log must never propagate exceptions — verify by calling
        // it and asserting nothing happens.
        $this->expectNotToPerformAssertions();
        \ActivityLog::log('auth.login_success');
    }
}
