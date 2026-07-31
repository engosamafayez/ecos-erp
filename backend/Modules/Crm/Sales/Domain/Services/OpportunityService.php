<?php

declare(strict_types=1);

namespace Modules\Crm\Sales\Domain\Services;

use Illuminate\Support\Carbon;
use Modules\Crm\Sales\Domain\Enums\OpportunityStatus;
use Modules\Crm\Sales\Domain\Exceptions\SalesException;
use Modules\Crm\Sales\Domain\Models\Opportunity;
use Modules\Crm\Sales\Domain\Models\Pipeline;
use Modules\Crm\Sales\Domain\Models\PipelineStage;

/**
 * Opportunities and the sales pipeline. Moving a deal to a stage adopts that
 * stage's win probability; a won deal references its ORDER by opaque id only —
 * Commerce owns the order, the CRM owns the deal.
 */
final class OpportunityService
{
    public function __construct(private readonly PipelineService $pipelines) {}

    /** @param array<string, mixed> $data */
    public function create(string $companyId, array $data, ?int $actorId = null): Opportunity
    {
        $pipeline = isset($data['pipeline_id'])
            ? Pipeline::query()->where('company_id', $companyId)->where('id', $data['pipeline_id'])->with('stages')->first()
            : $this->pipelines->defaultPipeline($companyId);

        $stage = $pipeline !== null ? $this->pipelines->firstStage($pipeline) : null;

        return Opportunity::create([
            'company_id' => $companyId,
            'customer_id' => $data['customer_id'] ?? null,
            'lead_id' => $data['lead_id'] ?? null,
            'pipeline_id' => $pipeline?->id,
            'stage_id' => $stage?->id,
            'name' => $data['name'],
            'amount' => $data['amount'] ?? 0,
            'currency' => $data['currency'] ?? 'EGP',
            'probability' => $stage?->probability ?? 0,
            'expected_close_date' => $data['expected_close_date'] ?? null,
            'status' => OpportunityStatus::Open->value,
            'source' => $data['source'] ?? null,
            'owner_id' => $data['owner_id'] ?? $actorId,
            'created_by' => $actorId,
        ]);
    }

    public function moveToStage(Opportunity $opportunity, PipelineStage $stage): Opportunity
    {
        $this->assertOpen($opportunity);

        $opportunity->update(['stage_id' => $stage->id, 'probability' => $stage->probability]);

        if ($stage->is_won) {
            return $this->win($opportunity->refresh());
        }
        if ($stage->is_lost) {
            return $this->lose($opportunity->refresh(), 'Moved to a lost stage');
        }

        return $opportunity->refresh();
    }

    public function win(Opportunity $opportunity, ?string $orderReference = null, ?int $actorId = null): Opportunity
    {
        $this->assertOpen($opportunity);

        $opportunity->update([
            'status' => OpportunityStatus::Won->value,
            'probability' => 100,
            'won_at' => Carbon::now(),
            'order_reference' => $orderReference,
        ]);

        return $opportunity->refresh();
    }

    public function lose(Opportunity $opportunity, string $reason, ?int $actorId = null): Opportunity
    {
        $this->assertOpen($opportunity);

        $opportunity->update([
            'status' => OpportunityStatus::Lost->value,
            'probability' => 0,
            'lost_at' => Carbon::now(),
            'lost_reason' => $reason,
        ]);

        return $opportunity->refresh();
    }

    public function reopen(Opportunity $opportunity): Opportunity
    {
        $opportunity->update([
            'status' => OpportunityStatus::Open->value,
            'won_at' => null, 'lost_at' => null, 'lost_reason' => null, 'order_reference' => null,
        ]);

        return $opportunity->refresh();
    }

    /**
     * The weighted pipeline forecast — open deals summed by amount × probability.
     *
     * @return array<string, mixed>
     */
    public function forecast(string $companyId): array
    {
        $open = Opportunity::query()->where('company_id', $companyId)->where('status', 'open')->get();

        return [
            'open_count' => $open->count(),
            'total_value' => round((float) $open->sum('amount'), 2),
            'weighted_value' => round($open->sum(fn (Opportunity $o) => $o->weightedValue()), 2),
        ];
    }

    private function assertOpen(Opportunity $opportunity): void
    {
        if ($opportunity->status->isClosed()) {
            throw SalesException::opportunityClosed($opportunity->name);
        }
    }
}
