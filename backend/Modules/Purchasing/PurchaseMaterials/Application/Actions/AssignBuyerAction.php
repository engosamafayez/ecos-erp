<?php

declare(strict_types=1);

namespace Modules\Purchasing\PurchaseMaterials\Application\Actions;

use App\Core\Responses\OperationResult;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Purchasing\PurchaseMaterials\Domain\Contracts\PurchaseMaterialRepositoryInterface;
use Modules\Purchasing\PurchaseMaterials\Domain\Exceptions\PurchaseMaterialNotFoundException;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;
use Modules\Purchasing\PurchaseMaterials\Domain\Services\PurchaseMaterialOwnershipService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * TASK-...-011 §5. Explicit REASSIGN — distinct from the implicit CLAIM the other actions perform.
 * Always allowed regardless of current ownership; the route's `purchasing.materials.review`
 * permission (a manager-level permission, not held by the base purchasing-officer role) is the
 * only gate. Takes a canonical IAM user id, not free text — the prior `buyer_name` string had no
 * relation to `users` and could not be attributed, filtered, or notified.
 */
final class AssignBuyerAction
{
    public function __construct(
        private readonly PurchaseMaterialRepositoryInterface $repository,
        private readonly PurchaseMaterialOwnershipService $ownership,
    ) {}

    public function execute(string $id, int $buyerId, Request $request): OperationResult
    {
        $material = DB::transaction(function () use ($id, $buyerId, $request): PurchaseMaterial {
            $material = PurchaseMaterial::query()->lockForUpdate()->find($id);
            if ($material === null) {
                throw new PurchaseMaterialNotFoundException($id);
            }

            $newBuyer = User::query()->find($buyerId);
            if ($newBuyer === null) {
                throw new NotFoundHttpException("User {$buyerId} not found.");
            }

            if ((string) $newBuyer->company_id !== (string) $material->company_id) {
                throw ValidationException::withMessages([
                    'buyer_id' => 'The selected user does not belong to this request\'s company.',
                ]);
            }

            if (! $newBuyer->isActive()) {
                throw ValidationException::withMessages([
                    'buyer_id' => 'The selected user is not active.',
                ]);
            }

            $actor = $request->user();
            $this->ownership->reassign($material, $newBuyer, $actor ?? $newBuyer);

            return $material;
        });

        return OperationResult::success(
            $this->repository->findById((string) $material->id) ?? $material->refresh(),
            'Buyer assigned successfully.',
        );
    }
}
