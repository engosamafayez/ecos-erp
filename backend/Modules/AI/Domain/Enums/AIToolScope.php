<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Enums;

/**
 * Declares what {@see \Modules\AI\Application\Services\AIToolInvoker} must
 * additionally validate before a tool executes, beyond the domain permission
 * check every tool already gets.
 */
enum AIToolScope: string
{
    /** Result is bounded to the current company; no cross-company data. */
    case Company = 'company';

    /** Company-bounded AND the entity's brand_id must match a validated Brand context. */
    case Brand = 'brand';

    /** Not tied to company/tenant data at all (e.g. platform metadata). */
    case None = 'none';
}
