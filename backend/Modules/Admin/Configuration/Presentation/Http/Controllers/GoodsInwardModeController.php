<?php

declare(strict_types=1);

namespace Modules\Admin\Configuration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Admin\Configuration\Domain\Services\ConfigAuditService;
use Modules\Inventory\InventoryItems\Domain\Enums\GoodsInwardMode;
use Modules\Inventory\InventoryItems\Domain\Services\GoodsInwardAuthority;

/**
 * Company configuration — the authoritative goods-inward document (G-1).
 *
 * GET /configuration/procurement/goods-inward-mode
 * PUT /configuration/procurement/goods-inward-mode
 *
 * This exposes ONE already-certified setting; it decides nothing itself.
 * `GoodsInwardAuthority` remains the sole business authority for which document posts
 * inventory, and this controller only reads and writes the value that authority consults.
 *
 * TENANT OWNERSHIP is structural, exactly as every other endpoint in this module does it: the
 * company comes from the authenticated actor and there is no company identifier anywhere in
 * the route or the payload, so there is nothing for a caller to tamper with. An actor can only
 * ever address their own company's setting. No database constraint is relied upon.
 *
 * `companies.goods_inward_mode` is written directly rather than through
 * `ConfigurationManager::setCompanySetting()`, because that manager persists into the
 * `config_company_settings` key-value store while the certified inbound authority reads the
 * column. Introducing a second home for the same value — or re-pointing the certified
 * authority at the key-value store — would reopen a certified contract for no gain. The audit
 * half of the manager IS reused, so this change is recorded exactly like every other
 * configuration change.
 */
final class GoodsInwardModeController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly GoodsInwardAuthority $authority,
        private readonly ConfigAuditService $audit,
    ) {}

    public function show(): JsonResponse
    {
        $companyId = (string) (Auth::user()?->company_id ?? '');

        return $this->success($this->present($companyId));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', Rule::in(array_column(GoodsInwardMode::cases(), 'value'))],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $companyId = (string) (Auth::user()?->company_id ?? '');

        if ($companyId === '') {
            return $this->error('No company context for the current actor.', 403);
        }

        $current = $this->authority->modeFor($companyId);
        $next = GoodsInwardMode::from($validated['mode']);

        // Idempotent: re-selecting the current mode is a successful no-op and is deliberately
        // NOT written to the audit trail, so the log records real changes only.
        if ($current !== $next) {
            DB::table('companies')
                ->where('id', $companyId)
                ->update(['goods_inward_mode' => $next->value]);

            $this->authority->forget($companyId);

            $this->audit->record(
                companyId: $companyId,
                module: 'procurement',
                category: 'goods_inward',
                action: 'update',
                oldValue: $current->value,
                newValue: $next->value,
                configKey: 'goods_inward_mode',
                reason: $validated['reason'] ?? null,
            );
        }

        return $this->updated($this->present($companyId), 'Goods inward mode updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $companyId): array
    {
        $stored = $companyId === ''
            ? null
            : DB::table('companies')->where('id', $companyId)->value('goods_inward_mode');

        $effective = $this->authority->modeFor($companyId);

        return [
            'mode' => $effective->value,
            'label' => $effective->label(),
            // True when no explicit choice is stored and the platform default is in force.
            // The frontend renders a "Default" marker from this; it never computes the
            // default itself — the backend owns it.
            'is_default' => $stored === null || $stored === '',
            'default_mode' => GoodsInwardMode::default()->value,
            'options' => array_map(
                static fn (GoodsInwardMode $m): array => [
                    'value' => $m->value,
                    'label' => $m->label(),
                ],
                GoodsInwardMode::cases(),
            ),
        ];
    }
}
