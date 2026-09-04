<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;

/**
 * Shared date-range filter application (§9/§10/§11), used by every first-tranche handler
 * that accepts a `date_from`/`date_to` filter pair — kept in one place so every report
 * applies the exact same inclusive-bounds, Cairo-anchored semantics rather than each
 * handler reinventing it.
 *
 * Format/range validation itself happens in each handler's own `validateFilters()` via
 * Laravel's `Validator::make(['date_format:Y-m-d', 'after_or_equal:date_from', ...])` —
 * the same inline-validation convention `PreparationAnalyticsController::index()` already
 * uses — not here; this class only *applies* an already-validated pair of `Y-m-d` strings
 * (or nulls) to a query.
 *
 * Two distinct application methods on purpose (§10: "Do not use one generic date column
 * across unrelated reports"): a `date`-typed column (e.g. `orders.order_date`) has no time
 * component at all, so no timezone conversion is meaningful; a `timestamp`-typed column
 * (e.g. `distribution_delivery_stops.completed_at`) needs its business-day boundary fixed
 * to Africa/Cairo explicitly, regardless of `APP_TIMEZONE` (§11).
 */
final class ReportDateRange
{
    public function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
    ) {}

    public function toPeriod(): ReportPeriod
    {
        return new ReportPeriod($this->from, $this->to);
    }

    /**
     * Inclusive range on a plain `date` column. No timezone conversion applies — a DATE
     * column carries no time component to convert.
     *
     * Accepts either an Eloquent builder or a plain query builder — some handlers
     * deliberately query from the plain query builder to avoid an Eloquent model's own
     * global scope colliding with a joined table of the same column name (see
     * `SalesByDimensionQuery::lineLevelRows()`).
     *
     * @template TBuilder of EloquentBuilder<*>|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function applyToDateColumn(EloquentBuilder|QueryBuilder $query, string $column): EloquentBuilder|QueryBuilder
    {
        if ($this->from !== null) {
            $query->whereDate($column, '>=', $this->from);
        }

        if ($this->to !== null) {
            $query->whereDate($column, '<=', $this->to);
        }

        return $query;
    }

    /**
     * Inclusive range on a `timestamp` column, anchored to Cairo business-day boundaries
     * (§11) regardless of `APP_TIMEZONE`.
     *
     * @template TBuilder of EloquentBuilder<*>|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function applyToTimestampColumn(EloquentBuilder|QueryBuilder $query, string $column): EloquentBuilder|QueryBuilder
    {
        if ($this->from !== null) {
            $query->where($column, '>=', Carbon::parse($this->from, ReportPeriod::BUSINESS_TIMEZONE)->startOfDay());
        }

        if ($this->to !== null) {
            $query->where($column, '<=', Carbon::parse($this->to, ReportPeriod::BUSINESS_TIMEZONE)->endOfDay());
        }

        return $query;
    }
}
