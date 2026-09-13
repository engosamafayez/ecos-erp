<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Crm\Sales\Domain\Services\OpportunityService;
use Modules\Crm\Sales\Domain\Services\PipelineService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * CRM-01 TASK 2 — closes the confirmed `OpportunityController::moveStage()`
 * cross-tenant scoping defect. The required invariant: an actor in Company A
 * must never move an Opportunity onto a stage that isn't that OPPORTUNITY's
 * own pipeline's stage — whether that foreign stage belongs to another
 * company entirely, or to a different pipeline within the SAME company (a
 * distinct correctness gap moveToStage() also never had — see
 * OpportunityService::assertStageInOpportunityPipeline()).
 */
final class PipelineCompanyScopingTest extends TestCase
{
    use DatabaseTransactions;

    private function pipelines(): PipelineService
    {
        return app(PipelineService::class);
    }

    private function opportunities(): OpportunityService
    {
        return app(OpportunityService::class);
    }

    public function test_move_stage_succeeds_within_the_same_company_and_pipeline(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $pipeline = $this->pipelines()->create((string) $company->id, 'Sales', [
            ['name' => 'New'], ['name' => 'Qualified'],
        ], true);
        $opportunity = $this->opportunities()->create((string) $company->id, ['name' => 'Deal A', 'pipeline_id' => $pipeline->id], $user->id);
        $targetStage = $pipeline->stages->firstWhere('name', 'Qualified');

        $response = $this->actingAs($user)->patchJson("/api/crm/sales/opportunities/{$opportunity->id}/stage", [
            'stage_id' => $targetStage->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.stage_id', $targetStage->id);
        $this->assertDatabaseHas('crm_opportunities', ['id' => $opportunity->id, 'stage_id' => $targetStage->id]);
    }

    public function test_move_stage_rejects_a_stage_belonging_to_another_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);

        $pipelineA = $this->pipelines()->create((string) $companyA->id, 'Sales A', [['name' => 'New']], true);
        $pipelineB = $this->pipelines()->create((string) $companyB->id, 'Sales B', [['name' => 'Foreign Stage']], true);
        $opportunity = $this->opportunities()->create((string) $companyA->id, ['name' => 'Deal A', 'pipeline_id' => $pipelineA->id], $userA->id);
        $foreignStage = $pipelineB->stages->first();

        $response = $this->actingAs($userA)->patchJson("/api/crm/sales/opportunities/{$opportunity->id}/stage", [
            'stage_id' => $foreignStage->id,
        ]);

        // The controller's own company-scoped stage lookup never resolves a
        // foreign-company stage id at all — this must 404, not 422 or 200.
        $response->assertStatus(404);
        $this->assertDatabaseHas('crm_opportunities', ['id' => $opportunity->id, 'stage_id' => $pipelineA->stages->first()->id]);
    }

    public function test_move_stage_rejects_a_stage_from_a_different_pipeline_in_the_same_company(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $pipelineOne = $this->pipelines()->create((string) $company->id, 'Pipeline One', [['name' => 'Start']], true);
        $pipelineTwo = $this->pipelines()->create((string) $company->id, 'Pipeline Two', [['name' => 'Other Board Stage']], false);
        $opportunity = $this->opportunities()->create((string) $company->id, ['name' => 'Deal', 'pipeline_id' => $pipelineOne->id], $user->id);
        $stageInOtherPipeline = $pipelineTwo->stages->first();

        // Same company — passes the controller's company-scoped lookup — but
        // NOT the same pipeline as the opportunity itself. The service-layer
        // assertion must still refuse this.
        $response = $this->actingAs($user)->patchJson("/api/crm/sales/opportunities/{$opportunity->id}/stage", [
            'stage_id' => $stageInOtherPipeline->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('crm_opportunities', [
            'id' => $opportunity->id,
            'stage_id' => $pipelineOne->stages->first()->id,
            'pipeline_id' => $pipelineOne->id,
        ]);
    }

    public function test_opportunity_list_is_scoped_to_the_acting_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->opportunities()->create((string) $company->id, ['name' => 'Mine'], $user->id);
        $this->opportunities()->create((string) $otherCompany->id, ['name' => 'Not Mine'], null);

        $response = $this->actingAs($user)->getJson('/api/crm/sales/opportunities');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Mine', $rows[0]['name']);
    }

    public function test_opportunity_list_supports_pipeline_owner_and_search_filters(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->create(['company_id' => $company->id]);
        $otherOwner = User::factory()->create(['company_id' => $company->id]);
        $pipeline = $this->pipelines()->create((string) $company->id, 'Sales', [['name' => 'New']], true);

        $this->opportunities()->create((string) $company->id, ['name' => 'Zeinab Deal', 'pipeline_id' => $pipeline->id], $owner->id);
        $this->opportunities()->create((string) $company->id, ['name' => 'Other Deal', 'pipeline_id' => $pipeline->id], $otherOwner->id);

        $response = $this->actingAs($owner)->getJson('/api/crm/sales/opportunities?'.http_build_query([
            'pipeline_id' => $pipeline->id,
            'owner_id' => $owner->id,
            'q' => 'Zeinab',
        ]));

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Zeinab Deal', $rows[0]['name']);
    }
}
