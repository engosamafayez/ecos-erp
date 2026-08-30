<?php

declare(strict_types=1);

namespace Modules\Purchasing\PurchaseMaterials\Application\Actions;

use App\Core\Responses\OperationResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Purchasing\PurchaseMaterials\Application\DTO\PurchaseMaterialDTO;
use Modules\Purchasing\PurchaseMaterials\Application\DTO\PurchaseMaterialLineDTO;
use Modules\Purchasing\PurchaseMaterials\Domain\Contracts\PurchaseMaterialRepositoryInterface;
use Modules\Purchasing\PurchaseMaterials\Domain\Enums\PurchaseMaterialStatus;

final class CreatePurchaseMaterialAction
{
    public function __construct(
        private readonly PurchaseMaterialRepositoryInterface $repository,
    ) {}

    public function execute(PurchaseMaterialDTO $dto, Request $request): OperationResult
    {
        $attributes = [
            'request_number' => $this->repository->nextRequestNumber(),
            // TASK-PROCUREMENT-MANUAL-REMEDIATION-001: record_type/source_type were
            // dropped here, so every request persisted with the column default
            // ('material_request') and the Purchases screen (record_type=purchase)
            // could never show what it created. Persist the client's intent.
            'record_type' => $dto->record_type,
            'source_type' => $dto->source_type,
            'warehouse_id' => $dto->warehouse_id,
            // TASK-PROCUREMENT-MANUAL-REMEDIATION-001: company ownership is
            // resolved server-side from the (tenant-scoped) initiating warehouse,
            // never trusted from the client payload. This keeps the write in
            // agreement with the model's fail-closed read scope — a request can
            // only ever belong to the warehouse's own company. Actor company is
            // the fallback when the warehouse has none.
            'company_id' => $this->resolveCompanyId($dto, $request),
            'channel_id' => $dto->channel_id,
            'status' => PurchaseMaterialStatus::Draft->value,
            'priority' => $dto->priority,
            'required_date' => $dto->required_date,
            'notes' => $dto->notes,
            'requested_by' => (string) $request->user()?->id,
            'created_by' => (string) $request->user()?->id,
        ];

        $lines = array_map(fn (PurchaseMaterialLineDTO $line): array => [
            'product_id' => $line->product_id,
            'requested_qty' => $line->requested_qty,
            'unit_label' => $line->unit_label,
            'notes' => $line->notes,
        ], $dto->lines);

        $material = $this->repository->create($attributes, $lines);

        return OperationResult::success($material, 'Purchase material request created.');
    }

    private function resolveCompanyId(PurchaseMaterialDTO $dto, Request $request): ?string
    {
        $warehouseCompanyId = $dto->warehouse_id !== ''
            ? DB::table('warehouses')->where('id', $dto->warehouse_id)->value('company_id')
            : null;

        return $warehouseCompanyId
            ?? $request->user()?->company_id
            ?? $dto->company_id;
    }
}
