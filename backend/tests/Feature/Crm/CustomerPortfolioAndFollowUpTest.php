<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Modules\Crm\Customers\Domain\Enums\CustomerType;
use Modules\Crm\Customers\Domain\Services\CustomerService;
use Modules\Crm\Engagement\Domain\Enums\FollowUpQueue;
use Modules\Crm\Engagement\Domain\Enums\TaskStatus;
use Modules\Crm\Engagement\Domain\Models\CustomerTask;
use Modules\Crm\Engagement\Domain\Services\FollowUpQueueClassifier;
use Modules\Crm\Engagement\Domain\Services\TaskService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-CRM-CUSTOMER-PORTFOLIO-AND-FOLLOWUP-003 (CTO ratification resume).
 *
 * Covers the ratified authorities: Portfolio membership (canonical Customers,
 * no new aggregate), sales_owner_id assignment, CustomerTask/TaskPriority/
 * FollowUpQueueClassifier, and tenant isolation across all of them.
 */
final class CustomerPortfolioAndFollowUpTest extends TestCase
{
    use DatabaseTransactions;

    private function customerService(): CustomerService
    {
        return app(CustomerService::class);
    }

    private function taskService(): TaskService
    {
        return app(TaskService::class);
    }

    // ═══ PORTFOLIO MEMBERSHIP ═══════════════════════════════════════════════

    public function test_portfolio_lists_every_company_customer_including_unassigned(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Unassigned', 'phone' => '01000000010']);

        $response = $this->actingAs($user)->getJson('/api/crm/portfolio');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['is_unassigned']);
        $this->assertNull($rows[0]['sales_owner_id']);
    }

    public function test_portfolio_filters_unassigned_only(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->create(['company_id' => $company->id]);

        $assigned = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Assigned', 'phone' => '01000000011']);
        $assigned->forceFill(['sales_owner_id' => $owner->id, 'sales_owner_name' => $owner->name])->save();
        $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Unassigned', 'phone' => '01000000012']);

        $response = $this->actingAs($user)->getJson('/api/crm/portfolio?unassigned=1');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Unassigned', $rows[0]['name']);
    }

    public function test_portfolio_search_matches_name_and_code(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Zeinab', 'last_name' => 'Kamel', 'phone' => '01000000013']);
        $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Other', 'phone' => '01000000014']);

        $response = $this->actingAs($user)->getJson('/api/crm/portfolio?search=Zeinab');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Zeinab', $rows[0]['name']);
    }

    // ═══ TENANT ISOLATION — PORTFOLIO ═══════════════════════════════════════

    public function test_portfolio_excludes_other_companys_customers(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $this->customerService()->create((string) $companyB->id, CustomerType::Individual, ['first_name' => 'B-only', 'phone' => '01000000020']);

        $response = $this->actingAs($userA)->getJson('/api/crm/portfolio');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    // ═══ ASSIGNMENT AUTHORITY (customers.sales_owner_id) ════════════════════

    public function test_assign_owner_succeeds_for_a_valid_company_user(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $owner = User::factory()->create(['company_id' => $company->id, 'name' => 'Nadia Owner']);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Target', 'phone' => '01000000030']);

        $response = $this->actingAs($actor)->patchJson("/api/customers/{$customer->id}/sales-owner", [
            'sales_owner_id' => $owner->id,
        ]);

        $response->assertOk();
        $this->assertSame((string) $owner->id, $response->json('data.sales_owner_id'));
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'sales_owner_id' => $owner->id]);
    }

    public function test_assign_owner_rejects_a_cross_company_user(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $actor = User::factory()->create(['company_id' => $companyA->id]);
        $foreignUser = User::factory()->create(['company_id' => $companyB->id]);
        $customer = $this->customerService()->create((string) $companyA->id, CustomerType::Individual, ['first_name' => 'Target', 'phone' => '01000000031']);

        $response = $this->actingAs($actor)->patchJson("/api/customers/{$customer->id}/sales-owner", [
            'sales_owner_id' => $foreignUser->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'sales_owner_id' => null]);
    }

    public function test_assign_owner_rejects_a_cross_company_customer(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $actor = User::factory()->create(['company_id' => $companyA->id]);
        $foreignCustomer = $this->customerService()->create((string) $companyB->id, CustomerType::Individual, ['first_name' => 'B-customer', 'phone' => '01000000032']);

        $response = $this->actingAs($actor)->patchJson("/api/customers/{$foreignCustomer->id}/sales-owner", [
            'sales_owner_id' => $actor->id,
        ]);

        $response->assertStatus(404);
    }

    public function test_unassign_owner_clears_the_field(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $owner = User::factory()->create(['company_id' => $company->id]);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Target', 'phone' => '01000000033']);
        $customer->forceFill(['sales_owner_id' => $owner->id, 'sales_owner_name' => $owner->name])->save();

        $response = $this->actingAs($actor)->patchJson("/api/customers/{$customer->id}/sales-owner", [
            'sales_owner_id' => null,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'sales_owner_id' => null, 'sales_owner_name' => null]);
    }

    // ═══ FOLLOW-UP LIFECYCLE ═════════════════════════════════════════════════

    public function test_create_follow_up_defaults_priority_to_normal(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'A', 'phone' => '01000000040']);

        $response = $this->actingAs($user)->postJson("/api/crm/customers/{$customer->id}/tasks", [
            'task_type' => 'follow_up',
            'title' => 'Call about renewal',
        ]);

        $response->assertCreated();
        $this->assertSame('normal', $response->json('data.priority'));
        $this->assertTrue($response->json('data.priority_valid'));
        $this->assertSame('open', $response->json('data.status'));
    }

    public function test_priority_validation_accepts_urgent_and_rejects_invalid(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'A', 'phone' => '01000000041']);

        $ok = $this->actingAs($user)->postJson("/api/crm/customers/{$customer->id}/tasks", [
            'title' => 'Urgent call', 'priority' => 'urgent',
        ]);
        $ok->assertCreated();
        $this->assertSame('urgent', $ok->json('data.priority'));

        $bad = $this->actingAs($user)->postJson("/api/crm/customers/{$customer->id}/tasks", [
            'title' => 'Bad priority', 'priority' => 'critical',
        ]);
        $bad->assertStatus(422);
    }

    public function test_historical_priority_value_outside_v1_set_remains_readable_not_coerced(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'A', 'phone' => '01000000042']);

        $task = CustomerTask::create([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'task_type' => 'follow_up',
            'title' => 'Legacy row', 'status' => 'open', 'priority' => 'critical', // not in the V1 set
        ]);

        $this->assertNull($task->fresh()->priorityEnum());
        $this->assertSame('critical', $task->fresh()->priority);

        $response = $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}/tasks");
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $task->id);
        $this->assertSame('critical', $row['priority']);
        $this->assertFalse($row['priority_valid']);
    }

    public function test_complete_an_open_follow_up(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'A', 'phone' => '01000000043']);
        $task = $this->taskService()->create((string) $company->id, (string) $customer->id, \Modules\Crm\Engagement\Domain\Enums\TaskType::FollowUp, ['title' => 'F1']);

        $response = $this->actingAs($user)->patchJson("/api/crm/customers/{$customer->id}/tasks/{$task->id}/complete");

        $response->assertOk();
        $this->assertSame('completed', $response->json('data.status'));
        $this->assertNotNull($response->json('data.completed_at'));
    }

    public function test_cannot_complete_an_already_completed_follow_up(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'A', 'phone' => '01000000044']);
        $task = $this->taskService()->create((string) $company->id, (string) $customer->id, \Modules\Crm\Engagement\Domain\Enums\TaskType::FollowUp, ['title' => 'F1']);
        $this->taskService()->complete($task);

        $response = $this->actingAs($user)->patchJson("/api/crm/customers/{$customer->id}/tasks/{$task->id}/complete");

        $response->assertStatus(422);
    }

    public function test_cancel_an_open_follow_up(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'A', 'phone' => '01000000045']);
        $task = $this->taskService()->create((string) $company->id, (string) $customer->id, \Modules\Crm\Engagement\Domain\Enums\TaskType::FollowUp, ['title' => 'F1']);

        $response = $this->actingAs($user)->patchJson("/api/crm/customers/{$customer->id}/tasks/{$task->id}/cancel");

        $response->assertOk();
        $this->assertSame('cancelled', $response->json('data.status'));
    }

    public function test_reschedule_an_open_follow_up(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'A', 'phone' => '01000000046']);
        $task = $this->taskService()->create((string) $company->id, (string) $customer->id, \Modules\Crm\Engagement\Domain\Enums\TaskType::FollowUp, ['title' => 'F1', 'due_at' => '2026-01-01 10:00:00']);

        $response = $this->actingAs($user)->patchJson("/api/crm/customers/{$customer->id}/tasks/{$task->id}/reschedule", [
            'due_at' => '2026-02-15 09:00:00',
        ]);

        $response->assertOk();
        $this->assertStringStartsWith('2026-02-15', $response->json('data.due_at'));
    }

    public function test_cannot_reschedule_a_closed_follow_up(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'A', 'phone' => '01000000047']);
        $task = $this->taskService()->create((string) $company->id, (string) $customer->id, \Modules\Crm\Engagement\Domain\Enums\TaskType::FollowUp, ['title' => 'F1']);
        $this->taskService()->cancel($task);

        $response = $this->actingAs($user)->patchJson("/api/crm/customers/{$customer->id}/tasks/{$task->id}/reschedule", [
            'due_at' => '2026-02-15 09:00:00',
        ]);

        $response->assertStatus(422);
    }

    // ═══ TENANT ISOLATION — FOLLOW-UP ════════════════════════════════════════

    public function test_cannot_read_or_mutate_another_companys_follow_up(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $customerB = $this->customerService()->create((string) $companyB->id, CustomerType::Individual, ['first_name' => 'B', 'phone' => '01000000050']);
        $taskB = $this->taskService()->create((string) $companyB->id, (string) $customerB->id, \Modules\Crm\Engagement\Domain\Enums\TaskType::FollowUp, ['title' => 'B task']);

        // Company A cannot even resolve company B's customer to reach the task.
        $this->actingAs($userA)->getJson("/api/crm/customers/{$customerB->id}/tasks")->assertNotFound();
        $this->actingAs($userA)->patchJson("/api/crm/customers/{$customerB->id}/tasks/{$taskB->id}/complete")->assertNotFound();
    }

    // ═══ DUE / OVERDUE / QUEUE CLASSIFICATION ════════════════════════════════

    public function test_queue_classification_boundaries(): void
    {
        $now = Carbon::parse('2026-06-15 12:00:00', config('app.timezone'));
        Carbon::setTestNow($now);

        try {
            $this->assertSame(
                FollowUpQueue::Overdue,
                FollowUpQueueClassifier::classify(TaskStatus::Open, $now->copy()->subHour()),
            );
            $this->assertSame(
                FollowUpQueue::DueToday,
                FollowUpQueueClassifier::classify(TaskStatus::Open, $now->copy()->addHour()),
            );
            $this->assertSame(
                FollowUpQueue::DueToday,
                FollowUpQueueClassifier::classify(TaskStatus::Open, $now->copy()->endOfDay()),
            );
            $this->assertSame(
                FollowUpQueue::Upcoming,
                FollowUpQueueClassifier::classify(TaskStatus::Open, $now->copy()->addDay()->startOfDay()),
            );
            $this->assertSame(
                FollowUpQueue::Unscheduled,
                FollowUpQueueClassifier::classify(TaskStatus::Open, null),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_completed_and_cancelled_tasks_are_never_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

        try {
            $pastDue = Carbon::now()->subDay();
            $this->assertNull(FollowUpQueueClassifier::classify(TaskStatus::Completed, $pastDue));
            $this->assertNull(FollowUpQueueClassifier::classify(TaskStatus::Cancelled, $pastDue));
            $this->assertFalse(FollowUpQueueClassifier::isOverdue(TaskStatus::Completed, $pastDue));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_portfolio_and_customer_360_agree_on_overdue_state_for_the_same_task(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

        try {
            $company = Company::factory()->create();
            $user = User::factory()->create(['company_id' => $company->id]);
            $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'A', 'phone' => '01000000060']);
            $this->taskService()->create((string) $company->id, (string) $customer->id, \Modules\Crm\Engagement\Domain\Enums\TaskType::FollowUp, [
                'title' => 'Overdue one', 'due_at' => Carbon::now()->subDay()->toIso8601String(),
            ]);

            $portfolio = $this->actingAs($user)->getJson('/api/crm/portfolio')->json('data.0.crm.next_follow_up.queue');
            $profile = $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}/profile")->json('data.crm.next_follow_up.queue');

            $this->assertSame('overdue', $portfolio);
            $this->assertSame('overdue', $profile);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ═══ CUSTOMER 360 CRM SECTION ════════════════════════════════════════════

    public function test_profile_crm_section_handles_zero_follow_ups_gracefully(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Quiet', 'phone' => '01000000070']);

        $response = $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}/profile");

        $response->assertOk();
        $crm = $response->json('data.crm');
        $this->assertSame(0, $crm['open_follow_ups_count']);
        $this->assertNull($crm['next_follow_up']);
        $this->assertNull($crm['owner']['id']);
        // Gate B sections must still be present and correct alongside the new one.
        $this->assertArrayHasKey('finance', $response->json('data'));
        $this->assertArrayHasKey('blocked', $response->json('data'));
        $this->assertArrayHasKey('engagement', $response->json('data'));
    }

    // ═══ PERFORMANCE — BOUNDED QUERY COUNT ═══════════════════════════════════

    public function test_portfolio_list_query_count_is_bounded_regardless_of_row_count(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        for ($i = 0; $i < 8; $i++) {
            $c = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => "Cust{$i}", 'phone' => '0100000008'.$i]);
            $this->taskService()->create((string) $company->id, (string) $c->id, \Modules\Crm\Engagement\Domain\Enums\TaskType::FollowUp, ['title' => "Task{$i}"]);
        }

        $queryCount = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->actingAs($user)->getJson('/api/crm/portfolio')->assertOk();

        // Bounded (a small constant), never proportional to the 8 customers above —
        // the exact number is an implementation detail; what matters is it stays
        // well under "one query per customer" (which would be 8+ per fact fetched).
        $this->assertLessThan(20, $queryCount, "Portfolio issued {$queryCount} queries for 8 customers — investigate for N+1.");
    }
}
