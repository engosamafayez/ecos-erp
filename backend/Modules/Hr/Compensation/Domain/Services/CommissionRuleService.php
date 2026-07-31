<?php

declare(strict_types=1);

namespace Modules\Hr\Compensation\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Hr\Compensation\Domain\Enums\CommissionMethod;
use Modules\Hr\Compensation\Domain\Enums\CommissionScope;
use Modules\Hr\Compensation\Domain\Enums\KpiMetric;
use Modules\Hr\Compensation\Domain\Exceptions\CompensationException;
use Modules\Hr\Compensation\Domain\Models\CommissionRule;
use Modules\Hr\Compensation\Domain\Models\CommissionRuleTier;

/**
 * Administering commission rules.
 *
 * The only validation that matters: the metric must be one HR can actually
 * measure, and a tiered rule must have tiers. A rule referencing a metric nobody
 * publishes would silently pay nothing forever, which is worse than a refusal.
 */
final class CommissionRuleService
{
    public function create(string $companyId, array $data): CommissionRule
    {
        $metric = KpiMetric::tryFrom((string) ($data['metric_key'] ?? ''));

        if ($metric === null) {
            throw CompensationException::unknownMetric((string) ($data['metric_key'] ?? ''));
        }

        $method = ($data['method'] ?? null) instanceof CommissionMethod
            ? $data['method']
            : (CommissionMethod::tryFrom((string) ($data['method'] ?? '')) ?? CommissionMethod::PercentageOfValue);

        $tiers = (array) ($data['tiers'] ?? []);

        if ($method->requiresTiers() && $tiers === []) {
            throw CompensationException::tiersRequired();
        }

        $scope = ($data['applies_to'] ?? null) instanceof CommissionScope
            ? $data['applies_to']
            : (CommissionScope::tryFrom((string) ($data['applies_to'] ?? '')) ?? CommissionScope::All);

        return DB::transaction(function () use ($companyId, $data, $metric, $method, $scope, $tiers): CommissionRule {
            $rule = CommissionRule::create([
                'company_id' => $companyId,
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'metric_key' => $metric->value,
                'method' => $method->value,
                'rate' => round((float) ($data['rate'] ?? 0), 4),
                'applies_to' => $scope->value,
                'target_id' => $data['target_id'] ?? null,
                'dimension_key' => $data['dimension_key'] ?? null,
                'dimension_value' => $data['dimension_value'] ?? null,
                'min_amount' => $data['min_amount'] ?? null,
                'max_amount' => $data['max_amount'] ?? null,
                'threshold_value' => $data['threshold_value'] ?? null,
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'priority' => (int) ($data['priority'] ?? 100),
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->replaceTiers($rule, $tiers);

            return $rule->refresh();
        });
    }

    public function update(CommissionRule $rule, array $data): CommissionRule
    {
        if (isset($data['metric_key'])) {
            $metric = KpiMetric::tryFrom((string) $data['metric_key']);

            if ($metric === null) {
                throw CompensationException::unknownMetric((string) $data['metric_key']);
            }
        }

        return DB::transaction(function () use ($rule, $data): CommissionRule {
            $rule->update(array_intersect_key($data, array_flip([
                'code', 'name', 'description', 'metric_key', 'method', 'rate',
                'applies_to', 'target_id', 'dimension_key', 'dimension_value',
                'min_amount', 'max_amount', 'threshold_value',
                'effective_from', 'effective_to', 'priority', 'is_active',
            ])));

            if (array_key_exists('tiers', $data)) {
                $this->replaceTiers($rule, (array) $data['tiers']);
            }

            return $rule->refresh();
        });
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, CommissionRule> */
    public function forCompany(string $companyId)
    {
        return CommissionRule::query()
            ->with('tiers')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();
    }

    /**
     * The metric catalogue a rule can be written against — what the UI offers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableMetrics(): array
    {
        return array_map(fn (KpiMetric $m) => [
            'key' => $m->value,
            'label' => $m->label(),
            'module' => $m->sourceModule(),
            'unit' => $m->unit(),
            'aggregation' => $m->aggregation()->value,
            'higher_is_better' => $m->higherIsBetter(),
        ], KpiMetric::cases());
    }

    /** @param array<int, array<string, mixed>> $tiers */
    private function replaceTiers(CommissionRule $rule, array $tiers): void
    {
        $rule->tiers()->delete();

        $sequence = 1;
        foreach ($tiers as $tier) {
            CommissionRuleTier::create([
                'rule_id' => $rule->id,
                'from_value' => round((float) ($tier['from_value'] ?? 0), 4),
                'to_value' => isset($tier['to_value']) && $tier['to_value'] !== null
                    ? round((float) $tier['to_value'], 4)
                    : null,
                'rate' => round((float) ($tier['rate'] ?? 0), 4),
                'sequence' => (int) ($tier['sequence'] ?? $sequence),
            ]);
            $sequence++;
        }
    }
}
