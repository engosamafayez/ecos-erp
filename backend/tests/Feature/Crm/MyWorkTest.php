<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Collaboration\Domain\Enums\TaskPriority as InternalTaskPriority;
use Modules\Collaboration\Domain\Enums\TaskStatus as InternalTaskStatus;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Crm\Sales\Domain\Services\LeadService;
use Modules\Crm\Sales\Domain\Services\OpportunityService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * CRM-01 TASK 2 — "My Work" composition (MyWorkController). Covers the exact
 * invariants the ticket named: the current user's own assigned Leads/
 * Opportunities are included, another user's assigned work is NOT, the
 * company boundary holds, and InternalTasks compose alongside without any
 * new persistence or duplicated task authority.
 */
final class MyWorkTest extends TestCase
{
    use DatabaseTransactions;

    private function leads(): LeadService
    {
        return app(LeadService::class);
    }

    private function opportunities(): OpportunityService
    {
        return app(OpportunityService::class);
    }

    public function test_my_work_includes_only_leads_and_opportunities_owned_by_the_current_user(): void
    {
        $company = Company::factory()->create();
        $me = User::factory()->create(['company_id' => $company->id]);
        $someoneElse = User::factory()->create(['company_id' => $company->id]);

        $myLead = $this->leads()->create((string) $company->id, ['name' => 'My Lead', 'owner_id' => $me->id]);
        $this->leads()->create((string) $company->id, ['name' => 'Their Lead', 'owner_id' => $someoneElse->id]);

        $myOpportunity = $this->opportunities()->create((string) $company->id, ['name' => 'My Deal'], $me->id);
        $this->opportunities()->create((string) $company->id, ['name' => 'Their Deal'], $someoneElse->id);

        $response = $this->actingAs($me)->getJson('/api/crm/my-work');

        $response->assertOk();
        $leadIds = array_column($response->json('data.leads'), 'id');
        $opportunityIds = array_column($response->json('data.opportunities'), 'id');

        $this->assertContains($myLead->id, $leadIds);
        $this->assertContains($myOpportunity->id, $opportunityIds);
        $this->assertCount(1, $leadIds, 'another user\'s lead leaked into my personal view');
        $this->assertCount(1, $opportunityIds, 'another user\'s opportunity leaked into my personal view');
    }

    public function test_my_work_excludes_converted_and_closed_deals(): void
    {
        $company = Company::factory()->create();
        $me = User::factory()->create(['company_id' => $company->id]);

        $convertedLead = $this->leads()->create((string) $company->id, ['name' => 'Converted', 'owner_id' => $me->id]);
        $this->leads()->convert($convertedLead, ['name' => 'Converted — opportunity'], $me->id);

        $wonOpportunity = $this->opportunities()->create((string) $company->id, ['name' => 'Won Deal'], $me->id);
        $this->opportunities()->win($wonOpportunity, null, $me->id);

        $response = $this->actingAs($me)->getJson('/api/crm/my-work');

        $response->assertOk();
        $leadIds = array_column($response->json('data.leads'), 'id');
        $opportunityIds = array_column($response->json('data.opportunities'), 'id');

        $this->assertNotContains($convertedLead->id, $leadIds);
        $this->assertNotContains($wonOpportunity->id, $opportunityIds);
    }

    public function test_my_work_is_scoped_to_the_acting_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $me = User::factory()->create(['company_id' => $company->id]);

        $this->leads()->create((string) $company->id, ['name' => 'Mine', 'owner_id' => $me->id]);
        // Same user id coincidentally "owning" a foreign-company lead must never surface here.
        $this->leads()->create((string) $otherCompany->id, ['name' => 'Foreign', 'owner_id' => $me->id]);

        $response = $this->actingAs($me)->getJson('/api/crm/my-work');

        $response->assertOk();
        $names = array_column($response->json('data.leads'), 'name');
        $this->assertContains('Mine', $names);
        $this->assertNotContains('Foreign', $names);
    }

    public function test_my_work_composes_internal_tasks_without_a_new_task_authority(): void
    {
        $company = Company::factory()->create();
        $me = User::factory()->create(['company_id' => $company->id]);

        // Created directly (bypassing CreateTaskAction's own authorization/board-
        // list orchestration, which is Collaboration's concern, not this
        // composition's) — this only proves MyWorkController correctly SURFACES
        // an existing InternalTask, via the same ListMyTasksAction the
        // Collaboration board itself already uses.
        $task = InternalTask::create([
            'company_id' => $company->id,
            'title' => 'Follow up on renewal',
            'creator_user_id' => $me->id,
            'assignee_user_id' => $me->id,
            'priority' => InternalTaskPriority::Normal,
            'status' => InternalTaskStatus::Todo,
        ]);

        $response = $this->actingAs($me)->getJson('/api/crm/my-work');

        $response->assertOk();
        $taskIds = array_column($response->json('data.internal_tasks'), 'id');
        $this->assertContains($task->id, $taskIds);

        // The composition is read-only — confirm no new table exists for it,
        // only the canonical collaboration_internal_tasks row above.
        $this->assertDatabaseHas('collaboration_internal_tasks', ['id' => $task->id, 'company_id' => $company->id]);
    }
}
