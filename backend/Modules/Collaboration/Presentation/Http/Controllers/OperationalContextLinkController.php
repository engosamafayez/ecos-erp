<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collaboration\Application\Actions\AttachOperationalContextAction;
use Modules\Collaboration\Domain\Enums\AttachedToType;
use Modules\Collaboration\Domain\Enums\OperationalContextType;
use Modules\Collaboration\Domain\Exceptions\CollaborationException;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Models\OperationalContextLink;
use Modules\Collaboration\Presentation\Http\Requests\AttachOperationalContextRequest;

/**
 * Foundation-proving endpoints (architecture report §13, wired for Task 4's
 * approved context types — Order, Distribution Group, Trip, Driver). The
 * response is always the bare reference — type + id — never any
 * denormalized data from the linked entity itself (brief §21): Collaboration
 * has no code path that fetches Order/Trip/Distribution Group/Driver data,
 * so there is structurally nothing here to leak.
 */
final class OperationalContextLinkController extends Controller
{
    use HasApiResponse;

    public function indexForTask(Request $request, InternalTask $task): JsonResponse
    {
        $this->authorize('view', $task);

        $links = OperationalContextLink::query()
            ->where('attached_to_type', AttachedToType::Task)
            ->where('attached_to_id', $task->id)
            ->get()
            ->map(fn (OperationalContextLink $link): array => $this->format($link))
            ->values();

        return $this->success($links);
    }

    public function store(AttachOperationalContextRequest $request, AttachOperationalContextAction $action): JsonResponse
    {
        try {
            $link = $action->execute(
                $request->user(),
                AttachedToType::from($request->validated('attached_to_type')),
                (string) $request->validated('attached_to_id'),
                OperationalContextType::from($request->validated('context_type')),
                (string) $request->validated('context_id'),
            );
        } catch (CollaborationException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->created($this->format($link));
    }

    /** @return array<string, mixed> */
    private function format(OperationalContextLink $link): array
    {
        return [
            'id' => $link->id,
            'context_type' => $link->context_type->value,
            'context_id' => $link->context_id,
            'attached_to_type' => $link->attached_to_type->value,
            'attached_to_id' => $link->attached_to_id,
        ];
    }
}
