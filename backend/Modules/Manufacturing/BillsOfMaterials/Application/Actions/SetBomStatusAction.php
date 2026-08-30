<?php

declare(strict_types=1);

namespace Modules\Manufacturing\BillsOfMaterials\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\BillsOfMaterials\Domain\Contracts\BomRepositoryInterface;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\BillOfMaterial;

/**
 * Activate or deactivate a recipe, and nothing else.
 *
 * This exists because BUG-BOM-DATA-LOSS-001 was only possible while a status
 * change travelled through the full update contract: a caller that omitted
 * `lines` silently deleted every component. Here there is no payload that could
 * carry lines at all, so the failure mode is unreachable rather than merely
 * guarded against.
 *
 * Passing null for lines relies on the repository contract established in the
 * same fix: null means "leave the existing lines untouched".
 *
 * ACTIVATION IS APPROVAL
 * ----------------------
 * There is one recipe lifecycle in this platform, not two, so activation is also
 * the moment a recipe's component costs are frozen for disassembly valuation. That
 * makes activation fail-closed: a recipe whose components cannot all be priced does
 * NOT become active, because activating it would mean later disassemblies silently
 * valuing those components at zero.
 *
 * Deactivation never touches snapshots. Turning a recipe off says nothing about the
 * goods already built under it.
 */
final class SetBomStatusAction extends BaseAction
{
    public function __construct(
        private readonly BomRepositoryInterface $boms,
        private readonly CreateRecipeCostSnapshotAction $snapshots,
    ) {}

    /**
     * @param  mixed  ...$arguments  Expects (BillOfMaterial $bom, bool $isActive, ?string $approvedBy).
     */
    public function execute(mixed ...$arguments): mixed
    {
        /** @var BillOfMaterial $bom */
        $bom = $arguments[0];
        $isActive = (bool) $arguments[1];
        $approvedBy = isset($arguments[2]) ? (string) $arguments[2] : null;

        // One transaction covers both the status write and the snapshot. If the
        // recipe cannot be costed, CreateRecipeCostSnapshotAction throws and the
        // activation rolls back with it — there is no window in which a recipe is
        // active but unvalued.
        $updated = DB::transaction(function () use ($bom, $isActive, $approvedBy): BillOfMaterial {
            // product_id is required by the repository so it can deactivate the
            // product's other recipes when this one becomes active. It is read from
            // the record, never from the request.
            $payload = ['is_active' => $isActive, 'product_id' => (string) $bom->product_id];

            if ($isActive) {
                $payload['approved_at'] = now();
                $payload['approved_by'] = $approvedBy;
            }

            /** @var BillOfMaterial $result */
            $result = $this->boms->update($bom, $payload, null);

            if ($isActive) {
                $this->snapshots->execute($result, $approvedBy);
            }

            return $result;
        });

        return OperationResult::success(
            $updated,
            $isActive ? 'Recipe activated.' : 'Recipe deactivated.',
        );
    }
}
