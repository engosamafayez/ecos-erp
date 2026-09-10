<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Presentation\Http\Controllers;

use App\Core\Company\CurrentCompanyService;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Admin\GoLive\Application\Actions\EstablishOpeningInventoryAction;

final class OpeningInventoryController extends Controller
{
    use HasApiResponse;

    public function store(Request $request, EstablishOpeningInventoryAction $action, CurrentCompanyService $currentCompany): JsonResponse
    {
        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'lines.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'gte:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $companyId = $currentCompany->id();
        if ($companyId === null) {
            abort(422, 'No active company context.');
        }

        $results = $action->execute($companyId, $validated['lines'], Auth::id() !== null ? (string) Auth::id() : null);

        return $this->success(['lines' => $results]);
    }
}
