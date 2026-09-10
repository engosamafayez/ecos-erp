<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Application\DTO;

/**
 * TASK-...-026 §5 — the read-only Preview result. Constructing this DTO must never have mutated
 * anything; see PreviewGoLiveResetAction.
 */
final class GoLiveResetPreviewDTO
{
    /**
     * @param  list<string>  $selectedDomains
     * @param  array<string, array<string, int>>  $counts  domain => {table => count}
     * @param  list<string>  $blockers  empty = safe to execute
     * @param  array<string, mixed>  $wooCutover
     */
    public function __construct(
        public readonly string $companyId,
        public readonly array $selectedDomains,
        public readonly array $counts,
        public readonly array $blockers,
        public readonly array $wooCutover,
        public readonly string $lifecycleState,
    ) {}

    public function isSafe(): bool
    {
        return $this->blockers === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'company_id' => $this->companyId,
            'selected_domains' => $this->selectedDomains,
            'counts' => $this->counts,
            'blockers' => $this->blockers,
            'is_safe' => $this->isSafe(),
            'woo_cutover' => $this->wooCutover,
            'lifecycle_state' => $this->lifecycleState,
        ];
    }
}
