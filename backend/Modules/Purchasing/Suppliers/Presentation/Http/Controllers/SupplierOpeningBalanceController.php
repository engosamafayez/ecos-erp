<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Finance\Payables\Domain\Services\SupplierLedgerService;
use Modules\Finance\Payables\Domain\Services\SupplierOpeningBalanceService;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use RuntimeException;

/**
 * TASK-PROC-SUPPLIER-OPENING-BALANCE-001 — the Supplier 360 "Post Opening Balance" entry point.
 *
 * Draft→Post: the operator submits {type, amount, date, reference, notes}; posting delegates to
 * the Finance SupplierOpeningBalanceService (a real JournalType::Opening posting, ledger-derived,
 * idempotent). No Purchase/PO/GR/Inventory is created. The financial summary is read from the
 * supplier ledger (the SSOT), distinguishing Outstanding Payable from Available Advance.
 *
 * The supplier is resolved through the model's tenant global scope, so a cross-company supplier
 * simply 404s (fail closed). Posting is gated by the dedicated finance.ap.opening.post permission.
 */
final class SupplierOpeningBalanceController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly SupplierOpeningBalanceService $opening,
        private readonly SupplierLedgerService $ledger,
    ) {}

    public function store(Request $request, string $supplier): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:payable,advance'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'opening_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Tenant-scoped: the Supplier global scope 404s a cross-company id (fail closed).
        $model = Supplier::query()->findOrFail($supplier);
        $companyId = (string) $model->company_id;
        $actorId = Auth::id() !== null ? (int) Auth::id() : null;
        $date = Carbon::parse($data['opening_date']);

        try {
            $entry = $data['type'] === 'payable'
                ? $this->opening->postOpeningPayable(
                    $companyId, (string) $model->id, (string) $model->code, (float) $data['amount'],
                    $date, $data['reference'] ?? null, $data['notes'] ?? null, $actorId,
                )
                : $this->opening->postOpeningAdvance(
                    $companyId, (string) $model->id, (string) $model->code, (float) $data['amount'],
                    $date, $data['reference'] ?? null, $data['notes'] ?? null, $actorId,
                );
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return $this->success([
            'entry_id' => $entry->id,
            'entry_type' => $entry->entry_type->value,
            'journal_entry_id' => $entry->journal_entry_id,
            'summary' => $this->summaryFor($companyId, (string) $model->id),
        ]);
    }

    public function summary(string $supplier): JsonResponse
    {
        $model = Supplier::query()->findOrFail($supplier);

        return $this->success($this->summaryFor((string) $model->company_id, (string) $model->id));
    }

    /** @return array<string, float> */
    private function summaryFor(string $companyId, string $supplierId): array
    {
        return [
            'outstanding_payable' => $this->ledger->outstandingPayable($companyId, $supplierId),
            'available_advance' => $this->ledger->availableAdvance($companyId, $supplierId),
            'net_balance' => $this->ledger->balance($companyId, $supplierId),
        ];
    }
}
