<?php

declare(strict_types=1);

namespace Modules\Finance\Integration\Application\Listeners;

use BackedEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Finance\Integration\Application\Services\FinancialIntegrationService;
use Modules\Finance\Integration\Domain\Enums\BusinessEventType;
use Modules\Finance\Integration\Domain\ValueObjects\FinancialEvent;
use Modules\Logistics\Fleet\Domain\Events\VehicleCostPosted;
use Throwable;

/**
 * Finance's subscriber to Fleet's vehicle-cost signal (TASK-ECOS-FINANCE-
 * OPERATIONAL-COST-ACCOUNTING-007, FIN-EXEC-07). Confirmed by this task's
 * own research to have zero subscribers anywhere in the codebase before now
 * — the same class of gap Task 6 closed for CodCollected.
 *
 * ┌─ WHY VehicleCostPosted ALONE, NOT FuelTransactionRecorded TOO ──────────┐
 * │ FuelTransactionRecorded fires when a fuel transaction is captured — well  │
 * │ before its own Captured→Validated→{Reconciled|Disputed}→WrittenOff        │
 * │ lifecycle reaches a state FuelReconciliationService::postsCost() accepts  │
 * │ (Reconciled/WrittenOff only). Posting off it directly would recognise an   │
 * │ unapproved fuel cost — exactly what TASK §4 forbids. FuelReconciliation-   │
 * │ Service::postCost() already calls VehicleCostService::post() once a fuel   │
 * │ transaction IS reconciled/written off, which itself fires VehicleCost-     │
 * │ Posted — so this one listener already covers approved fuel cost too,       │
 * │ with no separate wiring and no risk of posting too early.                  │
 * │                                                                            │
 * │ fleet_cost_entries has no approval column at all (confirmed) — a           │
 * │ CostEntry is effective the moment it exists, which is this task's own       │
 * │ "unless source architecture explicitly proves otherwise" exception to the   │
 * │ approved-state rule.                                                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * company_id on fleet_cost_entries is NULLABLE (confirmed) — an entry
 * without one cannot be posted to any company's ledger and is skipped, never
 * guessed.
 */
final class PostFleetCostOnVehicleCostPosted
{
    private const SOURCE_MODULE = 'logistics.fleet';

    public function __construct(private readonly FinancialIntegrationService $integration) {}

    public function handle(VehicleCostPosted $event): void
    {
        $entry = $event->entry;

        if ($entry->company_id === null) {
            Log::channel('daily')->error('[PostFleetCostOnVehicleCostPosted] Cost entry has no company_id — cannot post', [
                'cost_entry_id' => $entry->id,
            ]);

            return;
        }

        try {
            $costType = $entry->cost_type instanceof BackedEnum ? $entry->cost_type->value : (string) $entry->cost_type;

            $financialEvent = new FinancialEvent(
                companyId: (string) $entry->company_id,
                eventType: BusinessEventType::ShipmentCost,
                sourceModule: self::SOURCE_MODULE,
                entityType: 'fleet_cost_entry',
                entityId: (string) $entry->id,
                amounts: ['cost' => round((float) $entry->amount, 4)],
                occurredAt: $entry->incurred_on !== null ? Carbon::parse($entry->incurred_on) : Carbon::now(),
                idempotencyKey: 'fleet_cost_entry:'.$entry->id,
                actorId: $event->actor !== null && is_numeric($event->actor) ? (int) $event->actor : null,
                reference: (string) $entry->fleet_unit_id,
                description: 'Fleet cost — '.$costType.' — unit '.$entry->fleet_unit_id,
            );

            $this->integration->recordAsync($financialEvent);
        } catch (Throwable $e) {
            Log::channel('daily')->error('[PostFleetCostOnVehicleCostPosted] Failed to translate/post fleet cost', [
                'cost_entry_id' => $entry->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
