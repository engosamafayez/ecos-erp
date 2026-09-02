<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Purchasing\Suppliers\Application\DTO\SupplierDTO;
use Modules\Purchasing\Suppliers\Domain\Contracts\SupplierRepositoryInterface;
use Modules\Purchasing\Suppliers\Domain\Services\SupplierCapabilitySyncService;
use Modules\Purchasing\Suppliers\Domain\Services\SupplierCodeSequenceService;

/**
 * Creates a new supplier, auto-generating its code if none was supplied, and
 * syncing its declared Supply Capabilities (Raw Materials / Product
 * Categories) — all in one transaction. The code sequence's own internal
 * `DB::transaction()` (SupplierCodeSequenceService::next()) nests safely via
 * Laravel's automatic savepoints; its concurrency guarantees are unaffected
 * (TASK-...-SUPPLY-CAPABILITIES-003 §20 — proven in the Engineering Report).
 */
final class CreateSupplierAction extends BaseAction
{
    public function __construct(
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly SupplierCodeSequenceService $codeGenerator,
        private readonly SupplierCapabilitySyncService $capabilities,
    ) {}

    /**
     * @param  mixed  ...$arguments  Expects a single {@see SupplierDTO}.
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $dto = $arguments[0] ?? null;

        if (! $dto instanceof SupplierDTO) {
            throw new InvalidArgumentException('CreateSupplierAction::execute expects a SupplierDTO.');
        }

        $supplier = DB::transaction(function () use ($dto) {
            $attributes = $dto->toArray();
            unset($attributes['raw_material_ids'], $attributes['product_category_ids']);

            $companyId = $attributes['company_id'] ?? Auth::user()?->company_id;
            $attributes['company_id'] = $companyId;
            $attributes['code'] = $dto->code ?? $this->codeGenerator->next((string) $companyId);

            $supplier = $this->suppliers->create($attributes);

            $this->capabilities->sync($supplier, $dto->raw_material_ids, $dto->product_category_ids, Auth::id());

            return $supplier;
        });

        return OperationResult::success($supplier, 'Supplier created successfully.');
    }
}
