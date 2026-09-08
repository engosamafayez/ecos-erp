<?php

declare(strict_types=1);

namespace Modules\Purchasing\PurchaseMaterials\Application\Actions;

use App\Core\Audit\AuditService;
use App\Core\Responses\OperationResult;
use App\Core\Timeline\TimelineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Purchasing\PurchaseMaterials\Domain\Exceptions\InvalidPurchaseMaterialStatusException;
use Modules\Purchasing\PurchaseMaterials\Domain\Exceptions\PurchaseMaterialNotFoundException;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterialLine;
use Modules\Purchasing\PurchaseMaterials\Domain\Services\PurchaseMaterialOwnershipService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SelectLineSupplierAction
{
    public function __construct(
        private readonly PurchaseMaterialOwnershipService $ownership,
        private readonly AdvancePurchaseMaterialWorkflowAction $advanceWorkflow,
        private readonly AuditService $audit,
        private readonly TimelineService $timeline,
    ) {}

    public function execute(
        string $materialId,
        string $lineId,
        string $supplierId,
        ?float $agreedPrice,
        ?float $agreedQty,
        ?int $leadTimeDays,
        Request $request,
    ): OperationResult {
        $line = DB::transaction(function () use (
            $materialId,
            $lineId,
            $supplierId,
            $agreedPrice,
            $agreedQty,
            $leadTimeDays,
            $request,
        ): PurchaseMaterialLine {
            $material = PurchaseMaterial::query()->lockForUpdate()->find($materialId);
            if ($material === null) {
                throw new PurchaseMaterialNotFoundException($materialId);
            }

            if (! $material->status->canSelectSupplier()) {
                throw new InvalidPurchaseMaterialStatusException(
                    $material->request_number,
                    $material->status->value,
                    ['waiting_supplier_selection', 'approved', 'purchasing'],
                );
            }

            $line = PurchaseMaterialLine::where('id', $lineId)
                ->where('purchase_material_id', $materialId)
                ->lockForUpdate()
                ->first();

            if ($line === null) {
                throw new NotFoundHttpException("Line {$lineId} not found on request {$materialId}.");
            }

            // TASK-...-011 §7: incremental/partial ordering. agreed_qty is the cumulative quantity
            // committed to suppliers so far — it may only grow (a later, smaller commitment is a
            // separate concern, e.g. a correction, not modeled here) and may never exceed what was
            // actually requested, so a line can never appear "over-ordered".
            $previousAgreedQty = $line->agreed_qty !== null ? round((float) $line->agreed_qty, 4) : 0.0;
            $requestedQty = round((float) $line->requested_qty, 4);
            $nextAgreedQty = $agreedQty !== null ? round($agreedQty, 4) : $previousAgreedQty;

            if ($nextAgreedQty < $previousAgreedQty) {
                throw ValidationException::withMessages([
                    'agreed_qty' => "Ordered quantity cannot decrease below the {$previousAgreedQty} already committed for this line.",
                ]);
            }

            if ($nextAgreedQty > $requestedQty) {
                throw ValidationException::withMessages([
                    'agreed_qty' => "Ordered quantity cannot exceed the requested quantity ({$requestedQty}).",
                ]);
            }

            $line->update([
                'supplier_id' => $supplierId,
                'agreed_price' => $agreedPrice,
                'agreed_qty' => $nextAgreedQty,
                'lead_time_days' => $leadTimeDays,
                'supplier_selected_at' => now(),
                'supplier_selected_by' => $request->user()?->id,
            ]);

            if ($request->user() !== null) {
                $this->ownership->claimIfUnowned($material, $request->user());
            }

            $this->timeline->record(
                companyId: (string) $material->company_id,
                subjectType: 'PurchaseMaterial',
                subjectId: (string) $material->id,
                eventType: 'purchase_material.line_committed',
                title: "Supplier commitment recorded for {$nextAgreedQty} unit(s)",
                actorId: $request->user()?->id !== null ? (int) $request->user()->id : null,
                actorName: $request->user()?->name,
                sourceModule: 'Purchasing.PurchaseMaterials',
                metadata: ['line_id' => $line->id, 'supplier_id' => $supplierId, 'agreed_qty' => $nextAgreedQty],
            );

            $this->audit->record(
                action: 'purchase_material.line_committed',
                entityType: 'PurchaseMaterialLine',
                entityId: (string) $line->id,
                companyId: $material->company_id,
                userId: $request->user()?->id !== null ? (int) $request->user()->id : null,
                oldValues: ['agreed_qty' => $previousAgreedQty],
                newValues: ['agreed_qty' => $nextAgreedQty, 'supplier_id' => $supplierId],
            );

            $this->advanceWorkflow->execute((string) $material->id);

            return $line;
        });

        return OperationResult::success($line->refresh(), 'Supplier selected for line.');
    }
}
