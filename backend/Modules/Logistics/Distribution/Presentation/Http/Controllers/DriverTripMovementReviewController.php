<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Logistics\Distribution\Application\Actions\ReviewDriverTripMovementAction;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementCategory;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementDirection;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementStatus;
use Modules\Logistics\Distribution\Domain\Models\DriverTripMovement;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * OPERATIONS review of driver trip movements — TASK-OPERATIONS-DRIVER-TRIP-MOVEMENT-APPROVAL-001 §7–§10.
 *
 * The Operations-side Approve / Reject of a driver's Pending cash movement, plus secure evidence
 * retrieval. Approve/Reject are gated by `logistics.distribution.update` (an Operations write
 * permission the Driver role does NOT hold — §20); the receipt view by `logistics.distribution.view`.
 * All decisions delegate to the canonical {@see ReviewDriverTripMovementAction} — this controller
 * never mutates status directly (§9). Tenancy is fail-closed to the acting user's company. NOT
 * Finance: no posting of any kind (§25).
 */
final class DriverTripMovementReviewController extends Controller
{
    public function __construct(
        private readonly ReviewDriverTripMovementAction $review,
    ) {}

    /** PATCH .../driver-movements/{movementId}/approve */
    public function approve(Request $request, string $movementId): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $movement = $this->owned($movementId);

        try {
            $updated = $this->review->approve($movement, (string) $request->user()->id, $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->payload($updated)]);
    }

    /** PATCH .../driver-movements/{movementId}/reject */
    public function reject(Request $request, string $movementId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $movement = $this->owned($movementId);

        try {
            $updated = $this->review->reject($movement, (string) $request->user()->id, $data['reason']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->payload($updated)]);
    }

    /** GET .../driver-movements/{movementId}/receipt — company-scoped secure download (§8). */
    public function receipt(string $movementId): StreamedResponse
    {
        $movement = $this->owned($movementId);
        abort_unless($movement->hasReceipt(), 404, 'This movement has no receipt.');

        // Only the server-recorded path is ever used; the client cannot name a storage path.
        return Storage::disk($movement->storage_disk ?? 'local')->download((string) $movement->receipt_path);
    }

    // ── Internals (fail-closed tenancy) ──────────────────────────────────────────

    private function owned(string $movementId): DriverTripMovement
    {
        $movement = DriverTripMovement::query()
            ->where('id', $movementId)
            ->where('company_id', $this->companyId())
            ->first();

        abort_if($movement === null, 404, 'Driver movement not found.');

        return $movement;
    }

    private function companyId(): string
    {
        $companyId = request()->user()?->company_id;
        if ($companyId === null || $companyId === '') {
            abort(403, 'No company scope for the acting user.');
        }

        return (string) $companyId;
    }

    /** @return array<string, mixed> */
    private function payload(DriverTripMovement $m): array
    {
        $category = $m->category instanceof DriverTripMovementCategory ? $m->category : DriverTripMovementCategory::from((string) $m->category);
        $direction = $m->direction instanceof DriverTripMovementDirection ? $m->direction : DriverTripMovementDirection::from((string) $m->direction);
        $status = $m->status instanceof DriverTripMovementStatus ? $m->status : DriverTripMovementStatus::from((string) $m->status);

        return [
            'id' => $m->id,
            'category' => $category->value,
            'direction' => $direction->value,
            'is_expense' => $category->isExpense(),
            'amount' => (float) $m->amount,
            'note' => $m->note,
            'status' => $status->value,
            'occurred_at' => optional($m->occurred_at)->toIso8601String(),
            'has_receipt' => $m->hasReceipt(),
            'reviewed_by' => $m->reviewed_by,
            'reviewed_at' => optional($m->reviewed_at)->toIso8601String(),
            'review_note' => $m->review_note,
        ];
    }
}
