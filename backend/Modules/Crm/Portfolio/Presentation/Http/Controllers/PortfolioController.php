<?php

declare(strict_types=1);

namespace Modules\Crm\Portfolio\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Crm\Customers\Presentation\Http\Controllers\Concerns\ResolvesCustomerContext;
use Modules\Crm\Engagement\Domain\Enums\FollowUpQueue;
use Modules\Crm\Engagement\Domain\Enums\TaskPriority;
use Modules\Crm\Portfolio\Domain\Services\PortfolioService;

/**
 * The CRM Portfolio — a read model over canonical Customers plus CRM-owned
 * follow-up/ownership context (TASK-ECOS-CRM-CUSTOMER-PORTFOLIO-AND-FOLLOWUP-003).
 * Same permission as the customer list/profile: this is a view over customers,
 * not a separate authority.
 */
final class PortfolioController extends Controller
{
    use ResolvesCustomerContext;

    public function __construct(private readonly PortfolioService $portfolio) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'sales_owner_id' => ['nullable', 'integer'],
            'unassigned' => ['nullable', 'boolean'],
            'blocked' => ['nullable', 'boolean'],
            'queue' => ['nullable', Rule::in(array_map(fn ($c) => $c->value, FollowUpQueue::cases()))],
            'priority' => ['nullable', Rule::in(TaskPriority::values())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->portfolio->list($this->companyId($request), $validated);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}
