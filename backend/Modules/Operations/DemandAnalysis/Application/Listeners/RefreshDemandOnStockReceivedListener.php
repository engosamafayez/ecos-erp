<?php

declare(strict_types=1);

namespace Modules\Operations\DemandAnalysis\Application\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\DomainEvents\Events\InventoryStockReceived;
use Modules\Operations\DemandAnalysis\Application\Services\DemandProjectionBuilder;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Throwable;

/**
 * Material arrives → the waves that need it are re-projected → products that were
 * WAITING_MATERIAL become READY. No polling, no operator action.
 *
 * This is the recovery leg of the owner's preparation contract. Until now NO inventory
 * event rebuilt the demand projection: every live trigger was an order-membership or
 * wave-lifecycle event, so a wave's material figures were stale from the moment stock
 * moved until somebody added, removed or postponed an order.
 *
 * NO SECOND ENGINE. It reuses the existing `InventoryStockReceived` domain event (already
 * published by ReceiveStockAction after commit) and the existing DemandProjectionBuilder.
 * Nothing new is computed here — the listener only decides WHICH waves to re-project.
 *
 * WHY THE STATUS SET IS WIDER THAN THE OLD DRAFT. The never-wired predecessor filtered to
 * `['collecting','preparing']`, which excludes `planning` and `shortage_blocked` — exactly
 * the states a wave sits in while it waits for material, i.e. the only ones that needed
 * recovering. Terminal states (completed / cancelled / closed) are deliberately excluded.
 *
 * TENANT-SAFE: company_id and warehouse_id are both predicates, so one company's receipt
 * can never re-project another's wave.
 * BOUNDED: only waves whose own material demand already contains the received product.
 * IDEMPOTENT: a rebuild is a recompute of a projection, not an accumulation — replaying
 * the same event converges on the same rows.
 */
final class RefreshDemandOnStockReceivedListener
{
    /** Wave states in which a material arrival can still change the outcome. */
    private const RECOVERABLE_STATUSES = ['draft', 'collecting', 'planning', 'shortage_blocked', 'preparing'];

    public function __construct(private readonly DemandProjectionBuilder $builder) {}

    public function handle(InventoryStockReceived $event): void
    {
        // Only an arrival that actually RAISES on-hand can unblock anything.
        if ($event->onHandAfter <= $event->onHandBefore) {
            return;
        }

        $waveIds = DB::table('wave_material_demand as wmd')
            ->join('preparation_waves as pw', 'pw.id', '=', 'wmd.preparation_wave_id')
            ->where('wmd.material_id', $event->productId)
            ->where('pw.company_id', $event->companyId)
            ->where('pw.warehouse_id', $event->warehouseId)
            ->whereIn('pw.status', self::RECOVERABLE_STATUSES)
            ->distinct()
            ->pluck('wmd.preparation_wave_id')
            ->all();

        foreach ($waveIds as $waveId) {
            $wave = PreparationWave::find($waveId);

            if ($wave === null) {
                continue;
            }

            try {
                $this->builder->buildFull($wave, 'stock_received');
            } catch (Throwable $e) {
                // A projection refresh must never fail the inventory movement that
                // triggered it, and one wave must never abort the rest.
                report($e);

                Log::error('[DemandRefresh] Wave re-projection failed after stock receipt.', [
                    'wave_id' => $waveId,
                    'product_id' => $event->productId,
                    'company_id' => $event->companyId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
