<?php

declare(strict_types=1);

namespace Modules\Finance\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Finance\CostAllocation\Domain\Enums\CostAllocationMethod;
use Modules\Finance\CostAllocation\Domain\Models\CostAllocation;
use Modules\Finance\CostAllocation\Domain\Services\CostAllocationService;
use Modules\Finance\Expenses\Domain\Models\Expense;
use Modules\Finance\Presentation\Http\Controllers\Concerns\ResolvesFinanceContext;

/**
 * Cost Allocation (TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007,
 * FIN-EXEC-06) — a management-dimension redistribution of an already-posted
 * expense's cost across brands. Does not touch the GL (see
 * CostAllocationService's own docblock for why).
 */
class CostAllocationController extends Controller
{
    use ResolvesFinanceContext;

    public function __construct(private readonly CostAllocationService $allocations) {}

    public function index(Request $request): JsonResponse
    {
        $rows = CostAllocation::query()
            ->where('company_id', $this->companyId($request))
            ->when($request->filled('source_id'), fn ($q) => $q->where('source_id', $request->string('source_id')))
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (CostAllocation $a) => $this->payload($a));

        return response()->json(['data' => $rows]);
    }

    /**
     * Allocate a posted expense across one or more brands (destination
     * profit centers), by fixed amount or percentage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'expense_id' => ['required', 'string'], // expense uuid
            'method' => ['required', Rule::in(['fixed', 'percentage'])],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.profit_center_id' => ['required', 'string'],
            'destinations.*.amount' => ['nullable', 'numeric', 'gt:0'],
            'destinations.*.percentage' => ['nullable', 'numeric', 'gt:0', 'max:100'],
        ]);

        $expense = Expense::query()
            ->where('company_id', $this->companyId($request))
            ->where('uuid', $validated['expense_id'])
            ->firstOrFail();

        $rows = $this->allocations->allocate(
            $expense,
            CostAllocationMethod::from($validated['method']),
            $validated['destinations'],
            $this->actorId($request),
        );

        return response()->json(['data' => array_map(fn (CostAllocation $a) => $this->payload($a), $rows)], 201);
    }

    public function reverse(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $allocation = CostAllocation::query()
            ->where('company_id', $this->companyId($request))
            ->where('uuid', $uuid)
            ->firstOrFail();

        $reversal = $this->allocations->reverseAllocation($allocation, $validated['reason'], $this->actorId($request));

        return response()->json(['data' => $this->payload($reversal)], 201);
    }

    /** @return array<string, mixed> */
    private function payload(CostAllocation $a): array
    {
        return [
            'id' => $a->uuid,
            'source_type' => $a->source_type,
            'source_id' => $a->source_id,
            'source_amount' => (float) $a->source_amount,
            'method' => $a->method->value,
            'destination_profit_center_id' => $a->destination_profit_center_id,
            'allocated_amount' => (float) $a->allocated_amount,
            'percentage' => $a->percentage !== null ? (float) $a->percentage : null,
            'reverses_allocation_id' => $a->reverses?->uuid,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
