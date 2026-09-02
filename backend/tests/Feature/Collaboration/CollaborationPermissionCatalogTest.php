<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\IAM\Domain\Models\Permission;
use Tests\TestCase;

/**
 * TASK-ECOS-COLLABORATION-CORE-FOUNDATION-002 — CTO Remediation: permission
 * catalog persistence closure.
 *
 * Proves the source contract added by
 * 2026_09_02_100006_seed_collaboration_permissions.php, not just that a test
 * can manually insert the rows it needs (every other Collaboration test does
 * that deliberately, matching DriverRbacTenancySecurityTest's precedent —
 * these four are the ones that instead prove the migration itself works).
 */
final class CollaborationPermissionCatalogTest extends TestCase
{
    use DatabaseTransactions;

    private const NAMES = [
        'collaboration.conversations.create',
        'collaboration.conversations.message_drivers',
        'collaboration.groups.create',
    ];

    private function migrationPath(): string
    {
        return base_path('Modules/Collaboration/Infrastructure/Database/Migrations/2026_09_02_100006_seed_collaboration_permissions.php');
    }

    // The test database is migrated before the suite runs, so by the time any
    // test executes, this migration has already applied — no manual seeding
    // in this test proves the persistence path itself works, not a stand-in.
    public function test_collaboration_permissions_are_persisted_without_any_manual_seeding_in_this_test(): void
    {
        foreach (self::NAMES as $name) {
            self::assertTrue(
                Permission::query()->where('name', $name)->exists(),
                "Expected '{$name}' to already exist from the migration, not from test setup.",
            );
        }
    }

    public function test_running_the_seed_migration_twice_creates_no_duplicate_rows(): void
    {
        $before = Permission::query()->whereIn('name', self::NAMES)->count();

        /** @var \Illuminate\Database\Migrations\Migration $migration */
        $migration = require $this->migrationPath();
        $migration->up();

        $after = Permission::query()->whereIn('name', self::NAMES)->count();

        self::assertSame($before, $after, 'Re-running the migration must be a no-op once the rows exist.');
        self::assertSame(3, $after);
    }

    // CTO ruling (this remediation §8): persisting a permission's existence
    // must never double as granting it. This migration applies no
    // role_permissions rows at all — verified directly against the pivot
    // table, not inferred from the migration's own source.
    public function test_persisting_the_permission_definitions_grants_them_to_no_role(): void
    {
        $permissionIds = Permission::query()->whereIn('name', self::NAMES)->pluck('id');

        $grantCount = DB::table('role_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->count();

        self::assertSame(0, $grantCount, 'Seeding permission definitions must not itself grant them to any role.');
    }

    // Fail-closed contract for anything never declared in the catalog
    // (config/permissions.php) or seeded by this migration.
    public function test_an_unregistered_permission_token_does_not_exist_in_the_catalogue(): void
    {
        self::assertFalse(
            Permission::query()->where('name', 'collaboration.conversations.delete_forever')->exists(),
            'A permission token that was never declared must never silently appear in the catalogue.',
        );
    }
}
