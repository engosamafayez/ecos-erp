<?php

declare(strict_types=1);

namespace Modules\Finance\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Modules\Finance\Expenses\Domain\Models\Expense;
use Modules\Finance\Expenses\Domain\Models\ExpenseCategory;
use Modules\Finance\Expenses\Domain\Services\ExpenseService;
use Modules\Finance\Presentation\Http\Controllers\Concerns\ResolvesFinanceContext;
use Modules\Finance\Shared\Domain\Services\CommandIdempotencyGuard;

/**
 * Finance expenses (TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007):
 * create (maker) → approve (checker) → post. No other domain owns
 * operational expense capture today, so this is the canonical, complete
 * path — mirroring SupplierPaymentController's exact shape.
 */
class ExpenseController extends Controller
{
    use ResolvesFinanceContext;

    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly CommandIdempotencyGuard $idempotency,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $expenses = Expense::query()
            ->where('company_id', $this->companyId($request))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Expense $e) => $this->payload($e));

        return response()->json(['data' => $expenses]);
    }

    /**
     * Maker: create a draft expense. An `Idempotency-Key` header is honoured
     * when present — the same replay/conflict contract as every other F3
     * creation endpoint (SupplierPaymentController::store(),
     * CustomerReceiptController::store()).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'expense_category_id' => ['required', 'string'], // category uuid
            'number' => ['required', 'string', 'max:60'],
            'expense_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'funding_account_id' => ['required', 'string'], // account uuid
            'currency' => ['nullable', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'string'],
            'cost_center_id' => ['nullable', 'integer'],
            'profit_center_id' => ['nullable', 'string'],
        ]);

        $companyId = $this->companyId($request);

        $result = $this->idempotency->execute(
            companyId: $companyId,
            commandType: 'finance.expense.create',
            idempotencyKey: $request->header('Idempotency-Key'),
            payload: $validated,
            command: fn () => $this->expenses->createExpense(
                companyId: $companyId,
                expenseCategoryId: $this->categoryId($companyId, $validated['expense_category_id']),
                number: $validated['number'],
                expenseDate: Carbon::parse($validated['expense_date']),
                amount: (float) $validated['amount'],
                fundingAccountId: $this->accountId($request, $validated['funding_account_id']),
                currency: $validated['currency'] ?? 'EGP',
                description: $validated['description'] ?? null,
                createdBy: $this->actorId($request),
                branchId: $validated['branch_id'] ?? null,
                costCenterId: isset($validated['cost_center_id']) ? (int) $validated['cost_center_id'] : null,
                profitCenterId: $validated['profit_center_id'] ?? null,
            ),
            actorId: $this->actorId($request),
        );

        /** @var Expense $expense */
        $expense = $result->result;

        return response()
            ->json(['data' => $this->payload($expense)], $result->wasReplayed ? 200 : 201)
            ->header('Idempotent-Replay', $result->wasReplayed ? 'true' : 'false');
    }

    /** Checker: approve a draft expense (must differ from the maker). */
    public function approve(Request $request, string $uuid): JsonResponse
    {
        $expense = $this->expenses->approveExpense($this->find($request, $uuid), (int) $this->actorId($request));

        return response()->json(['data' => $this->payload($expense)]);
    }

    public function post(Request $request, string $uuid): JsonResponse
    {
        $expense = $this->expenses->postExpense($this->find($request, $uuid), $this->actorId($request));

        return response()->json(['data' => $this->payload($expense)]);
    }

    /** Reverse a posted expense's journal — the correction path. */
    public function reversePosting(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $expense = $this->find($request, $uuid);
        $reversalJournal = $this->expenses->reverseExpensePosting($expense, $validated['reason'], $this->actorId($request));

        return response()->json(['data' => [
            'reversal_journal_id' => $reversalJournal->uuid,
            'reverses_journal_id' => $reversalJournal->reverses_journal_id,
            'expense_id' => $expense->uuid,
        ]], 201);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function find(Request $request, string $uuid): Expense
    {
        return Expense::query()
            ->where('company_id', $this->companyId($request))
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function categoryId(string $companyId, string $uuid): int
    {
        return (int) ExpenseCategory::query()
            ->where('company_id', $companyId)
            ->where('uuid', $uuid)
            ->firstOrFail()
            ->id;
    }

    /** @return array<string, mixed> */
    private function payload(Expense $e): array
    {
        return [
            'id' => $e->uuid,
            'expense_category_id' => $e->category?->uuid,
            'number' => $e->number,
            'expense_date' => $e->expense_date?->toDateString(),
            'amount' => (float) $e->amount,
            'currency' => $e->currency,
            'status' => $e->status->value,
            'source_type' => $e->source_type,
            'source_id' => $e->source_id,
            'journal_entry_id' => $e->journal_entry_id,
            'approved_by' => $e->approved_by,
            'approved_at' => $e->approved_at?->toIso8601String(),
            'posted_at' => $e->posted_at?->toIso8601String(),
        ];
    }
}
