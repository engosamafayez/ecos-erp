<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use DateTimeImmutable;
use Modules\Reporting\Application\Exceptions\DuplicateReportHandlerException;
use Modules\Reporting\Application\Exceptions\ReportNotExecutableException;
use Modules\Reporting\Application\Services\ReportHandlerRegistry;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;
use PHPUnit\Framework\TestCase;

/**
 * TASK-ECOS-REPORTING-QUERY-EXECUTION-AND-FIRST-REPORTS-003 §15-A — execution framework.
 * Pure, DB-free unit tests using minimal stub handlers, the same convention as Task 2's
 * catalogue tests.
 */
final class ReportHandlerRegistryTest extends TestCase
{
    public function test_known_report_resolves(): void
    {
        $handler = self::stubHandler('RPT-TEST-01');
        $registry = new ReportHandlerRegistry([$handler]);

        $this->assertTrue($registry->has('RPT-TEST-01'));
        $this->assertSame($handler, $registry->resolve('RPT-TEST-01'));
        $this->assertSame(['RPT-TEST-01'], $registry->registeredReportIds());
    }

    public function test_unknown_report_rejected(): void
    {
        $registry = new ReportHandlerRegistry([self::stubHandler('RPT-TEST-01')]);

        $this->assertFalse($registry->has('RPT-DOES-NOT-EXIST'));

        $this->expectException(ReportNotExecutableException::class);
        $registry->resolve('RPT-DOES-NOT-EXIST');
    }

    public function test_duplicate_handler_registration_rejected(): void
    {
        $this->expectException(DuplicateReportHandlerException::class);
        $this->expectExceptionMessage('RPT-TEST-01');

        new ReportHandlerRegistry([
            self::stubHandler('RPT-TEST-01'),
            self::stubHandler('RPT-TEST-01'),
        ]);
    }

    public function test_registry_construction_is_all_or_nothing_on_duplicate(): void
    {
        // A duplicate anywhere in the list must fail the whole registry at construction
        // time (deterministic, §4) — not silently keep the first N-1 valid handlers.
        try {
            new ReportHandlerRegistry([
                self::stubHandler('RPT-TEST-01'),
                self::stubHandler('RPT-TEST-02'),
                self::stubHandler('RPT-TEST-01'),
            ]);
            $this->fail('Expected DuplicateReportHandlerException was not thrown.');
        } catch (DuplicateReportHandlerException) {
            $this->addToAssertionCount(1);
        }
    }

    private static function stubHandler(string $reportId): ReportHandlerInterface
    {
        return new class($reportId) implements ReportHandlerInterface
        {
            public function __construct(private readonly string $id) {}

            public function reportId(): string
            {
                return $this->id;
            }

            public function validateFilters(array $rawFilters): array
            {
                return $rawFilters;
            }

            public function execute(ReportQueryContext $context, array $filters): ReportResult
            {
                return new ReportResult(
                    reportId: $this->id,
                    kpis: [],
                    rows: [],
                    totals: [],
                    period: new \Modules\Reporting\Domain\ValueObjects\ReportPeriod(null, null),
                    appliedFilters: $filters,
                    generatedAt: new DateTimeImmutable,
                );
            }
        };
    }
}
