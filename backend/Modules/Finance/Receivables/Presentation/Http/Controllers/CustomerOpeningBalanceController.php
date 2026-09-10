<?php

declare(strict_types=1);

namespace Modules\Finance\Receivables\Presentation\Http\Controllers;

use App\Core\Company\TenantOwnershipResolver;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Finance\Receivables\Domain\Services\CustomerOpeningBalanceService;
use Modules\Sales\Customers\Domain\Models\Customer;
use RuntimeException;

/**
 * TASK-...-026 §10/§18 — mirrors SupplierOpeningBalanceController's shape, but with one
 * deliberate addition: `Modules\Sales\Customers\Domain\Models\Customer` (confirmed by direct
 * read) carries NO tenant global scope, unlike `Supplier` — so `findOrFail` alone would NOT fail
 * closed on a cross-company id. This controller checks `owns()` explicitly instead of assuming a
 * scope that does not exist, rather than silently inheriting Supplier's stronger guarantee.
 */
final class CustomerOpeningBalanceController extends Controller
{
    use HasApiResponse;

    public function __construct(private readonly CustomerOpeningBalanceService $opening) {}

    public function store(Request $request, string $customer, TenantOwnershipResolver $tenant): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'opening_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $model = Customer::query()->findOrFail($customer);

        if (! $tenant->owns((string) $model->company_id)) {
            abort(404);
        }

        $companyId = (string) $model->company_id;
        $actorId = Auth::id() !== null ? (int) Auth::id() : null;

        try {
            $entry = $this->opening->postOpeningReceivable(
                $companyId,
                (string) $model->id,
                (string) ($model->code ?? $model->id),
                (float) $data['amount'],
                Carbon::parse($data['opening_date']),
                $data['reference'] ?? null,
                $data['notes'] ?? null,
                $actorId,
            );
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return $this->success([
            'entry_id' => $entry->id,
            'journal_entry_id' => $entry->journal_entry_id,
        ]);
    }
}
