<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Enums;

/**
 * The 10 Reports workspace categories (ENTERPRISE-REPORTING-PLATFORM.md §4 Information
 * Architecture). One category-level IAM permission per case (§8) — Executive included,
 * even though it composes other categories' metrics rather than owning any of its own.
 */
enum ReportCategory: string
{
    case Executive = 'executive';
    case Sales = 'sales';
    case Customers = 'customers';
    case Products = 'products';
    case Inventory = 'inventory';
    case Procurement = 'procurement';
    case Preparation = 'preparation';
    case Distribution = 'distribution';
    case Drivers = 'drivers';
    case Financial = 'finance';

    /**
     * The category-level permission name, exact string per §8 / ADR-045 Decision 5.
     */
    public function permission(): string
    {
        return "reports.{$this->value}.view";
    }
}
