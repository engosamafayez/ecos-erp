<?php

declare(strict_types=1);

namespace Modules\AI\Application\Tools;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\AI\Domain\Contracts\AIToolInterface;
use Modules\AI\Domain\Enums\AIToolClassification;
use Modules\AI\Domain\Enums\AIToolScope;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\AI\Domain\ValueObjects\AIToolResult;
use Modules\Reporting\Application\Exceptions\ReportNotExecutableException;
use Modules\Reporting\Application\Exceptions\UnknownReportException;
use Modules\Reporting\Application\Services\ReportExecutionService;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;

/**
 * §15 — executes ONE already-catalogued, already-registered report through the
 * exact same ReportExecutionService/ReportHandlerRegistry every human user's
 * Reporting UI calls. This tool never chooses which permission applies (the
 * report definition's own permission does, exactly as ReportExecutionService
 * already enforces) — it is a thin pass-through, not a second reporting engine.
 */
final class RunReportTool implements AIToolInterface
{
    public function __construct(private readonly ReportExecutionService $execution) {}

    public function name(): string
    {
        return 'run_report';
    }

    public function description(): string
    {
        return 'Runs one existing ECOS report by its id (see get_reports_catalogue) with optional filters and returns its KPIs/rows/totals.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'report_id' => ['type' => 'string', 'description' => 'Exact report id from the catalogue, e.g. "RPT-EXEC-01".'],
                'filters' => ['type' => 'object', 'description' => 'Optional report-specific filters, e.g. {"date_from": "2026-09-01", "date_to": "2026-09-30"}.'],
            ],
            'required' => ['report_id'],
        ];
    }

    public function permission(): string
    {
        // The report's OWN catalogued permission is what actually gates execution
        // (checked inside ReportExecutionService); this declared permission is only
        // the assistant entry re-check — matching how the real HTTP route behaves
        // (auth:sanctum only, dynamic per-report permission enforced in the service).
        return 'ai.assistant.use';
    }

    public function scope(): AIToolScope
    {
        return AIToolScope::Company;
    }

    public function classification(): AIToolClassification
    {
        return AIToolClassification::Read;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function execute(AIRequestContext $context, User $user, array $input): AIToolResult
    {
        $reportId = is_string($input['report_id'] ?? null) ? $input['report_id'] : null;

        if ($reportId === null || $reportId === '') {
            return AIToolResult::invalidInput('report_id is required.');
        }

        $filters = is_array($input['filters'] ?? null) ? $input['filters'] : [];
        $queryContext = new ReportQueryContext(companyId: $context->companyId, userId: (string) $context->userId);

        try {
            $result = $this->execution->execute($reportId, $user, $queryContext, $filters);
        } catch (UnknownReportException) {
            return AIToolResult::notFound("No report with id \"{$reportId}\" exists.");
        } catch (ReportNotExecutableException) {
            return AIToolResult::unavailable("Report \"{$reportId}\" is catalogued but not yet executable.");
        } catch (AuthorizationException) {
            return AIToolResult::denied("You don't have permission to run report \"{$reportId}\".");
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AIToolResult::invalidInput(implode(' ', $e->validator->errors()->all()));
        }

        return AIToolResult::success([
            'report_id' => $result->reportId,
            'kpis' => $result->kpis,
            'rows' => $result->rows,
            'totals' => $result->totals,
            'period' => $result->period->toArray(),
        ]);
    }
}
