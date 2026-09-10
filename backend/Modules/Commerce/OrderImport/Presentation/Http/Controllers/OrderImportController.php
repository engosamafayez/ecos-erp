<?php

declare(strict_types=1);

namespace Modules\Commerce\OrderImport\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Commerce\OrderImport\Application\Actions\ImportOrdersAction;

final class OrderImportController extends Controller
{
    use HasApiResponse;

    /**
     * TASK-...-025 (P4/P5) — `mode=historical` is the explicit, deliberately-invoked historical
     * import path (W3); it is NEVER the default. Omitting `mode` (or `mode=live`) is the existing
     * live/catch-up behaviour, unchanged for every caller that doesn't know about this yet.
     */
    public function importOrders(Request $request, string $channel, ImportOrdersAction $action): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['nullable', 'string', 'in:live,historical'],
            'after' => ['nullable', 'date'],
            'batch_id' => ['nullable', 'uuid'],
        ]);

        $result = $action->execute($channel, [
            'historical' => ($validated['mode'] ?? 'live') === 'historical',
            'after' => $validated['after'] ?? null,
            'batch_id' => $validated['batch_id'] ?? null,
        ]);

        return $this->success($result->data(), $result->message());
    }
}
