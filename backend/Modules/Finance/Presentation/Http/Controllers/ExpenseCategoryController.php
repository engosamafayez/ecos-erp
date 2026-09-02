<?php

declare(strict_types=1);

namespace Modules\Finance\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Finance\Expenses\Domain\Models\ExpenseCategory;
use Modules\Finance\Presentation\Http\Controllers\Concerns\ResolvesFinanceContext;

/**
 * Expense Category management (TASK-ECOS-FINANCE-OPERATIONAL-COST-
 * ACCOUNTING-007) — the minimal, canonical mapping every expense posts
 * through. A category names an existing Chart-of-Accounts expense account
 * by role, not by hardcoded id; no account is created here.
 */
class ExpenseCategoryController extends Controller
{
    use ResolvesFinanceContext;

    public function index(Request $request): JsonResponse
    {
        $categories = ExpenseCategory::query()
            ->where('company_id', $this->companyId($request))
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (ExpenseCategory $c) => $this->payload($c));

        return response()->json(['data' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'expense_account_id' => ['required', 'string'], // account uuid
        ]);

        $category = ExpenseCategory::create([
            'company_id' => $this->companyId($request),
            'name' => $validated['name'],
            'expense_account_id' => $this->accountId($request, $validated['expense_account_id']),
        ]);

        return response()->json(['data' => $this->payload($category)], 201);
    }

    /** @return array<string, mixed> */
    private function payload(ExpenseCategory $c): array
    {
        return [
            'id' => $c->uuid,
            'name' => $c->name,
            'expense_account_id' => $c->expenseAccount?->uuid,
            'is_active' => $c->is_active,
        ];
    }
}
