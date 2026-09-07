<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\IAM\Domain\Catalog\PermissionBusinessCatalog;
use Modules\IAM\Domain\Models\Permission;

/**
 * IAM / Admin / Permissions — the business Permission Directory
 * (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §13).
 *
 * STILL READ-ONLY, and still for the original reason: `PermissionRegistry::sync()` — the
 * one method capable of minting a permission row — is never called from here or from
 * anywhere reachable from here. It stays dormant (DO NOT REIMPLEMENT / DO NOT WIRE). This
 * endpoint reads `permissions` and nothing else, and it is gated entirely by route-level
 * `permission:iam.permissions.view` middleware because a catalogue listing has no
 * per-instance tenant logic — permissions are global definitions, not company-owned rows.
 *
 * WHAT CHANGED: the payload. §13 found the directory developer-centric — a wall of
 * `finance.ap.advance.apply`-shaped keys. Each permission is now returned with its Arabic
 * business name, its Arabic business description, its business module group and a
 * sensitivity band, composed by `PermissionBusinessCatalog` (a pure presentation
 * dictionary that mints nothing). The canonical key remains present, verbatim, as `name` —
 * §13's "raw canonical permission key should appear as secondary technical detail", not
 * removed. Groups are ordered by business module, and every legacy field the previous
 * payload carried (`name`, `resource`, `action`, `description`) is still there, so no
 * existing consumer breaks.
 *
 * `PermissionGroup`/`group_id` remains fully inert (ADR-038 Phase 1 — re-confirmed:
 * `permission_groups` has zero rows and every `permissions.group_id` is null on DEV), so
 * grouping continues to parse the permission's own `module` segment. That is the same
 * near-term approach the architecture report recommended, now with business labels on top
 * of it rather than the bare module slug.
 */
final class PermissionController extends Controller
{
    use HasApiResponse;

    public function index(): JsonResponse
    {
        $permissions = Permission::query()
            ->orderBy('module')
            ->orderBy('resource')
            ->orderBy('action')
            ->get();

        $described = $permissions
            ->map(fn (Permission $p) => PermissionBusinessCatalog::describe(
                (string) $p->name,
                $p->description,
            ) + [
                // Preserved verbatim from the previous contract.
                'resource_raw' => $p->resource,
                'description' => $p->description,
            ]);

        $groups = $described
            ->groupBy('module')
            ->map(function ($items, $module) {
                $meta = PermissionBusinessCatalog::moduleLabel((string) $module);

                return [
                    'module' => $module,
                    'label_ar' => $meta['ar'],
                    'label_en' => $meta['en'],
                    'sort' => $meta['sort'],
                    'count' => $items->count(),
                    'sensitive_count' => $items->where('sensitivity', '!=', PermissionBusinessCatalog::SENSITIVITY_NORMAL)->count(),
                    'permissions' => $items->values()->all(),
                ];
            })
            ->sortBy('sort')
            ->values()
            ->all();

        return $this->success([
            'groups' => $groups,
            'total' => $permissions->count(),
            'sensitivity_levels' => [
                PermissionBusinessCatalog::SENSITIVITY_NORMAL,
                PermissionBusinessCatalog::SENSITIVITY_ELEVATED,
                PermissionBusinessCatalog::SENSITIVITY_CRITICAL,
            ],
        ]);
    }
}
