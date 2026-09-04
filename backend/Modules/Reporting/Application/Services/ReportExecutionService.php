<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;
use Modules\Reporting\Application\Exceptions\UnknownReportException;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * The canonical application-layer entry point for executing one report (§3).
 *
 * Order of checks is deliberate: catalogue existence -> permission -> handler
 * resolution -> filter validation -> execution. Permission is checked before handler
 * resolution so an unauthorized caller learns nothing about which of the 35 catalogued
 * reports actually have a Task 3 handler wired up yet.
 *
 * Reuses the exact same {@see AuthorizationGatewayInterface::decision()} call
 * `Modules\IAM\Infrastructure\Middleware\RequirePermissionMiddleware` itself uses
 * (including the system-role bypass) — no parallel ACL engine (§14).
 */
final class ReportExecutionService
{
    public function __construct(
        private readonly ReportCatalogueService $catalogue,
        private readonly ReportHandlerRegistry $registry,
        private readonly AuthorizationGatewayInterface $authorization,
    ) {}

    /**
     * @param  array<string, mixed>  $rawFilters
     *
     * @throws UnknownReportException
     * @throws AuthorizationException
     * @throws \Modules\Reporting\Application\Exceptions\ReportNotExecutableException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function execute(string $reportId, User $user, ReportQueryContext $context, array $rawFilters): ReportResult
    {
        $definition = $this->catalogue->find($reportId);

        if ($definition === null || $definition['is_v1'] !== true) {
            throw UnknownReportException::forId($reportId);
        }

        if ($this->authorization->decision($user, $definition['permission'])->isDenied()) {
            throw new AuthorizationException("Permission denied: {$definition['permission']}");
        }

        $handler = $this->registry->resolve($reportId);

        $filters = $handler->validateFilters($rawFilters);

        return $handler->execute($context, $filters);
    }
}
