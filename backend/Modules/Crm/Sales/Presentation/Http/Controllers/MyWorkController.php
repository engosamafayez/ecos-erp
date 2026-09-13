<?php

declare(strict_types=1);

namespace Modules\Crm\Sales\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Collaboration\Application\Actions\ListMyTasksAction;
use Modules\Collaboration\Domain\Enums\TaskStatus as InternalTaskStatus;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Crm\Customers\Presentation\Http\Controllers\Concerns\ResolvesCustomerContext;
use Modules\Crm\Portfolio\Domain\Services\PortfolioService;
use Modules\Crm\Sales\Domain\Enums\LeadStatus;
use Modules\Crm\Sales\Domain\Enums\OpportunityStatus;
use Modules\Crm\Sales\Domain\Models\Lead;
use Modules\Crm\Sales\Domain\Models\Opportunity;
use Modules\Crm\Sales\Domain\Models\SalesActivity;
use Modules\Crm\Sales\Domain\Services\SalesActivityService;

/**
 * CRM-01 TASK 2 — "My Work": the current user's assigned CRM work, composed
 * read-only from existing authorities. No new persistence, no new engine.
 *
 * ┌─ WORKFLOW METADATA, NOT A NEW ACL ──────────────────────────────────────┐
 * │ `owner_id`/`assignee_id` here are the same fields Task 1's reconciliation │
 * │ found already exist — this endpoint only FILTERS by them for one user's  │
 * │ own view. It grants no visibility a company-permission holder didn't      │
 * │ already have (the same rows remain reachable via the plain Lead/           │
 * │ Opportunity/Portfolio list endpoints); it takes none away either.         │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `Collaboration\InternalTask` is surfaced as its own, honestly-separate
 * section — it currently has no column linking it to any Lead/Opportunity/
 * Customer (confirmed by reading the model), so it is NOT presented as CRM
 * work here, only as "your other tasks". See CRM-01 Task 2 report, "My Work".
 */
class MyWorkController extends Controller
{
    use ResolvesCustomerContext;

    public function __construct(
        private readonly PortfolioService $portfolio,
        private readonly SalesActivityService $activities,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $userId = $this->actorId($request);

        return response()->json(['data' => [
            'leads' => $this->myLeads($companyId, $userId),
            'opportunities' => $this->myOpportunities($companyId, $userId),
            'due_activities' => $this->myDueActivities($companyId, $userId),
            'customer_follow_ups' => $this->myCustomerFollowUps($companyId, $userId),
            'internal_tasks' => $this->myInternalTasks($request),
        ]]);
    }

    /** @return array<int, array<string, mixed>> */
    private function myLeads(string $companyId, ?int $userId): array
    {
        return Lead::query()
            ->where('company_id', $companyId)
            ->where('owner_id', $userId)
            ->whereNotIn('status', [LeadStatus::Converted->value, LeadStatus::Unqualified->value])
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (Lead $l) => [
                'id' => $l->id, 'name' => $l->name, 'company_name' => $l->company_name,
                'status' => $l->status->value, 'source' => $l->source, 'score' => $l->score,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function myOpportunities(string $companyId, ?int $userId): array
    {
        return Opportunity::query()
            ->where('company_id', $companyId)
            ->where('owner_id', $userId)
            ->where('status', OpportunityStatus::Open->value)
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (Opportunity $o) => [
                'id' => $o->id, 'name' => $o->name, 'customer_id' => $o->customer_id, 'lead_id' => $o->lead_id,
                'pipeline_id' => $o->pipeline_id, 'stage_id' => $o->stage_id, 'amount' => (float) $o->amount,
                'expected_close_date' => $o->expected_close_date?->toDateString(),
            ])
            ->all();
    }

    /** Lead/Opportunity follow-ups due now or overdue — the existing "agents' work queue" feed. */
    private function myDueActivities(string $companyId, ?int $userId): array
    {
        return $this->activities->due($companyId, null, $userId)
            ->map(fn (SalesActivity $a) => [
                'id' => $a->id, 'subject_type' => $a->subject_type, 'subject_id' => $a->subject_id,
                'activity_type' => $a->activity_type->value, 'title' => $a->title,
                'due_at' => $a->due_at?->toIso8601String(),
            ])
            ->all();
    }

    /** Customer follow-ups due now or overdue, reusing Portfolio's own queue classification. */
    private function myCustomerFollowUps(string $companyId, ?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        $overdue = $this->portfolio->list($companyId, ['sales_owner_id' => $userId, 'queue' => 'overdue', 'per_page' => 25]);
        $dueToday = $this->portfolio->list($companyId, ['sales_owner_id' => $userId, 'queue' => 'due_today', 'per_page' => 25]);

        return collect($overdue->items())->concat($dueToday->items())->values()->all();
    }

    /**
     * The user's own generic InternalTasks (created by, assigned to, or an
     * additional assignee on) — unfiltered by any CRM relationship, since none
     * exists today. Excludes archived (the action's own default) and closed
     * (done/cancelled) so this stays an actionable list, not a history.
     */
    private function myInternalTasks(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return app(ListMyTasksAction::class)->execute($user, ['scope' => 'mine'])
            ->reject(fn (InternalTask $t) => in_array($t->status, [InternalTaskStatus::Done, InternalTaskStatus::Cancelled], true))
            ->take(50)
            ->map(fn (InternalTask $t) => [
                'id' => $t->id, 'title' => $t->title, 'status' => $t->status->value,
                'priority' => $t->priority->value, 'due_at' => $t->due_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
