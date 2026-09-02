<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\IAM\Domain\Models\Permission;

/**
 * IAM / Admin / Permissions (TASK-ECOS-IAM-SECURE-ADMIN-API-002, §8/§13) — read-only, by
 * explicit CTO-ratified decision (Recommendation B, Task 1 §8): view the catalog, assign
 * EXISTING permissions to templates. `PermissionRegistry::sync()` — the one method capable of
 * minting a new permission row — is never called from here or anywhere reachable from this
 * controller; it stays dormant (DO NOT REIMPLEMENT / DO NOT WIRE). Gated entirely by route-level
 * `permission:iam.permissions.view` middleware — a catalog listing has no per-instance tenant
 * logic (permissions are global definitions, not company-owned rows).
 */
final class PermissionController extends Controller
{
    use HasApiResponse;

    /**
     * `PermissionGroup`/`group_id` is still fully inert (ADR-038 Phase 1 — confirmed unchanged
     * in Task 1's pass), so grouping here parses the existing `domain.resource.action` name
     * (module + resource) as the near-term, zero-new-code approach Task 1 recommended, rather
     * than waiting on group_id being populated.
     */
    public function index(): JsonResponse
    {
        $permissions = Permission::query()->orderBy('module')->orderBy('resource')->orderBy('action')->get();

        $grouped = $permissions->groupBy('module')->map(function ($items, $module) {
            return [
                'module' => $module,
                'permissions' => $items->map(fn (Permission $p) => [
                    'name' => $p->name,
                    'resource' => $p->resource,
                    'action' => $p->action,
                    'description' => $p->description,
                ])->values()->all(),
            ];
        })->values()->all();

        return $this->success(['groups' => $grouped, 'total' => $permissions->count()]);
    }
}
