<?php

declare(strict_types=1);

namespace Modules\Purchasing\PurchaseMaterials\Domain\Services;

use App\Core\Audit\AuditService;
use App\Core\Timeline\TimelineService;
use App\Models\User;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;

/**
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-AND-HUB-FINAL-REMEDIATION-011 §5.
 *
 * Ownership no longer needs a manual "assign a buyer" step before any purchasing work can start.
 * The first authorized purchasing user to act on an unowned request (approve / reject / hold /
 * cancel / commit a supplier) becomes its buyer automatically. This is CLAIM, not REASSIGN:
 *
 *   CLAIM     — implicit, bundled into whatever action-specific permission already gates the
 *               action being performed (approve/reject/hold/cancel/select_supplier). No second
 *               "may I claim" permission exists, and none is needed — a user who is not allowed
 *               to approve a request is not allowed to claim it by approving it either, because
 *               the route middleware rejects the request before this service ever runs.
 *   REASSIGN  — explicit, always allowed regardless of current ownership, gated by the stronger
 *               `purchasing.materials.review` permission the assign-buyer route already carries.
 *
 * The caller is responsible for holding a row lock on $material (SELECT ... FOR UPDATE) before
 * calling claimIfUnowned — concurrency safety is the caller's transaction, not this service's.
 */
final class PurchaseMaterialOwnershipService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TimelineService $timeline,
    ) {}

    /**
     * Claim the request for $actor if — and only if — nobody owns it yet. A no-op, not an error,
     * when the request is already owned (by this actor or anyone else): claiming is a side effect
     * of doing the work, never a contested action in its own right.
     */
    public function claimIfUnowned(PurchaseMaterial $material, User $actor): void
    {
        if ($material->assigned_buyer_id !== null) {
            return;
        }

        $material->assigned_buyer_id = $actor->id;
        $material->assigned_buyer = $actor->name;
        $material->save();

        $this->timeline->record(
            companyId: (string) $material->company_id,
            subjectType: 'PurchaseMaterial',
            subjectId: (string) $material->id,
            eventType: 'purchase_material.claimed',
            title: "{$actor->name} claimed this request",
            actorId: (int) $actor->id,
            actorName: $actor->name,
            sourceModule: 'Purchasing.PurchaseMaterials',
            metadata: ['buyer_id' => $actor->id],
        );

        $this->audit->record(
            action: 'purchase_material.claimed',
            entityType: 'PurchaseMaterial',
            entityId: (string) $material->id,
            companyId: $material->company_id,
            userId: (int) $actor->id,
            newValues: ['assigned_buyer_id' => $actor->id, 'assigned_buyer' => $actor->name],
        );
    }

    /** Explicit reassignment — always allowed; the route's own permission is the only gate. */
    public function reassign(PurchaseMaterial $material, User $newBuyer, User $actor): void
    {
        $previousId = $material->assigned_buyer_id;
        $previousName = $material->assigned_buyer;

        $material->assigned_buyer_id = $newBuyer->id;
        $material->assigned_buyer = $newBuyer->name;
        $material->save();

        $this->timeline->record(
            companyId: (string) $material->company_id,
            subjectType: 'PurchaseMaterial',
            subjectId: (string) $material->id,
            eventType: 'purchase_material.reassigned',
            title: "{$actor->name} reassigned this request to {$newBuyer->name}",
            actorId: (int) $actor->id,
            actorName: $actor->name,
            sourceModule: 'Purchasing.PurchaseMaterials',
            metadata: ['previous_buyer_id' => $previousId, 'new_buyer_id' => $newBuyer->id],
        );

        $this->audit->record(
            action: 'purchase_material.reassigned',
            entityType: 'PurchaseMaterial',
            entityId: (string) $material->id,
            companyId: $material->company_id,
            userId: (int) $actor->id,
            oldValues: ['assigned_buyer_id' => $previousId, 'assigned_buyer' => $previousName],
            newValues: ['assigned_buyer_id' => $newBuyer->id, 'assigned_buyer' => $newBuyer->name],
        );
    }
}
