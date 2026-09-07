<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The canonical organization-entity directory for user scope assignment
 * (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §9).
 *
 * §9 requires an administrator to PICK real entities — Company → Brands → Branches →
 * Warehouses → Regions → Channels → Teams → Business Units — instead of typing a raw
 * `type` / `id` / `label` triple, and it qualifies that with "only where those entities
 * actually exist in the canonical organization model".
 *
 * NOT A SECOND DIRECTORY. Every row this service returns is read straight out of the
 * canonical organization table that already owns it (`companies`, `brands`, `branches`,
 * `warehouses`, `channels`, `teams`, `business_accounts`, `network_dispatch_regions`).
 * There is no IAM-side copy, no cache, no mirror table, and this service performs no
 * writes whatsoever.
 *
 * WHY IT READS THE TABLES RATHER THAN CALLING THE ORG MODULE'S OWN ENDPOINTS: each of
 * those endpoints is gated by its own `organization.*` / `inventory.warehouses.view` /
 * `sales.channels.view` permission. Requiring an IAM administrator to additionally hold
 * seven unrelated module permissions just to fill in a scope picker would either force
 * permission inflation on the IAM role or leave the picker empty. This is a narrow,
 * read-only, id+name+parent projection behind the IAM administrator's own
 * `iam.users.view` token — deliberately the smallest possible surface, and it exposes no
 * operational or financial column.
 *
 * AUTHORITATIVE VALIDATION LIVES HERE TOO. `exists()` is what
 * `UserOrganizationAssignmentService` calls before persisting an assignment, so a
 * hand-crafted request naming a nonexistent warehouse is rejected server-side. Before this
 * task, `org_id` was an unvalidated nullable string.
 */
class OrganizationScopeDirectory
{
    /**
     * Declarative map of the org-unit types §9 names to the canonical table that owns
     * them, in the hierarchical order the picker presents.
     *
     * `parents` records which already-selected type narrows this one, and `parent_column`
     * the column that expresses it. `company` is the root and has neither.
     *
     * Types present in `UserOrganizationAssignmentService::TYPES` but absent here —
     * `department`, `cost_center` — are intentionally omitted: §9 does not list them, and
     * exposing a picker for a table with no rows and no place in the requested hierarchy
     * would be noise. They remain assignable through the existing single-assignment
     * endpoint, unchanged.
     *
     * @var array<string,array{table:string,label_ar:string,label_en:string,parents:array<string,string>,order:int,code_column:?string}>
     */
    private const SOURCES = [
        'company' => [
            'table' => 'companies',
            'label_ar' => 'الشركة',
            'label_en' => 'Company',
            'parents' => [],
            'order' => 10,
            'code_column' => 'code',
        ],
        'brand' => [
            'table' => 'brands',
            'label_ar' => 'العلامة التجارية',
            'label_en' => 'Brand',
            'parents' => ['company' => 'company_id'],
            'order' => 20,
            'code_column' => 'code',
        ],
        'branch' => [
            'table' => 'branches',
            'label_ar' => 'الفرع',
            'label_en' => 'Branch',
            'parents' => ['company' => 'company_id'],
            'order' => 30,
            'code_column' => 'code',
        ],
        'warehouse' => [
            'table' => 'warehouses',
            'label_ar' => 'المخزن',
            'label_en' => 'Warehouse',
            'parents' => ['company' => 'company_id'],
            'order' => 40,
            'code_column' => 'code',
        ],
        'region' => [
            'table' => 'network_dispatch_regions',
            'label_ar' => 'المنطقة',
            'label_en' => 'Region',
            'parents' => ['company' => 'company_id', 'warehouse' => 'warehouse_id', 'branch' => 'branch_id'],
            'order' => 50,
            'code_column' => 'code',
        ],
        'channel' => [
            // `channels` has no company_id — it reaches a company THROUGH its brand, which is
            // exactly the hierarchy §9 asks the picker to express.
            'table' => 'channels',
            'label_ar' => 'القناة',
            'label_en' => 'Channel',
            'parents' => ['brand' => 'brand_id', 'business_unit' => 'business_account_id'],
            'order' => 60,
            'code_column' => 'code',
        ],
        'team' => [
            'table' => 'teams',
            'label_ar' => 'الفريق',
            'label_en' => 'Team',
            'parents' => ['company' => 'company_id'],
            'order' => 70,
            'code_column' => 'code',
        ],
        'business_unit' => [
            // `business_accounts` IS the canonical business-unit entity in this model.
            'table' => 'business_accounts',
            'label_ar' => 'وحدة الأعمال',
            'label_en' => 'Business unit',
            'parents' => ['company' => 'company_id', 'brand' => 'brand_id'],
            'order' => 80,
            'code_column' => 'code',
        ],
    ];

    /** The org-unit types this directory can resolve. @return list<string> */
    public static function types(): array
    {
        return array_keys(self::SOURCES);
    }

    /**
     * The whole hierarchy, optionally narrowed to one company and filtered by a search term.
     *
     * A type whose table does not exist in this installation is returned with
     * `available: false` and no entities, so the UI can hide it rather than render an
     * empty, mysterious picker. A type whose table exists but is empty is `available:
     * true` with `total: 0` — that is a real "nothing configured yet" state, not a missing
     * capability, and §9's "only where those entities actually exist" is about the MODEL,
     * not about whether rows have been created.
     *
     * @return list<array<string,mixed>>
     */
    public function hierarchy(?string $companyId = null, ?string $search = null, int $perType = 200): array
    {
        $levels = [];

        foreach (self::SOURCES as $type => $source) {
            if (! Schema::hasTable($source['table'])) {
                $levels[] = [
                    'type' => $type,
                    'label_ar' => $source['label_ar'],
                    'label_en' => $source['label_en'],
                    'order' => $source['order'],
                    'parents' => $source['parents'],
                    'available' => false,
                    'total' => 0,
                    'entities' => [],
                ];

                continue;
            }

            $entities = $this->entities($type, $companyId, $search, $perType);

            $levels[] = [
                'type' => $type,
                'label_ar' => $source['label_ar'],
                'label_en' => $source['label_en'],
                'order' => $source['order'],
                'parents' => $source['parents'],
                'available' => true,
                'total' => count($entities),
                'entities' => $entities,
            ];
        }

        usort($levels, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $levels;
    }

    /**
     * One level's entities as `{ id, name, code, parents: { <parentType>: <parentId> } }`.
     *
     * @return list<array<string,mixed>>
     */
    public function entities(string $type, ?string $companyId = null, ?string $search = null, int $limit = 200): array
    {
        $source = self::SOURCES[$type] ?? null;

        if ($source === null || ! Schema::hasTable($source['table'])) {
            return [];
        }

        $table = $source['table'];
        $columns = ['id', 'name'];

        if ($source['code_column'] !== null && Schema::hasColumn($table, $source['code_column'])) {
            $columns[] = $source['code_column'];
        }

        $parentColumns = [];
        foreach ($source['parents'] as $parentType => $column) {
            if (Schema::hasColumn($table, $column)) {
                $parentColumns[$parentType] = $column;
                $columns[] = $column;
            }
        }

        $query = DB::table($table)->select(array_values(array_unique($columns)));

        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        if (Schema::hasColumn($table, 'is_active')) {
            $query->where('is_active', true);
        }

        // Narrowing to a company: direct when the table carries company_id, otherwise
        // through the parent that does. `channels` is the case that needs it.
        if ($companyId !== null) {
            if (Schema::hasColumn($table, 'company_id')) {
                $query->where('company_id', $companyId);
            } elseif (isset($parentColumns['brand']) && Schema::hasTable('brands')) {
                $query->whereIn($parentColumns['brand'], function ($sub) use ($companyId) {
                    $sub->select('id')->from('brands')->where('company_id', $companyId);
                });
            }
        }

        if ($search !== null && $search !== '') {
            $term = '%'.$search.'%';
            $query->where(function ($q) use ($term, $table, $source) {
                $q->where('name', 'like', $term);
                if ($source['code_column'] !== null && Schema::hasColumn($table, $source['code_column'])) {
                    $q->orWhere($source['code_column'], 'like', $term);
                }
            });
        }

        return $query->orderBy('name')->limit($limit)->get()
            ->map(function ($row) use ($parentColumns, $source): array {
                $parents = [];
                foreach ($parentColumns as $parentType => $column) {
                    $value = $row->{$column} ?? null;
                    if ($value !== null) {
                        $parents[$parentType] = (string) $value;
                    }
                }

                $codeColumn = $source['code_column'];

                return [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'code' => $codeColumn !== null && isset($row->{$codeColumn}) ? (string) $row->{$codeColumn} : null,
                    'parents' => $parents,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Does this org unit really exist? The authoritative server-side scope check §9
     * demands, called by UserOrganizationAssignmentService before it persists anything.
     *
     * A type with no canonical table (`department`, `cost_center`) returns true: those
     * remain forward-compatible free-form assignments, exactly as documented on
     * UserOrganizationAssignment, and this method must not retroactively invalidate them.
     */
    public function exists(string $type, ?string $id): bool
    {
        $source = self::SOURCES[$type] ?? null;

        if ($source === null) {
            return true;
        }

        if (! Schema::hasTable($source['table'])) {
            return true;
        }

        // A typed assignment with no id means "the whole type" (e.g. every warehouse) —
        // meaningful, pre-existing behaviour, and there is no row to verify.
        if ($id === null || $id === '') {
            return true;
        }

        return DB::table($source['table'])->where('id', $id)->exists();
    }

    /** The entity's display name, so an assignment's label need never be typed by hand (§9). */
    public function labelFor(string $type, ?string $id): ?string
    {
        $source = self::SOURCES[$type] ?? null;

        if ($source === null || $id === null || $id === '' || ! Schema::hasTable($source['table'])) {
            return null;
        }

        $row = DB::table($source['table'])->select('name')->where('id', $id)->first();

        return $row !== null ? (string) $row->name : null;
    }

    /** True when this type is backed by a canonical table in this installation. */
    public function isCanonical(string $type): bool
    {
        $source = self::SOURCES[$type] ?? null;

        return $source !== null && Schema::hasTable($source['table']);
    }
}
