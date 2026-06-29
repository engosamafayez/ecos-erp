<?php

declare(strict_types=1);

namespace Modules\Manufacturing\ManufacturingExecution\Application\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use JsonException;
use Modules\Inventory\InventoryItems\Domain\Contracts\InventoryItemRepositoryInterface;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Manufacturing\ManufacturingExecution\Domain\Contracts\ManufacturingTransactionRepositoryInterface;
use Modules\Manufacturing\ManufacturingExecution\Domain\Enums\TransactionStatus;
use Modules\Manufacturing\ManufacturingExecution\Domain\Exceptions\ExecutionException;
use Modules\Manufacturing\ManufacturingExecution\Domain\Models\ManufacturingTransaction;
use Modules\Manufacturing\ManufacturingExecution\Domain\ValueObjects\ComponentConsumptionRecord;
use Modules\Manufacturing\ManufacturingExecution\Domain\ValueObjects\ManufacturingExecutionResult;
use Modules\Manufacturing\ManufacturingPlanner\Domain\ValueObjects\ComponentConsumptionPlan;
use Modules\Manufacturing\ManufacturingPlanner\Domain\ValueObjects\ManufacturingPlan;

/**
 * The only component that executes a ManufacturingPlan.
 *
 * EXECUTE ONLY: Does not resolve recipes, check availability, build plans,
 * evaluate rules, make decisions, or select recipe versions.
 * All decisions have already been made by the time this service is called.
 *
 * Execution guarantees:
 *   - Idempotent: plan_id UNIQUE on manufacturing_transactions prevents double execution
 *   - Transactional: all inventory changes + ledger entries + transaction record commit together
 *   - Rollback: any failure inside DB::transaction() reverts all inventory changes
 *   - Snapshot integrity: recipe_snapshot is re-hashed and compared before any DB write
 *
 * @param string $companyId  The company context for InventoryItem creation.
 *                           Must match the company of the warehouse in the plan.
 */
final class ManufacturingExecutor
{
    public function __construct(
        private readonly InventoryItemRepositoryInterface $inventoryItems,
        private readonly ManufacturingTransactionRepositoryInterface $transactions,
    ) {}

    /**
     * Execute a ManufacturingPlan.
     *
     * @throws ExecutionException  On pre-execution guard failures (no DB writes occur).
     * @throws \Throwable          On in-transaction failures (all DB changes rolled back).
     */
    public function execute(ManufacturingPlan $plan, string $companyId): ManufacturingExecutionResult
    {
        // ── Pre-execution guards (no DB writes below this line) ────────────────

        if (!$plan->should_manufacture) {
            throw ExecutionException::planNotApproved($plan->plan_id, $plan->eligibility->value);
        }

        if ($plan->recipe_snapshot_hash === null) {
            throw ExecutionException::snapshotMissing($plan->plan_id);
        }

        if ($plan->recipe_snapshot !== null) {
            $this->verifySnapshotIntegrity($plan);
        }

        // ── Idempotency check ──────────────────────────────────────────────────

        $existing = $this->transactions->findByPlanId($plan->plan_id);
        if ($existing !== null) {
            return $this->buildIdempotentResult($existing);
        }

        // ── Execute inside a single database transaction ───────────────────────

        $executionId = $this->generateUuid();
        $startMs     = (int) (microtime(true) * 1000);

        /** @var ManufacturingExecutionResult $result */
        $result = DB::transaction(function () use ($plan, $companyId, $executionId, $startMs): ManufacturingExecutionResult {
            $ledgerIds   = [];

            // 1. Consume raw materials
            $consumptionRecords = [];
            foreach ($plan->components as $component) {
                [$record, $entryId] = $this->consumeComponent($component, $plan, $companyId, $executionId);
                $consumptionRecords[] = $record;
                $ledgerIds[]          = $entryId;
            }

            // 2. Produce finished goods
            $fgEntryId   = $this->produceFinishedGoods($plan, $companyId, $executionId);
            $ledgerIds[] = $fgEntryId;

            // 3. Record the manufacturing transaction
            $executedAt  = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            $durationMs  = (int) (microtime(true) * 1000) - $startMs;

            $transaction = new ManufacturingTransaction();
            $transaction->fill([
                'execution_id'         => $executionId,
                'plan_id'              => $plan->plan_id,
                'product_id'           => $plan->product_id,
                'warehouse_id'         => $plan->warehouse_id,
                'bom_id'               => $plan->recipe_id,
                'bom_version_number'   => $plan->bom_version_number,
                'recipe_snapshot_hash' => $plan->recipe_snapshot_hash,
                'qty_produced'         => $plan->qty_to_manufacture,
                'status'               => TransactionStatus::Completed->value,
                'executed_at'          => $executedAt,
                'duration_ms'          => $durationMs,
                'order_line_id'        => null, // RC-10: populated when Order integration is added
                'metadata'             => array_merge($plan->metadata, ['plan_id' => $plan->plan_id]),
            ]);

            $this->transactions->save($transaction);

            return new ManufacturingExecutionResult(
                execution_id:        $executionId,
                transaction_id:      $transaction->id,
                success:             true,
                was_idempotent:      false,
                qty_produced:        $plan->qty_to_manufacture,
                consumed_components: $consumptionRecords,
                ledger_entry_ids:    $ledgerIds,
                duration_ms:         $durationMs,
                executed_at:         $executedAt,
                metadata:            $plan->metadata,
            );
        });

        return $result;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * @throws ExecutionException  When the snapshot hash in the plan doesn't match the snapshot content.
     */
    private function verifySnapshotIntegrity(ManufacturingPlan $plan): void
    {
        try {
            $computed = hash('sha256', json_encode($plan->recipe_snapshot->toArray(), JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            $computed = '';
        }

        if ($computed !== $plan->recipe_snapshot_hash) {
            throw ExecutionException::snapshotMismatch($plan->plan_id, $plan->recipe_snapshot_hash, $computed);
        }
    }

    /**
     * Consumes one raw material component from inventory.
     *
     * - Finds (or creates) the InventoryItem for the component at the warehouse.
     * - Acquires a pessimistic write lock.
     * - Decrements on_hand_qty by qty_to_consume.
     * - Creates a StockLedgerEntry (ProductionConsumption).
     *
     * @return array{ComponentConsumptionRecord, string}  [record, ledger_entry_id]
     */
    private function consumeComponent(
        ComponentConsumptionPlan $component,
        ManufacturingPlan $plan,
        string $companyId,
        string $executionId,
    ): array {
        $item       = $this->inventoryItems->findOrCreate($plan->warehouse_id, $component->component_id, $companyId);
        $lockedItem = $this->inventoryItems->lockForUpdate($item->id);

        $onHandBefore = (float) $lockedItem->on_hand_qty;
        $onHandAfter  = $onHandBefore - $component->qty_to_consume;

        $lockedItem->on_hand_qty = $onHandAfter;
        $this->inventoryItems->save($lockedItem);

        $entry = $this->inventoryItems->recordEntry([
            'inventory_item_id' => $lockedItem->id,
            'warehouse_id'      => $plan->warehouse_id,
            'product_id'        => $component->component_id,
            'company_id'        => $companyId,
            'movement_type'     => LedgerMovementType::ProductionConsumption->value,
            'quantity'          => $component->qty_to_consume,
            'on_hand_before'    => $onHandBefore,
            'on_hand_after'     => $onHandAfter,
            'reserved_before'   => (float) $lockedItem->reserved_qty,
            'reserved_after'    => (float) $lockedItem->reserved_qty,
            'reference_type'    => 'manufacturing_plan',
            'reference_id'      => $plan->plan_id,
            'notes'             => "Consumed for manufacturing execution {$executionId}",
        ]);

        $record = new ComponentConsumptionRecord(
            component_id:   $component->component_id,
            sku:            $component->sku,
            name:           $component->name,
            unit_symbol:    $component->unit_symbol,
            qty_consumed:   $component->qty_to_consume,
            on_hand_before: $onHandBefore,
            on_hand_after:  $onHandAfter,
            went_negative:  $onHandAfter < 0.0,
            ledger_entry_id: $entry->id,
        );

        return [$record, $entry->id];
    }

    /**
     * Produces finished goods into inventory.
     *
     * - Finds (or creates) the InventoryItem for the finished goods product.
     * - Acquires a pessimistic write lock.
     * - Increments on_hand_qty by qty_to_manufacture.
     * - Creates a StockLedgerEntry (ProductionOutput).
     *
     * @return string  The ledger entry ID.
     */
    private function produceFinishedGoods(ManufacturingPlan $plan, string $companyId, string $executionId): string
    {
        $item       = $this->inventoryItems->findOrCreate($plan->warehouse_id, $plan->product_id, $companyId);
        $lockedItem = $this->inventoryItems->lockForUpdate($item->id);

        $onHandBefore = (float) $lockedItem->on_hand_qty;
        $onHandAfter  = $onHandBefore + $plan->qty_to_manufacture;

        $lockedItem->on_hand_qty = $onHandAfter;
        $this->inventoryItems->save($lockedItem);

        $entry = $this->inventoryItems->recordEntry([
            'inventory_item_id' => $lockedItem->id,
            'warehouse_id'      => $plan->warehouse_id,
            'product_id'        => $plan->product_id,
            'company_id'        => $companyId,
            'movement_type'     => LedgerMovementType::ProductionOutput->value,
            'quantity'          => $plan->qty_to_manufacture,
            'on_hand_before'    => $onHandBefore,
            'on_hand_after'     => $onHandAfter,
            'reserved_before'   => (float) $lockedItem->reserved_qty,
            'reserved_after'    => (float) $lockedItem->reserved_qty,
            'reference_type'    => 'manufacturing_plan',
            'reference_id'      => $plan->plan_id,
            'notes'             => "Finished goods produced by execution {$executionId}",
        ]);

        return $entry->id;
    }

    private function buildIdempotentResult(ManufacturingTransaction $transaction): ManufacturingExecutionResult
    {
        return new ManufacturingExecutionResult(
            execution_id:        $this->generateUuid(),
            transaction_id:      $transaction->id,
            success:             $transaction->status === TransactionStatus::Completed,
            was_idempotent:      true,
            qty_produced:        (float) $transaction->qty_produced,
            consumed_components: [],
            ledger_entry_ids:    [],
            duration_ms:         0,
            executed_at:         (string) $transaction->executed_at,
            metadata:            $transaction->metadata ?? [],
        );
    }

    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
