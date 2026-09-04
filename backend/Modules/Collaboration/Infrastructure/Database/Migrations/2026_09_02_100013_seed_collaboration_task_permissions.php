<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Mirrors 2026_09_02_100006_seed_collaboration_permissions.php exactly (the
 * Task 2 permission-catalog persistence remediation) for the two new tokens
 * Task 4 needs. A separate migration rather than editing that one — an
 * already-committed, already-"run" migration is never retroactively edited
 * in this codebase's convention.
 *
 * Only two new tokens (brief §26 — "do not create a permission for every
 * button/action"): task creation is a coarse capability gate, exactly like
 * `collaboration.conversations.create`; task view/comment/status-transition
 * remain ownership-gated (creator/assignee), not permission-gated, exactly
 * like conversation participation — see TaskPolicy. Employee->driver task
 * assignment gets its own token, separate from
 * `collaboration.conversations.message_drivers`, because a company may
 * reasonably want to grant one without the other.
 *
 * No role grants here either — same CTO ruling as Task 2's remediation
 * (permission-definition persistence must never double as authorization).
 */
return new class extends Migration
{
    /**
     * [name, resource, action, description]
     *
     * @var list<array{0:string,1:string,2:string,3:string}>
     */
    private const PERMISSIONS = [
        ['collaboration.tasks.create', 'tasks', 'create', 'Create an internal Collaboration task'],
        ['collaboration.tasks.assign_drivers', 'tasks', 'assign_drivers', 'Assign an internal task to a driver (also requires IAM data scope)'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach (self::PERMISSIONS as [$name, $resource, $action, $description]) {
            if (DB::table('permissions')->where('name', $name)->exists()) {
                continue;
            }

            DB::table('permissions')->insert([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'module' => 'collaboration',
                'resource' => $resource,
                'action' => $action,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // No grants applied — see this file's docblock.
    }

    public function down(): void {}
};
