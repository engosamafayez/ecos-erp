<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * FIN-01 carry-forward closure item (continuation ticket §19).
 *
 * ┌─ TWO DIFFERENT RULES, DELIBERATELY NOT THE SAME ONE ────────────────────┐
 * │ CompensationArchitectureGuardTest::test_hr_imports_no_operational_module()  │
 * │ forbids Hr\Compensation and Hr\Performance from importing ANY operational   │
 * │ module — that rule protects the duck-typed KPI-bridge boundary, where       │
 * │ HR must integrate by reference only.                                       │
 * │                                                                            │
 * │ Driver identity resolution and Driver Performance presentation (FIN-01     │
 * │ 044B, Slice 1 + Slice 4) are a DIFFERENT, explicitly approved pattern —     │
 * │ "Pattern C" (per §5 of 044B, citing Reporting's ExecutiveOverviewQuery and  │
 * │ ADR-045): composing another module's own read-model classes directly and   │
 * │ copying their computed values verbatim. That pattern REQUIRES importing     │
 * │ the composed module's class — the opposite of duck-typing — so it could     │
 * │ never live inside the Compensation/Performance guard's rule without         │
 * │ contradicting it.                                                          │
 * │                                                                            │
 * │ This is why the Driver services live under Hr\Workforce rather than        │
 * │ Hr\Performance: not to dodge the old guard's scan path, but because they    │
 * │ are governed by a genuinely different, approved rule — asserted here,       │
 * │ explicitly, rather than left to survive only because nothing scans this     │
 * │ directory. The old guard is untouched; this is an additional, narrower one. │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
class WorkforceLogisticsBoundaryTest extends TestCase
{
    /**
     * Every Hr\Workforce file allowed to import Modules\Logistics, and
     * nothing else — an addition here is a deliberate, reviewed widening of
     * the Pattern-C exception, never an accident of file placement.
     */
    private const ALLOWED_LOGISTICS_IMPORTERS = [
        'DriverEmployeeResolver.php',
        'DriverPerformanceReadModel.php',
        'DriverPerformanceController.php',
        'DriverIdentityDiagnosticsCommand.php',
    ];

    public function test_only_the_approved_pattern_c_files_import_logistics_under_hr_workforce(): void
    {
        $offenders = [];

        foreach ($this->workforceSources() as $file => $source) {
            $importsLogistics = str_contains($source, 'use Modules\\Logistics');

            if ($importsLogistics && ! in_array($file, self::ALLOWED_LOGISTICS_IMPORTERS, true)) {
                $offenders[] = $file;
            }
        }

        $this->assertEmpty(
            $offenders,
            'Only the Driver identity/performance Pattern-C files may import Modules\\Logistics under Hr\\Workforce: '
            .implode(', ', $offenders),
        );
    }

    /** The allowlist itself must still be real — never let it silently stop matching anything. */
    public function test_the_allowlisted_files_genuinely_exist_and_do_import_logistics(): void
    {
        $sources = $this->workforceSources();

        foreach (self::ALLOWED_LOGISTICS_IMPORTERS as $file) {
            $this->assertArrayHasKey($file, $sources, "{$file} is allowlisted but no longer exists under Hr\\Workforce.");
            $this->assertStringContainsString(
                'use Modules\\Logistics',
                $sources[$file],
                "{$file} is allowlisted for the Logistics Pattern-C exception but no longer imports it — prune it from the allowlist.",
            );
        }
    }

    /** Reinforces (never replaces) the existing Compensation/Performance guard from the outside. */
    public function test_hr_compensation_and_performance_remain_free_of_logistics_imports(): void
    {
        foreach (['Modules/Hr/Compensation', 'Modules/Hr/Performance'] as $dir) {
            $path = base_path($dir);
            if (! is_dir($path)) {
                continue;
            }

            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $this->assertStringNotContainsString(
                    'use Modules\\Logistics',
                    (string) file_get_contents($file->getPathname()),
                    "{$file->getFilename()} must integrate by reference only — Pattern C is a Hr\\Workforce-only exception.",
                );
            }
        }
    }

    /** @return array<string, string> basename => source, for every PHP file under Hr\Workforce */
    private function workforceSources(): array
    {
        $out = [];
        $path = base_path('Modules/Hr/Workforce');

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[$file->getFilename()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }
}
