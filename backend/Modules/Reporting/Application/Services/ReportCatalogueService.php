<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Services;

use Modules\Reporting\Domain\Catalog\ReportCatalogue;
use Modules\Reporting\Domain\Enums\ReportCategory;

/**
 * Read-only accessor over {@see ReportCatalogue}. Never mutates, never queries a database
 * — the catalogue is a static, versioned, code-defined registry (ADR-045 Decision 4), not
 * a business-fact table.
 */
final class ReportCatalogueService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return ReportCatalogue::all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        foreach (ReportCatalogue::all() as $report) {
            if ($report['id'] === $id) {
                return $report;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function byCategory(ReportCategory $category): array
    {
        return array_values(array_filter(
            ReportCatalogue::all(),
            static fn (array $report): bool => $report['category'] === $category->value,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function v1Only(): array
    {
        return array_values(array_filter(
            ReportCatalogue::all(),
            static fn (array $report): bool => $report['is_v1'] === true,
        ));
    }
}
