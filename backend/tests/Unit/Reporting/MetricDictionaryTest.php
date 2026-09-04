<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use Modules\Reporting\Domain\Catalog\MetricDictionary;
use Modules\Reporting\Domain\Enums\FreshnessClass;
use Modules\Reporting\Domain\Enums\MetricClassification;
use Modules\Reporting\Domain\Enums\ReportCategory;
use PHPUnit\Framework\TestCase;

/**
 * TASK-ECOS-REPORTING-PLATFORM-FOUNDATION-002 §10 — pure catalog-data assertions.
 * MetricDictionary::all() has no DB dependency, the same convention as
 * Modules\IAM\Domain\Catalog\RoleTemplateCatalog / RoleTemplateCatalogReconciliationTest.
 *
 * The exact set of Finance-authoritative (ACCOUNTING) metric ids is pinned explicitly
 * (test_finance_derived_metrics_remain_finance_authoritative /
 * test_operational_metrics_are_never_misclassified_as_accounting) against
 * ENTERPRISE-REPORTING-PLATFORM.md §17 — every "Classification: ACCOUNTING" line in that
 * document, transcribed once here so a future edit to MetricDictionary cannot silently
 * relabel a metric without this test catching it.
 */
final class MetricDictionaryTest extends TestCase
{
    private const EXPECTED_COUNT = 45;

    /** Every §17 entry whose "Classification:" line reads ACCOUNTING — MET-PROD-03/05/07, MET-FIN-01/02/03. */
    private const ACCOUNTING_IDS = [
        'MET-PROD-03', 'MET-PROD-05', 'MET-PROD-07',
        'MET-FIN-01', 'MET-FIN-02', 'MET-FIN-03',
    ];

    // ── catalogue loads deterministically ───────────────────────────────────────

    public function test_catalogue_loads_deterministically(): void
    {
        $first = MetricDictionary::all();
        $second = MetricDictionary::all();

        $this->assertSame($first, $second, 'MetricDictionary::all() must return byte-identical output on every call.');
        $this->assertCount(self::EXPECTED_COUNT, $first);
    }

    // ── metric identifiers are unique ───────────────────────────────────────────

    public function test_metric_identifiers_are_unique(): void
    {
        $ids = array_column(MetricDictionary::all(), 'id');

        $this->assertCount(self::EXPECTED_COUNT, $ids);
        $this->assertCount(self::EXPECTED_COUNT, array_unique($ids), 'Duplicate MET- identifier found in MetricDictionary.');
    }

    public function test_every_metric_id_matches_the_met_prefix_grammar(): void
    {
        foreach (MetricDictionary::all() as $metric) {
            $this->assertMatchesRegularExpression(
                '/^MET-[A-Z]+-\d{2}$/',
                $metric['id'],
                "Metric id '{$metric['id']}' does not match the MET-{CATEGORY}-{NN} grammar.",
            );
        }
    }

    // ── source/authority metadata is valid ──────────────────────────────────────

    public function test_source_authority_metadata_is_valid(): void
    {
        foreach (MetricDictionary::all() as $metric) {
            $this->assertNotEmpty($metric['source_modules'], "Metric '{$metric['id']}' has no source_modules — a future developer could query the wrong bounded context.");

            foreach ($metric['source_modules'] as $module) {
                $this->assertMatchesRegularExpression(
                    '/^Modules\\\\[A-Za-z]+(\\\\[A-Za-z]+)*$/',
                    $module,
                    "Metric '{$metric['id']}' names source module '{$module}', which is not a well-formed Modules\\... namespace.",
                );
            }

            $this->assertContains(
                $metric['category'],
                array_map(static fn (ReportCategory $c): string => $c->value, ReportCategory::cases()),
                "Metric '{$metric['id']}' has an unrecognised category '{$metric['category']}'.",
            );

            $this->assertContains(
                $metric['classification'],
                array_map(static fn (MetricClassification $c): string => $c->value, MetricClassification::cases()),
            );

            $this->assertContains(
                $metric['freshness'],
                array_map(static fn (FreshnessClass $c): string => $c->value, FreshnessClass::cases()),
            );
        }
    }

    // ── Finance-derived metrics remain marked Finance-authoritative ────────────

    public function test_finance_derived_metrics_remain_finance_authoritative(): void
    {
        $byId = self::indexById(MetricDictionary::all());

        foreach (self::ACCOUNTING_IDS as $id) {
            $this->assertArrayHasKey($id, $byId, "Expected accounting metric '{$id}' is missing from the dictionary.");
            $this->assertSame(
                MetricClassification::Accounting->value,
                $byId[$id]['classification'],
                "'{$id}' must be classified ACCOUNTING (Finance-authoritative) per ENTERPRISE-REPORTING-PLATFORM.md §17.",
            );
            $this->assertContains(
                'Modules\\Finance',
                $byId[$id]['source_modules'],
                "'{$id}' is classified ACCOUNTING but does not name Modules\\Finance as a source module.",
            );
        }
    }

    // ── operational metrics retain their source-module authority ──────────────

    public function test_operational_metrics_are_never_misclassified_as_accounting(): void
    {
        $byId = self::indexById(MetricDictionary::all());
        $accounting = array_flip(self::ACCOUNTING_IDS);

        foreach ($byId as $id => $metric) {
            if (isset($accounting[$id])) {
                continue;
            }

            $this->assertSame(
                MetricClassification::Operational->value,
                $metric['classification'],
                "'{$id}' is not in the pinned ACCOUNTING set but is classified '{$metric['classification']}' — either the pinned set or MetricDictionary is stale.",
            );
        }
    }

    /**
     * ADR-045 Decision 2b's core rule, at the data level: no Commerce/Orders-sourced
     * metric may ever be classified ACCOUNTING, and Finance's own metrics never claim
     * Commerce\Orders as a source — the two authorities never blend on one entry.
     */
    public function test_operational_sales_and_recognized_revenue_never_share_a_classification_by_accident(): void
    {
        $byId = self::indexById(MetricDictionary::all());

        $this->assertSame(MetricClassification::Operational->value, $byId['MET-SALES-01']['classification']);
        $this->assertSame(MetricClassification::Accounting->value, $byId['MET-FIN-01']['classification']);
        $this->assertNotSame($byId['MET-SALES-01']['classification'], $byId['MET-FIN-01']['classification']);

        foreach ($byId['MET-FIN-01']['source_modules'] as $module) {
            $this->assertNotSame('Modules\\Commerce\\Orders', $module, 'MET-FIN-01 (Recognized Revenue) must never name Commerce\\Orders as its own source — it is Finance-owned, proxied, never recomputed.');
        }
    }

    /**
     * Pins a representative sample of operational metrics to their exact owning module
     * per §5's Source Authority Matrix — not merely "not Finance," but the *correct*
     * bounded context, so a future developer cannot silently re-point e.g. a Driver
     * metric at Finance (ADR-045 §5: "Finance has zero code awareness of drivers —
     * this is a deliberate rule, not a gap").
     */
    public function test_operational_metrics_retain_their_exact_source_module_authority(): void
    {
        $byId = self::indexById(MetricDictionary::all());

        $expected = [
            'MET-SALES-01' => 'Modules\\Commerce\\Orders',
            'MET-CUST-01' => 'Modules\\Sales\\Customers',
            'MET-INV-01' => 'Modules\\Inventory\\InventoryItems',
            'MET-INV-03' => 'Modules\\CostManagement',
            'MET-PROC-01' => 'Modules\\Purchasing',
            'MET-PREP-01' => 'Modules\\Operations\\DemandAnalysis',
            'MET-DIST-01' => 'Modules\\Logistics\\Distribution',
            // Driver cash/expenses are financial in nature but Distribution-owned, never
            // Finance — the exact non-obvious boundary this bullet exists to guard.
            'MET-DRV-04' => 'Modules\\Logistics\\Distribution',
        ];

        foreach ($expected as $id => $module) {
            $this->assertContains($module, $byId[$id]['source_modules'], "'{$id}' must name '{$module}' as a source module.");
            $this->assertNotContains('Modules\\Finance', $byId[$id]['source_modules'], "'{$id}' is operational and must not name Modules\\Finance as its authority.");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $metrics
     * @return array<string, array<string, mixed>>
     */
    private static function indexById(array $metrics): array
    {
        $indexed = [];
        foreach ($metrics as $metric) {
            $indexed[$metric['id']] = $metric;
        }

        return $indexed;
    }
}
