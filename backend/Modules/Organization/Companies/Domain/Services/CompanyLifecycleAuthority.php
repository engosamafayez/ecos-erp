<?php

declare(strict_types=1);

namespace Modules\Organization\Companies\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Organization\Companies\Domain\Enums\CompanyLifecycleState;

/**
 * "May destructive Pre-Live test-reset run for THIS company?" — the single Pre-Live/Live
 * authority (TASK-...-026 §1). Mirrors {@see \Modules\Inventory\InventoryItems\Domain\Services\GoodsInwardAuthority}
 * deliberately: read through the query builder rather than the Company model/global scopes, so
 * this is safe to consult from console commands, queue workers, and the reset execution itself
 * (which must re-check the state INSIDE its own transaction, not trust a value read before the
 * request started).
 *
 * FAILS SAFE (to PreLive), not closed, for the same reason GoodsInwardAuthority does: an unset or
 * unrecognised value must not silently brick an existing company out of its own admin tooling.
 * This is the ONLY place that resolves the lifecycle state for reset-eligibility purposes — no
 * other code should re-derive it from `companies.is_active` or any other column.
 */
final class CompanyLifecycleAuthority
{
    /** @var array<string, CompanyLifecycleState> */
    private array $cache = [];

    public function stateFor(string $companyId): CompanyLifecycleState
    {
        if ($companyId === '') {
            return CompanyLifecycleState::default();
        }

        if (! array_key_exists($companyId, $this->cache)) {
            $value = DB::table('companies')
                ->where('id', $companyId)
                ->value('lifecycle_state');

            $this->cache[$companyId] = CompanyLifecycleState::tryFromValue(
                $value === null ? null : (string) $value,
            );
        }

        return $this->cache[$companyId];
    }

    public function isLive(string $companyId): bool
    {
        return $this->stateFor($companyId) === CompanyLifecycleState::Live;
    }

    public function isPreLive(string $companyId): bool
    {
        return $this->stateFor($companyId) === CompanyLifecycleState::PreLive;
    }

    /** Needed only by ActivateGoLiveAction, which changes the value mid-request. */
    public function forget(string $companyId): void
    {
        unset($this->cache[$companyId]);
    }
}
