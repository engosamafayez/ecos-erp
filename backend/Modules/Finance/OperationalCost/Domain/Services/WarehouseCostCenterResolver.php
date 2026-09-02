<?php

declare(strict_types=1);

namespace Modules\Finance\OperationalCost\Domain\Services;

use Modules\Finance\Ledger\Domain\Models\CostCenter;

/**
 * FIN-EXEC-04 (TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007) — the
 * bounded, unconditional half of Task 5's Warehouse→Brand contract: "seed/
 * allow warehouses as typed cost centers." Resolves (get-or-create) the one
 * cost center representing a given warehouse, keyed by the generic source_
 * type/source_id reference pair (this task's addition to
 * finance_cost_centers) rather than a fragile code/name match — idempotent,
 * safe to call repeatedly. warehouse_id/warehouse_name are opaque inputs;
 * Finance does not become a warehouse master-data owner (TASK §8).
 */
final class WarehouseCostCenterResolver
{
    private const SOURCE_TYPE = 'warehouse';

    public function resolve(string $companyId, string $warehouseId, string $warehouseName, ?int $createdBy = null): CostCenter
    {
        $existing = CostCenter::query()
            ->where('company_id', $companyId)
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $warehouseId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return CostCenter::create([
            'company_id' => $companyId,
            'code' => 'WH-'.substr(str_replace('-', '', $warehouseId), 0, 12),
            'name' => $warehouseName,
            'source_type' => self::SOURCE_TYPE,
            'source_id' => $warehouseId,
            'created_by' => $createdBy,
        ]);
    }
}
