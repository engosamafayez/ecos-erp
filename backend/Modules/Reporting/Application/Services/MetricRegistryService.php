<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Services;

use Modules\Reporting\Domain\Catalog\MetricDictionary;
use Modules\Reporting\Domain\Enums\MetricClassification;
use Modules\Reporting\Domain\Enums\ReportCategory;

/**
 * Read-only accessor over {@see MetricDictionary}. Never mutates, never queries a database
 * — the dictionary is a static, versioned, code-defined catalogue (ADR-045 Decision 4:
 * "the single definition authority"), not a business-fact table.
 */
final class MetricRegistryService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return MetricDictionary::all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        foreach (MetricDictionary::all() as $metric) {
            if ($metric['id'] === $id) {
                return $metric;
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
            MetricDictionary::all(),
            static fn (array $metric): bool => $metric['category'] === $category->value,
        ));
    }

    /**
     * Metrics whose system of record is Finance's own General Ledger — never Reporting's
     * own computation (ADR-045 Decision 6).
     *
     * @return list<array<string, mixed>>
     */
    public function financeAuthoritative(): array
    {
        return array_values(array_filter(
            MetricDictionary::all(),
            static fn (array $metric): bool => $metric['classification'] === MetricClassification::Accounting->value,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function operational(): array
    {
        return array_values(array_filter(
            MetricDictionary::all(),
            static fn (array $metric): bool => $metric['classification'] === MetricClassification::Operational->value,
        ));
    }
}
