<?php

declare(strict_types=1);

namespace Modules\Logistics\Operations\Domain\Services;

use Illuminate\Support\Carbon;

/**
 * Enterprise readiness — the health score, the module summary and the checklist,
 * all built ON TOP of CrossModuleValidationService.
 *
 * ┌─ SCORING OVER SIGNALS, NOT NEW SIGNALS ─────────────────────────────────┐
 * │ The Logistics Health Score is a weighted roll-up of the validation       │
 * │ statuses this service is HANDED. It invents no metric: a module that     │
 * │ CrossModuleValidationService calls ready scores full, degraded scores    │
 * │ half, not-ready scores zero. Change what "ready" means in one place and  │
 * │ the score follows.                                                       │
 * │                                                                          │
 * │ The weights say what the operation depends on most. They are the ONE     │
 * │ judgement this phase adds, and they are stated here in the open rather   │
 * │ than buried in a config table (read-model only — nothing is stored).     │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
class ReadinessValidationService
{
    /**
     * What the operation leans on, in order. Fleet and drivers are the hard
     * floor — without a vehicle and a driver nothing moves at all — so they
     * carry the most weight.
     *
     * @var array<string, int>
     */
    private const WEIGHTS = [
        'fleet' => 25,
        'drivers' => 25,
        'capacity' => 20,
        'dispatch' => 20,
        'operations' => 10,
    ];

    public function __construct(
        private readonly CrossModuleValidationService $validation,
    ) {}

    /**
     * The Enterprise Readiness Dashboard — score, overall status, module
     * summary and the checklist in one payload.
     *
     * @return array<string, mixed>
     */
    public function dashboard(?string $companyId = null): array
    {
        $report = $this->validation->report($companyId);

        return [
            'generated_at' => $report['generated_at'],
            'health_score' => $this->scoreFrom($report),
            'overall_status' => $report['overall_status'],
            'ready_count' => $report['ready_count'],
            'degraded_count' => $report['degraded_count'],
            'not_ready_count' => $report['not_ready_count'],
            'modules' => $this->summariseModules($report),
            'checklist' => $this->checklistFrom($report),
        ];
    }

    /**
     * The Logistics Health Score, 0-100.
     *
     * @return array<string, mixed>
     */
    public function healthScore(?string $companyId = null): array
    {
        $report = $this->validation->report($companyId);

        return [
            'generated_at' => $report['generated_at'],
            'score' => $this->scoreFrom($report),
            'grade' => $this->grade($this->scoreFrom($report)),
            'overall_status' => $report['overall_status'],
            'weights' => self::WEIGHTS,
        ];
    }

    /**
     * Module Readiness Summary — one line per authority.
     *
     * @return array<string, mixed>
     */
    public function moduleSummary(?string $companyId = null): array
    {
        $report = $this->validation->report($companyId);

        return [
            'generated_at' => $report['generated_at'],
            'overall_status' => $report['overall_status'],
            'modules' => $this->summariseModules($report),
        ];
    }

    /**
     * Operational Readiness Checklist — every check, flattened and grouped, so a
     * duty manager can see exactly what is and is not satisfied before a shift.
     *
     * @return array<string, mixed>
     */
    public function checklist(?string $companyId = null): array
    {
        $report = $this->validation->report($companyId);
        $items = $this->checklistFrom($report);

        return [
            'generated_at' => $report['generated_at'],
            'overall_status' => $report['overall_status'],
            'items' => $items,
            'passed' => count(array_filter($items, static fn ($i) => $i['passed'])),
            'total' => count($items),
            // What actually blocks go-live, called out.
            'blocking_failures' => array_values(array_filter(
                $items,
                static fn ($i) => ! $i['passed'] && $i['severity'] === 'blocking',
            )),
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $report
     */
    private function scoreFrom(array $report): int
    {
        $total = array_sum(self::WEIGHTS);
        $earned = 0.0;

        foreach ($report['modules'] as $module) {
            $weight = self::WEIGHTS[$module['module']] ?? 0;

            $earned += $weight * match ($module['status']) {
                CrossModuleValidationService::READY => 1.0,
                CrossModuleValidationService::DEGRADED => 0.5,
                default => 0.0,
            };
        }

        return (int) round(($earned / $total) * 100);
    }

    private function grade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 60 => 'C',
            $score >= 40 => 'D',
            default => 'F',
        };
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private function summariseModules(array $report): array
    {
        return array_map(static fn (array $m) => [
            'module' => $m['module'],
            'label' => $m['label'],
            'status' => $m['status'],
            'passed_checks' => $m['passed_checks'],
            'total_checks' => $m['total_checks'],
            // The first reason is the headline; the rest are in the checklist.
            'headline' => $m['reasons'][0] ?? null,
            'weight' => self::WEIGHTS[$m['module']] ?? 0,
        ], $report['modules']);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private function checklistFrom(array $report): array
    {
        $items = [];

        foreach ($report['modules'] as $module) {
            foreach ($module['checks'] as $check) {
                $items[] = [
                    'id' => $module['module'].'.'.$check['name'],
                    'module' => $module['module'],
                    'module_label' => $module['label'],
                    'label' => $this->humanise($check['name']),
                    'passed' => $check['passed'],
                    'severity' => $check['severity'],
                    'detail' => $check['detail'],
                ];
            }
        }

        return $items;
    }

    private function humanise(string $name): string
    {
        return ucfirst(str_replace('_', ' ', $name));
    }
}
