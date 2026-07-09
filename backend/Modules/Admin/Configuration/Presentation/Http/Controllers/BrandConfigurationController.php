<?php

declare(strict_types=1);

namespace Modules\Admin\Configuration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Admin\Configuration\Domain\Models\BrandPolicy;
use Modules\Admin\Configuration\Domain\Models\ConfigAuditEntry;
use Modules\Admin\Configuration\Domain\Services\ConfigurationManager;

/**
 * Manages all brand-level policy groups.
 *
 * GET  /configuration/brands/{brandId}/policies                → all groups summary
 * GET  /configuration/brands/{brandId}/policies/{group}        → single group
 * PUT  /configuration/brands/{brandId}/policies/{group}        → update group
 * GET  /configuration/brands/{brandId}/audit                   → audit trail
 */
final class BrandConfigurationController extends Controller
{
    use HasApiResponse;

    public function __construct(private readonly ConfigurationManager $manager) {}

    public function index(string $brandId): JsonResponse
    {
        $policies = BrandPolicy::where('brand_id', $brandId)
            ->orderBy('policy_group')
            ->get(['id', 'brand_id', 'company_id', 'policy_group', 'version', 'is_active', 'updated_at']);

        // Build summary with defaults for groups not yet configured
        $configured = $policies->keyBy('policy_group');

        $summary = collect(BrandPolicy::POLICY_GROUPS)->map(function (string $group) use ($configured, $brandId): array {
            $row = $configured->get($group);
            return [
                'group'       => $group,
                'label'       => ucwords(str_replace('_', ' ', $group)),
                'is_active'   => (bool) ($row?->is_active ?? true),
                'version'     => $row?->version ?? 0,
                'configured'  => $row !== null,
                'updated_at'  => $row?->updated_at?->toIso8601String(),
            ];
        });

        return $this->success($summary->values());
    }

    public function show(string $brandId, string $group): JsonResponse
    {
        abort_if(! in_array($group, BrandPolicy::POLICY_GROUPS, true), 404, 'Unknown policy group.');

        $settings = $this->manager->getBrandPolicy($brandId, $group);

        $meta = BrandPolicy::where('brand_id', $brandId)
            ->where('policy_group', $group)
            ->first(['id', 'version', 'is_active', 'updated_at', 'updated_by']);

        return $this->success([
            'group'      => $group,
            'settings'   => $settings,
            'version'    => $meta?->version ?? 0,
            'is_active'  => $meta?->is_active ?? true,
            'updated_at' => $meta?->updated_at?->toIso8601String(),
            'updated_by' => $meta?->updated_by,
        ]);
    }

    public function update(Request $request, string $brandId, string $group): JsonResponse
    {
        abort_if(! in_array($group, BrandPolicy::POLICY_GROUPS, true), 404, 'Unknown policy group.');

        $validated = $request->validate([
            'settings' => 'required|array',
            'reason'   => 'nullable|string|max:500',
        ]);

        $actorId   = Auth::id() ?? '';
        $companyId = Auth::user()?->company_id ?? '';

        $policy = $this->manager->updateBrandPolicy(
            brandId:   $brandId,
            companyId: $companyId,
            group:     $group,
            settings:  $validated['settings'],
            actorId:   $actorId,
            reason:    $validated['reason'] ?? null,
        );

        return $this->updated([
            'group'      => $group,
            'settings'   => $policy->settings,
            'version'    => $policy->version,
            'updated_at' => $policy->updated_at?->toIso8601String(),
        ], 'Policy updated.');
    }

    public function audit(Request $request, string $brandId): JsonResponse
    {
        $limit = min((int) $request->query('limit', 50), 200);

        $entries = ConfigAuditEntry::where('brand_id', $brandId)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();

        return $this->success($entries);
    }
}
