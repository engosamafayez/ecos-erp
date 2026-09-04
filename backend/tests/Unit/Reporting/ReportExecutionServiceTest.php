<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\Models\User;
use Closure;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;
use Modules\IAM\Domain\ValueObjects\AuthorizationDecision;
use Modules\Reporting\Application\Exceptions\ReportNotExecutableException;
use Modules\Reporting\Application\Exceptions\UnknownReportException;
use Modules\Reporting\Application\Services\ReportCatalogueService;
use Modules\Reporting\Application\Services\ReportExecutionService;
use Modules\Reporting\Application\Services\ReportHandlerRegistry;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;
use PHPUnit\Framework\TestCase;

/**
 * TASK-ECOS-REPORTING-QUERY-EXECUTION-AND-FIRST-REPORTS-003 §15-A — execution-service
 * orchestration. DB-free: `ReportCatalogueService` reads a static Catalog, the
 * authorization gateway and the acting user are mocked/bare-constructed, never persisted.
 */
final class ReportExecutionServiceTest extends TestCase
{
    public function test_unknown_report_id_is_rejected_before_anything_else_runs(): void
    {
        $service = $this->service(permissionDenied: false, handlers: []);

        $this->expectException(UnknownReportException::class);
        $service->execute('RPT-NOT-A-REAL-ID', new User, $this->context(), []);
    }

    public function test_authorized_known_report_with_a_registered_handler_executes(): void
    {
        // RPT-SALES-03 is a real, cataloged, is_v1 report id (Task 2 catalogue) with no
        // dependency on any of this task's own handlers being registered — used here only
        // as a real catalogue anchor, not to assert anything about its own query logic.
        $handler = self::stubHandler('RPT-SALES-03');
        $service = $this->service(permissionDenied: false, handlers: [$handler]);

        $result = $service->execute('RPT-SALES-03', new User, $this->context(), ['x' => '1']);

        $this->assertInstanceOf(ReportResult::class, $result);
        $this->assertSame('RPT-SALES-03', $result->reportId);
    }

    public function test_cataloged_report_with_no_registered_handler_is_rejected_cleanly(): void
    {
        // No handlers registered at all — RPT-SALES-03 is real in the catalogue but this
        // task has not (in this test) wired a handler for it.
        $service = $this->service(permissionDenied: false, handlers: []);

        $this->expectException(ReportNotExecutableException::class);
        $service->execute('RPT-SALES-03', new User, $this->context(), []);
    }

    public function test_permission_denied_is_rejected_before_handler_resolution(): void
    {
        // No handler registered for RPT-SALES-03 either — if permission is checked first
        // (as designed), the AuthorizationException must fire before ReportNotExecutableException
        // ever has a chance to.
        $service = $this->service(permissionDenied: true, handlers: []);

        $this->expectException(AuthorizationException::class);
        $service->execute('RPT-SALES-03', new User, $this->context(), []);
    }

    public function test_report_query_context_is_passed_through_unchanged_to_the_handler(): void
    {
        $seenContext = null;
        $handler = new class('RPT-SALES-03', function (ReportQueryContext $c) use (&$seenContext): void {
            $seenContext = $c;
        }) implements ReportHandlerInterface
        {

            public function __construct(private readonly string $id, private readonly Closure $onExecute) {}

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
                ($this->onExecute)($context);

                return new ReportResult($this->id, [], [], [], new ReportPeriod(null, null), $filters, new DateTimeImmutable);
            }
        };

        $context = $this->context('company-xyz', 'user-abc');
        $this->service(permissionDenied: false, handlers: [$handler])
            ->execute('RPT-SALES-03', new User, $context, []);

        $this->assertSame($context, $seenContext);
        $this->assertSame('company-xyz', $seenContext->companyId);
        $this->assertSame('user-abc', $seenContext->userId);
    }

    private function context(string $companyId = 'company-1', string $userId = 'user-1'): ReportQueryContext
    {
        return new ReportQueryContext($companyId, $userId);
    }

    /**
     * @param  list<ReportHandlerInterface>  $handlers
     */
    private function service(bool $permissionDenied, array $handlers): ReportExecutionService
    {
        // AuthorizationDecision is `final` — construct a real instance via its own named
        // factory rather than mocking it.
        $decision = $permissionDenied
            ? AuthorizationDecision::deny('test.permission')
            : AuthorizationDecision::allow('test.permission');

        $gateway = $this->createMock(AuthorizationGatewayInterface::class);
        $gateway->method('decision')->willReturn($decision);

        return new ReportExecutionService(
            new ReportCatalogueService,
            new ReportHandlerRegistry($handlers),
            $gateway,
        );
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
                return new ReportResult($this->id, [], [], [], new ReportPeriod(null, null), $filters, new DateTimeImmutable);
            }
        };
    }
}
