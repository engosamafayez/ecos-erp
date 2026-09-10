<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Admin\GoLive\Application\DTO\GoLiveResetPreviewDTO;
use Modules\Admin\GoLive\Application\Services\CommerceResetService;
use Modules\Admin\GoLive\Application\Services\FinanceResetService;
use Modules\Admin\GoLive\Application\Services\InventoryResetService;
use Modules\Admin\GoLive\Application\Services\OperationsResetService;
use Modules\Admin\GoLive\Domain\Enums\ResetDomain;
use Modules\Admin\GoLive\Domain\Services\UnsafeCombinationRule;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Organization\Companies\Domain\Services\CompanyLifecycleAuthority;

/**
 * TASK-...-026 §5 — the read-only dry-run. Calls ONLY the four services' `previewCounts()`
 * methods, which are pure SELECT/COUNT queries — no service in this module's Preview path ever
 * calls an `execute()` method or issues a write. See GoLiveResetDryRunIsReadOnlyTest for the
 * proof.
 */
final class PreviewGoLiveResetAction
{
    public function __construct(
        private readonly CommerceResetService $commerce,
        private readonly OperationsResetService $operations,
        private readonly InventoryResetService $inventory,
        private readonly FinanceResetService $finance,
        private readonly CompanyLifecycleAuthority $lifecycle,
    ) {}

    /**
     * @param  list<string>  $selectedDomains  ResetDomain values
     */
    public function execute(string $companyId, array $selectedDomains): GoLiveResetPreviewDTO
    {
        $selected = array_values(array_filter(
            array_map(static fn (string $d) => ResetDomain::tryFrom($d), $selectedDomains),
        ));

        $counts = [];
        foreach ($selected as $domain) {
            $counts[$domain->value] = $this->serviceFor($domain)->previewCounts($companyId);
        }

        $blockers = $this->blockersFor($companyId, $selected);

        return new GoLiveResetPreviewDTO(
            companyId: $companyId,
            selectedDomains: array_map(static fn (ResetDomain $d) => $d->value, $selected),
            counts: $counts,
            blockers: $blockers,
            wooCutover: $this->wooCutoverSnapshot($companyId),
            lifecycleState: $this->lifecycle->stateFor($companyId)->value,
        );
    }

    /**
     * TASK-...-026 §6 — unsafe combinations are blocked, never bypassed.
     *
     * The one rule this checkpoint enforces (see FinanceResetService/InventoryResetService
     * docblocks for why their own link to Commerce is polymorphic, not a real FK — so the DB
     * itself will not stop this combination; only application logic can): clearing Inventory or
     * Finance transactional history while Orders that reference it are deliberately being KEPT
     * (Commerce not also selected, and the company still has Orders) would leave those surviving
     * Orders with no reservation/shipment/posting trail — "clear inventory state while preserving
     * incompatible movement/valuation facts" / "remove Finance postings while leaving dependent
     * balances that derive from them", the exact §6 examples.
     *
     * @param  list<ResetDomain>  $selected
     * @return list<string>
     */
    private function blockersFor(string $companyId, array $selected): array
    {
        $hasCommerce = in_array(ResetDomain::Commerce, $selected, true);
        $remainingOrders = $hasCommerce ? 0 : DB::table('orders')->where('company_id', $companyId)->count();

        return UnsafeCombinationRule::evaluate($selected, $remainingOrders);
    }

    private function serviceFor(ResetDomain $domain): CommerceResetService|OperationsResetService|InventoryResetService|FinanceResetService
    {
        return match ($domain) {
            ResetDomain::Commerce => $this->commerce,
            ResetDomain::Operations => $this->operations,
            ResetDomain::Inventory => $this->inventory,
            ResetDomain::Finance => $this->finance,
        };
    }

    /**
     * TASK-...-026 §12 — surfaces the Task 025-R1 Orders Sync contract as-is; never changes it.
     *
     * @return array<string, mixed>
     */
    private function wooCutoverSnapshot(string $companyId): array
    {
        $channels = Channel::query()
            ->whereHas('brand', fn ($q) => $q->where('company_id', $companyId))
            ->with('credential')
            ->get();

        return $channels->map(static fn (Channel $c) => [
            'channel_id' => $c->id,
            'name' => $c->name,
            'connection_status' => $c->connection_status->value,
            'health_status' => $c->healthStatus()->value,
            'sync_orders' => (bool) $c->sync_orders,
            'orders_sync_watermark_at' => $c->orders_sync_watermark_at?->toIso8601String(),
            'orders_initial_import_policy' => $c->orders_initial_import_policy,
            'orders_sync_activated_at' => $c->orders_sync_activated_at?->toIso8601String(),
            'last_successful_sync_at' => $c->last_successful_sync_at?->toIso8601String(),
            'last_error_at' => $c->last_error_at?->toIso8601String(),
            'last_error_message' => $c->last_error_message,
            'sync_products' => (bool) $c->sync_products,
            'sync_prices' => (bool) $c->sync_prices,
            'sync_stock' => (bool) $c->sync_stock,
            'sync_customers' => (bool) $c->sync_customers,
        ])->all();
    }
}
