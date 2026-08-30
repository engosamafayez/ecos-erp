<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Application\Listeners;

use App\Core\FeatureFlags\FeatureFlagService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\DomainEvents\Events\InventoryStockReceived;
use Throwable;

/**
 * When raw material stock arrives, check if any shortage-blocked waves
 * now have their shortages resolved. If so, log and let the planner decide.
 *
 * INTEGRATION-DESIGN.md §5.2
 */
final class StockAddedListener
{
    public function __construct(private readonly FeatureFlagService $flags) {}

    public function handle(InventoryStockReceived $event): void
    {
        if ($this->flags->isDisabled('workflow.stages.preparation', $event->companyId)) {
            return;
        }

        try {
            $unresolved = DB::table('preparation_material_requirements as pmr')
                ->join('preparation_waves as pw', 'pw.id', '=', 'pmr.preparation_wave_id')
                ->where('pw.status', 'shortage_blocked')
                ->where('pw.company_id', $event->companyId)
                ->where('pw.warehouse_id', $event->warehouseId)
                ->where('pmr.raw_material_id', $event->productId)
                ->where('pmr.shortage', true)
                ->where('pmr.resolved', false)
                ->select('pmr.id', 'pmr.preparation_wave_id', 'pmr.quantity_required', 'pw.wave_number')
                ->get();

            if ($unresolved->isEmpty()) {
                return;
            }

            // One read per event, not per requirement: every row in $unresolved shares
            // the same (product_id, warehouse_id) pair, so the figure is identical.
            //
            // Column is `on_hand_qty`. It was previously spelled `on_hand_quantity`,
            // which does not exist on `inventory_items` — every dispatch raised a
            // QueryException that the catch below turned into a log line, so this
            // recovery path had never once executed.
            $currentStock = (float) DB::table('inventory_items')
                ->whereNull('deleted_at')
                ->where('product_id', $event->productId)
                ->where('warehouse_id', $event->warehouseId)
                ->sum('on_hand_qty');

            foreach ($unresolved as $req) {
                if ($currentStock >= $req->quantity_required) {
                    DB::table('preparation_material_requirements')
                        ->where('id', $req->id)
                        ->update(['resolved' => true, 'updated_at' => now()]);

                    Log::channel('daily')->info('[Preparation] Shortage resolved by stock arrival', [
                        'wave_number' => $req->wave_number,
                        'wave_id' => $req->preparation_wave_id,
                        'material_id' => $event->productId,
                        'stock_arrived' => $event->quantityReceived,
                        'stock_on_hand' => $currentStock,
                        'qty_required' => $req->quantity_required,
                    ]);
                }
            }
        } catch (Throwable $e) {
            // Stock receipt must not fail because a downstream projection did, so the
            // exception is still contained here — but it is no longer swallowed.
            // report() routes it to the configured error handler, which is what makes
            // a programming error (a bad column, a renamed table) visible instead of
            // decaying into a log line nobody reads. ENTERPRISE-FULFILLMENT-PLATFORM:
            // "Exceptions are first-class — none are swallowed silently."
            report($e);

            Log::channel('daily')->error('[Preparation] StockAddedListener failed', [
                'product_id' => $event->productId,
                'warehouse_id' => $event->warehouseId,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
