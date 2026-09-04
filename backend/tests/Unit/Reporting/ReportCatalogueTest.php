<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use Modules\Reporting\Domain\Catalog\MetricDictionary;
use Modules\Reporting\Domain\Catalog\ReportCatalogue;
use Modules\Reporting\Domain\Enums\GapClassification;
use Modules\Reporting\Domain\Enums\ReadStrategy;
use Modules\Reporting\Domain\Enums\ReportCategory;
use PHPUnit\Framework\TestCase;

/**
 * TASK-ECOS-REPORTING-PLATFORM-FOUNDATION-002 §10 — pure catalog-data assertions.
 * ReportCatalogue::all() has no DB dependency, the same convention as
 * Modules\IAM\Domain\Catalog\RoleTemplateCatalog / RoleTemplateCatalogReconciliationTest.
 */
final class ReportCatalogueTest extends TestCase
{
    private const EXPECTED_COUNT = 35;

    // ── catalogue loads deterministically ───────────────────────────────────────

    public function test_catalogue_loads_deterministically(): void
    {
        $first = ReportCatalogue::all();
        $second = ReportCatalogue::all();

        $this->assertSame($first, $second, 'ReportCatalogue::all() must return byte-identical output on every call.');
        $this->assertCount(self::EXPECTED_COUNT, $first);
    }

    // ── approved report identifiers are unique ──────────────────────────────────

    public function test_report_identifiers_are_unique(): void
    {
        $ids = array_column(ReportCatalogue::all(), 'id');

        $this->assertCount(self::EXPECTED_COUNT, $ids);
        $this->assertCount(self::EXPECTED_COUNT, array_unique($ids), 'Duplicate RPT- identifier found in ReportCatalogue.');
    }

    public function test_every_report_id_matches_the_rpt_prefix_grammar(): void
    {
        foreach (ReportCatalogue::all() as $report) {
            $this->assertMatchesRegularExpression(
                '/^RPT-[A-Z]+-\d{2}$/',
                $report['id'],
                "Report id '{$report['id']}' does not match the RPT-{CATEGORY}-{NN} grammar.",
            );
        }
    }

    // ── source/authority metadata is valid ──────────────────────────────────────

    public function test_source_authority_metadata_is_valid(): void
    {
        foreach (ReportCatalogue::all() as $report) {
            $this->assertNotEmpty($report['source_modules'], "Report '{$report['id']}' has no source_modules — a future developer could query the wrong bounded context.");

            foreach ($report['source_modules'] as $module) {
                $this->assertMatchesRegularExpression(
                    '/^Modules\\\\[A-Za-z]+(\\\\[A-Za-z]+)*$/',
                    $module,
                    "Report '{$report['id']}' names source module '{$module}', which is not a well-formed Modules\\... namespace.",
                );
            }

            $this->assertContains($report['category'], array_map(static fn (ReportCategory $c): string => $c->value, ReportCategory::cases()));
            $this->assertContains($report['read_strategy'], array_map(static fn (ReadStrategy $s): string => $s->value, ReadStrategy::cases()));
            $this->assertContains($report['gap_classification'], array_map(static fn (GapClassification $g): string => $g->value, GapClassification::cases()));
            $this->assertIsBool($report['is_v1']);
        }
    }

    /**
     * ADR-045 Decision 5 / §8: every report's permission is its own category's
     * `reports.{category}.view` — never a different category's, and never a bespoke
     * per-report permission (the coarser, category-level model was the adopted option).
     */
    public function test_every_report_permission_matches_its_own_category(): void
    {
        foreach (ReportCatalogue::all() as $report) {
            $category = ReportCategory::from($report['category']);

            $this->assertSame(
                $category->permission(),
                $report['permission'],
                "Report '{$report['id']}' (category '{$report['category']}') carries permission '{$report['permission']}', expected '{$category->permission()}'.",
            );
        }
    }

    /**
     * Every metric_id a report cites must resolve to a real MetricDictionary entry —
     * catches a typo'd or invented metric reference at the data level, not just at
     * runtime.
     */
    public function test_every_cited_metric_id_resolves_to_a_real_metric(): void
    {
        $knownMetricIds = array_flip(array_column(MetricDictionary::all(), 'id'));

        foreach (ReportCatalogue::all() as $report) {
            foreach ($report['metric_ids'] as $metricId) {
                $this->assertArrayHasKey(
                    $metricId,
                    $knownMetricIds,
                    "Report '{$report['id']}' cites metric '{$metricId}', which does not exist in MetricDictionary — task requirement: do not invent report definitions not present in the approved architecture.",
                );
            }
        }
    }

    public function test_all_v1_reports_are_flagged_is_v1(): void
    {
        // §18's ratified catalogue lists every one of these 35 entries as "V1." — none
        // is a deferred/LATER entry (those live only in the separate "Later Reports"
        // table, §18, and are deliberately not represented as ReportCatalogue rows).
        foreach (ReportCatalogue::all() as $report) {
            $this->assertTrue($report['is_v1'], "Report '{$report['id']}' is in the V1 catalogue but not flagged is_v1.");
        }
    }

    /**
     * RPT-FIN-04 is the architecture's single canonical entry for the Customer/Supplier
     * Statement feature (§18: "listed once here as the canonical Financial-category
     * entry, cross-linked from Customers/Procurement") — it must not also appear as a
     * separate Customers- or Procurement-category row, which would double-count one
     * report as three catalogue entries.
     */
    public function test_customer_supplier_statement_is_not_double_counted_across_categories(): void
    {
        $ids = array_column(ReportCatalogue::all(), 'id');

        $this->assertContains('RPT-FIN-04', $ids);
        $this->assertNotContains('RPT-CUST-04', $ids, 'Customer Statement must not exist as a separate Customers-category row — it is RPT-FIN-04, cross-linked.');
        $this->assertNotContains('RPT-PROC-04', $ids, 'Supplier Statement must not exist as a separate Procurement-category row — it is RPT-FIN-04, cross-linked.');
    }
}
