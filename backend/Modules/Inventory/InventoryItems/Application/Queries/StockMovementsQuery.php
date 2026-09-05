<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Inventory\InventoryItems\Domain\Models\StockLedgerEntry;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Reporting\Application\Support\ReportDateRange;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-INV-03 · Stock Movements (ENTERPRISE-REPORTING-PLATFORM.md §18).
 *
 * Source is `StockLedgerEntry` (`Modules\Inventory\InventoryItems\Domain\Models\
 * StockLedgerEntry`, table `stock_ledger_entries`) — confirmed by direct inspection to be
 * the canonical, immutable, append-only ledger, NEVER the legacy `stock_movements` table
 * (a separate model, `StockMovement`, in a different module — the doc's own explicit
 * warning). Catalogue correction: Task 2's own `source_modules` entry for this report named
 * `Modules\Inventory\StockLedger`, but the real class lives in
 * `Modules\Inventory\InventoryItems` — fixed as part of this task (§24: "metric mapping
 * errors", a data-entry correction, not a semantic change).
 *
 * `StockLedgerEntry` carries its own `company_id` but no global scope — filtered explicitly,
 * same convention as every other manually-scoped model this workstream has used.
 */
final class StockMovementsQuery implements ReportHandlerInterface
{
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 200;

    public function reportId(): string
    {
        return 'RPT-INV-03';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'warehouse_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'movement_type' => ['nullable', Rule::in(array_map(static fn (LedgerMovementType $t): string => $t->value, LedgerMovementType::cases()))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'product_id' => $validated['product_id'] ?? null,
            'movement_type' => $validated['movement_type'] ?? null,
            'per_page' => (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
            'page' => (int) ($validated['page'] ?? 1),
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $range = new ReportDateRange($filters['date_from'], $filters['date_to']);

        $base = $range->applyToTimestampColumn(
            StockLedgerEntry::query()
                ->where('company_id', $context->companyId)
                ->when($filters['warehouse_id'] !== null, fn ($q) => $q->where('warehouse_id', $filters['warehouse_id']))
                ->when($filters['product_id'] !== null, fn ($q) => $q->where('product_id', $filters['product_id']))
                ->when($filters['movement_type'] !== null, fn ($q) => $q->where('movement_type', $filters['movement_type'])),
            'created_at',
        );

        $entryCount = (clone $base)->count();

        $entries = (clone $base)
            ->orderByDesc('created_at')
            ->forPage($filters['page'], $filters['per_page'])
            ->get();

        $productIds = $entries->pluck('product_id')->unique()->values()->all();
        $productNames = $productIds === [] ? collect() : Product::query()->whereIn('id', $productIds)->pluck('name', 'id');

        $rows = $entries->map(static fn (StockLedgerEntry $entry): array => [
            'id' => $entry->id,
            'product_id' => $entry->product_id,
            'product_name' => $productNames[$entry->product_id] ?? null,
            'warehouse_id' => $entry->warehouse_id,
            'movement_type' => $entry->movement_type->value,
            'quantity' => (float) $entry->quantity,
            'on_hand_before' => (float) $entry->on_hand_before,
            'on_hand_after' => (float) $entry->on_hand_after,
            'reference_type' => $entry->reference_type,
            'reference_id' => $entry->reference_id,
            'created_at' => $entry->created_at->toAtomString(),
        ])->values()->all();

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [],
            rows: $rows,
            totals: [
                'entry_count' => $entryCount,
                'page' => $filters['page'],
                'per_page' => $filters['per_page'],
            ],
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
