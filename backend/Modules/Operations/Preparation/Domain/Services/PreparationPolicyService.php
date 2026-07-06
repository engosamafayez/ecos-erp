<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Domain\Services;

use App\Core\FeatureFlags\FeatureFlagService;

/**
 * Preparation Policy — module-level policy checks.
 *
 * CONTRACT: read-only, no DB writes, no events.
 */
final class PreparationPolicyService
{
    public function __construct(private readonly FeatureFlagService $flags) {}

    /** Whether the Preparation OS module is enabled for a company. */
    public function moduleEnabled(?string $companyId = null): bool
    {
        return $this->flags->isEnabled('modules.preparation_os', $companyId);
    }

    /** Whether the preparation workflow stage is enabled. */
    public function workflowEnabled(?string $companyId = null): bool
    {
        return $this->flags->isEnabled('workflow.preparation', $companyId);
    }

    /** Whether AI features are enabled. */
    public function aiEnabled(?string $companyId = null): bool
    {
        return $this->flags->isEnabled('ai.preparation', $companyId);
    }

    /** Whether analytics are enabled. */
    public function analyticsEnabled(?string $companyId = null): bool
    {
        return $this->flags->isEnabled('preparation.analytics', $companyId);
    }
}
