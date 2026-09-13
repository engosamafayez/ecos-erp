<?php

declare(strict_types=1);

namespace Modules\AI\Application\Tools;

use App\Models\User;
use Modules\AI\Domain\Contracts\AIToolInterface;
use Modules\AI\Domain\Enums\AIToolClassification;
use Modules\AI\Domain\Enums\AIToolScope;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\AI\Domain\ValueObjects\AIToolResult;
use Modules\Reporting\Application\Services\ReportCatalogueService;
use Modules\Reporting\Domain\Enums\ReportCategory;

/**
 * §15 — thin wrapper over the existing, already-authoritative Report Catalogue.
 * Platform metadata only (names/categories/permissions), matching
 * ReportCatalogueController's own "no category permission required to see the
 * shape of the platform" precedent — so this tool's own permission is simply
 * the assistant entry gate, re-checked, not a separate business grant.
 */
final class GetReportsCatalogueTool implements AIToolInterface
{
    public function __construct(private readonly ReportCatalogueService $catalogue) {}

    public function name(): string
    {
        return 'get_reports_catalogue';
    }

    public function description(): string
    {
        return 'Lists the reports available in ECOS, optionally filtered by category, so the assistant knows which run_report calls are possible.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => ['type' => 'string', 'description' => 'Optional category filter, e.g. "sales", "finance", "inventory".'],
            ],
        ];
    }

    public function permission(): string
    {
        return 'ai.assistant.use';
    }

    public function scope(): AIToolScope
    {
        return AIToolScope::None;
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
        $category = is_string($input['category'] ?? null) ? ReportCategory::tryFrom($input['category']) : null;

        $reports = $category !== null ? $this->catalogue->byCategory($category) : $this->catalogue->all();

        $rows = array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'name' => $r['name'],
            'category' => $r['category'],
            'permission' => $r['permission'],
        ], $reports);

        return AIToolResult::success(['reports' => $rows]);
    }
}
