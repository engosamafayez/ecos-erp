<?php

declare(strict_types=1);

namespace Modules\Reporting\Presentation\Http\Controllers;

use App\Core\Company\TenantOwnershipResolver;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Reporting\Application\Exceptions\ReportNotExecutableException;
use Modules\Reporting\Application\Exceptions\UnknownReportException;
use Modules\Reporting\Application\Services\ReportExecutionService;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;

/**
 * TASK-ECOS-REPORTING-QUERY-EXECUTION-AND-FIRST-REPORTS-003 §13 — the minimum read-only
 * execution surface. One generic, parameterized endpoint rather than 35 (or even 6)
 * hardcoded routes: the report id is a path parameter, resolved dynamically against the
 * catalogue/registry, so permission enforcement (which permission depends on which report
 * was requested) cannot be static route middleware and is performed inside
 * {@see ReportExecutionService} instead (§14) — see that class's own docblock for why this
 * is not a parallel ACL engine.
 *
 * Read-only by construction: the sole action is a GET-shaped query, and nothing here (or
 * reachable from here) writes to any table Reporting does not itself own (§11).
 */
final class ReportExecutionController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly ReportExecutionService $executionService,
        private readonly TenantOwnershipResolver $tenant,
    ) {}

    public function execute(Request $request, string $reportId): JsonResponse
    {
        $user = $request->user();

        // Tenant/company scope must never depend only on frontend input (§9) — resolved
        // here from the same authority Order's own global scope already trusts, never
        // from a request parameter. Reporting requires a definite, single company scope
        // for every execution regardless of an is_system/unrestricted actor's cross-
        // company privilege — no cross-company aggregation is approved anywhere in the
        // architecture, so a null company fails closed rather than guessing one.
        $companyId = $this->tenant->companyId();

        if ($companyId === null) {
            abort(403, 'Reporting requires a definite company context; none is available for this account.');
        }

        $context = new ReportQueryContext(
            companyId: $companyId,
            userId: (string) $user->id,
        );

        try {
            $result = $this->executionService->execute($reportId, $user, $context, $request->query());
        } catch (UnknownReportException $e) {
            abort(404, $e->getMessage());
        } catch (ReportNotExecutableException $e) {
            abort(501, $e->getMessage());
        }

        return $this->success($result->toArray());
    }
}
