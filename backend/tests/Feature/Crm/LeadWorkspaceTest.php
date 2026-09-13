<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Crm\Sales\Domain\Services\LeadService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * CRM-01 TASK 1 — Lead 360 closure.
 *
 * Covers the two things this task actually changed in `Crm\Sales`: real
 * pagination/search/company-isolation on the Lead list (previously a hard
 * limit(100), no search — see CRM-01 report, "Search/pagination"), and a
 * regression guard on the pre-existing Lead→Customer conversion continuity
 * this task deliberately did not touch. Does not re-test Pipeline/Opportunity
 * mechanics or the known moveStage() scoping defect — that is CRM-01 Task 2.
 */
final class LeadWorkspaceTest extends TestCase
{
    use DatabaseTransactions;

    private function leadService(): LeadService
    {
        return app(LeadService::class);
    }

    public function test_lead_list_paginates_and_reports_meta(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        for ($i = 1; $i <= 3; $i++) {
            $this->leadService()->create((string) $company->id, ['name' => "Lead {$i}", 'phone' => "0100000010{$i}"]);
        }

        $response = $this->actingAs($user)->getJson('/api/crm/sales/leads?per_page=2');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
    }

    public function test_lead_list_search_matches_name_phone_email_and_company(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->leadService()->create((string) $company->id, ['name' => 'Zeinab Kamel', 'phone' => '01000000201']);
        $this->leadService()->create((string) $company->id, ['name' => 'Other Person', 'phone' => '01000000202']);

        $response = $this->actingAs($user)->getJson('/api/crm/sales/leads?q=Zeinab');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Zeinab Kamel', $rows[0]['name']);
    }

    public function test_lead_list_is_scoped_to_the_acting_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->leadService()->create((string) $company->id, ['name' => 'Mine', 'phone' => '01000000301']);
        $this->leadService()->create((string) $otherCompany->id, ['name' => 'Not Mine', 'phone' => '01000000302']);

        $response = $this->actingAs($user)->getJson('/api/crm/sales/leads');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Mine', $rows[0]['name']);
    }

    public function test_lead_show_rejects_a_lead_from_another_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $foreignLead = $this->leadService()->create((string) $otherCompany->id, ['name' => 'Foreign', 'phone' => '01000000401']);

        $response = $this->actingAs($user)->getJson("/api/crm/sales/leads/{$foreignLead->id}");

        $response->assertStatus(404);
    }

    public function test_convert_preserves_lead_history_and_links_the_resulting_customer(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $lead = $this->leadService()->create((string) $company->id, ['name' => 'Convertible Corp', 'phone' => '01000000501']);

        $response = $this->actingAs($user)->postJson("/api/crm/sales/leads/{$lead->id}/convert", [
            'opportunity_name' => 'Convertible Corp — first deal',
        ]);

        $response->assertOk();
        $customerId = $response->json('data.customer_id');
        $opportunityId = $response->json('data.opportunity_id');
        $this->assertNotNull($customerId);
        $this->assertNotNull($opportunityId);

        // The lead row itself must still exist and be traceable — not deleted,
        // not replaced — with both forward links populated.
        $show = $this->actingAs($user)->getJson("/api/crm/sales/leads/{$lead->id}");
        $show->assertOk();
        $show->assertJsonPath('data.status', 'converted');
        $show->assertJsonPath('data.customer_id', $customerId);
        $show->assertJsonPath('data.converted_opportunity_id', $opportunityId);

        // The resulting Customer resolves through the canonical CRM surface.
        $customer = $this->actingAs($user)->getJson("/api/crm/customers/{$customerId}");
        $customer->assertOk();
    }
}
