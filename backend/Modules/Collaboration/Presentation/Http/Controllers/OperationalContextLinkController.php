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
use Modules\Collaboration\Presentation\Http\Requests\AttachOperationalContextRequest;

/**
 * Foundation-proving endpoint only (architecture report §13) — full V1
 * behaviour per context type belongs to Task 4/5.
 */
final class OperationalContextLinkController extends Controller
{
    use HasApiResponse;

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

        return $this->created([
            'id' => $link->id,
            'context_type' => $link->context_type->value,
            'context_id' => $link->context_id,
            'attached_to_type' => $link->attached_to_type->value,
            'attached_to_id' => $link->attached_to_id,
        ]);
    }
}
