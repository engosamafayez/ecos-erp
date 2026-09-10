<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Commerce\Channels\Presentation\Http\Resources\ChannelResource;
use Modules\Commerce\OrderImport\Application\Actions\SetInitialOrdersImportPolicyAction;
use Modules\Commerce\Synchronization\Application\Actions\SetOrdersSyncStateAction;

/**
 * TASK-...-025 (P1/P3/P4) — thin HTTP wrapper. All decisions live in the two Actions; this
 * controller only validates the request shape and adapts it to their array-input contract.
 */
final class OrdersSyncControlController extends Controller
{
    use HasApiResponse;

    public function setState(Request $request, string $channel, SetOrdersSyncStateAction $action): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['required', 'string', 'in:paused,enabled'],
            'resume_policy' => ['nullable', 'string', 'in:catch_up,resume_from_now,resume_from_point'],
            'resume_from' => ['nullable', 'date'],
        ]);

        $result = $action->execute($channel, $validated);

        return $this->success(new ChannelResource($result->data()), $result->message());
    }

    public function setInitialImportPolicy(Request $request, string $channel, SetInitialOrdersImportPolicyAction $action): JsonResponse
    {
        $validated = $request->validate([
            'policy' => ['required', 'string', 'in:from_now,from_date,last_n_days,historical'],
            'date' => ['nullable', 'date'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $result = $action->execute($channel, $validated);

        return $this->success(new ChannelResource($result->data()), $result->message());
    }
}
