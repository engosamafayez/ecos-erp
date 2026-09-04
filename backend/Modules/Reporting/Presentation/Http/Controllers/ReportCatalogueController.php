<?php

declare(strict_types=1);

namespace Modules\Reporting\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Reporting\Application\Services\MetricRegistryService;
use Modules\Reporting\Application\Services\ReportCatalogueService;
use Modules\Reporting\Domain\Enums\ReportCategory;

/**
 * Reporting Platform Foundation (TASK-ECOS-REPORTING-PLATFORM-FOUNDATION-002) — minimum
 * route wiring to expose the Report Catalogue and Metric Dictionary as read-only platform
 * metadata (ADR-045 Decision 4; task requirement §8: "Do NOT build all 35 report endpoints
 * in this task").
 *
 * Read-only by construction: no `store`/`update`/`destroy` action exists, and none is
 * reachable from this controller — there is nothing here for one to mutate, since the
 * catalogue and dictionary are static, code-defined registries, not business-fact tables
 * (ADR-045 Decision 2). Gated on `auth:sanctum` only, not a `reports.*.view` permission:
 * this metadata describes *what categories and metrics exist*, the same kind of
 * cross-cutting, pre-authorization discoverability the IAM Permission catalog already
 * exposes on `iam.permissions.view` — but unlike that catalog, no category-specific
 * business figure is ever returned here, only names/formulas/authority metadata, so no
 * category permission is required to see the shape of the platform.
 */
final class ReportCatalogueController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly ReportCatalogueService $catalogue,
        private readonly MetricRegistryService $metrics,
    ) {}

    public function catalogue(Request $request): JsonResponse
    {
        $category = $this->resolveCategory($request);

        $reports = $category !== null
            ? $this->catalogue->byCategory($category)
            : $this->catalogue->all();

        return $this->success(['reports' => $reports, 'total' => count($reports)]);
    }

    public function metrics(Request $request): JsonResponse
    {
        $category = $this->resolveCategory($request);

        $metrics = $category !== null
            ? $this->metrics->byCategory($category)
            : $this->metrics->all();

        return $this->success(['metrics' => $metrics, 'total' => count($metrics)]);
    }

    private function resolveCategory(Request $request): ?ReportCategory
    {
        $raw = $request->query('category');

        return is_string($raw) ? ReportCategory::tryFrom($raw) : null;
    }
}
