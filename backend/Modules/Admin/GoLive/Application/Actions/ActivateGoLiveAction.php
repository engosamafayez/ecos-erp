<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Application\Actions;

use Illuminate\Support\Facades\Auth;
use Modules\Admin\GoLive\Domain\Enums\ResetOperationStatus;
use Modules\Admin\GoLive\Domain\Models\GoLiveResetOperation;
use Modules\Admin\GoLive\Domain\Services\CashBankOpeningReadiness;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Organization\Companies\Domain\Services\CompanyLifecycleAuthority;
use RuntimeException;

/**
 * TASK-...-026 §16/§17 — Go-Live activation. A distinct action from any reset step; completing
 * earlier wizard steps never marks a company Live by itself (§14).
 *
 * Readiness gate, all four required:
 *  1. No unfinished (status=executing) reset operation for this company.
 *  2. Every WooCommerce channel on this company has been through EXPLICIT first activation
 *     (Task 025-R1's `orders_sync_activated_at`) — "an explicit Woo cutover decision", not
 *     silently defaulted. A company with no channels at all has nothing to resolve.
 *  3. TASK-...-026-R1 Gate 4 — no unresolved Cash/Bank opening-balance gap. See
 *     {@see CashBankOpeningReadiness} for the exact, evidence-based condition; this never fires
 *     for a company that doesn't need it, and there is deliberately no code path that fabricates
 *     an opening posting to clear it — the operator must resolve it outside this action.
 *  4. Not already Live (idempotent-safe: re-activating an already-Live company is a no-op that
 *     returns the existing state rather than erroring, since retried requests must be safe).
 *
 * Audited via `companies.live_activated_at`/`live_activated_by` — a minimal, directly-queryable
 * audit trail on the row being changed; this is a one-way, one-time transition (no reversal
 * contract — §1), so a fuller timestamped log has less value here than it does for the
 * repeatable reset operation, which is why `golive_reset_operations` is not reused for this event.
 *
 * Never touches `channels.sync_orders` — activation must not implicitly change the Woo cutover
 * policy an operator already set explicitly (§16).
 */
final class ActivateGoLiveAction
{
    public function __construct(
        private readonly CompanyLifecycleAuthority $lifecycle,
        private readonly CashBankOpeningReadiness $cashBankReadiness,
    ) {}

    public function execute(string $companyId): Company
    {
        $company = Company::query()->findOrFail($companyId);

        if ($this->lifecycle->isLive($companyId)) {
            return $company; // already Live — idempotent no-op, not an error
        }

        $unfinished = GoLiveResetOperation::query()
            ->where('company_id', $companyId)
            ->where('status', ResetOperationStatus::Executing->value)
            ->exists();
        if ($unfinished) {
            throw new RuntimeException('Cannot activate Go-Live while a reset operation is still in progress.');
        }

        $unresolvedChannels = Channel::query()
            ->whereHas('brand', fn ($q) => $q->where('company_id', $companyId))
            ->whereNull('orders_sync_activated_at')
            ->count();
        if ($unresolvedChannels > 0) {
            throw new RuntimeException(
                "{$unresolvedChannels} WooCommerce channel(s) have not had their Orders Sync cutover explicitly resolved. "
                .'Set an initial import policy for each channel before activating Go-Live.',
            );
        }

        $cashBankBlockerMessage = $this->cashBankReadiness->missingPrerequisiteMessage($companyId);
        if ($cashBankBlockerMessage !== null) {
            throw new RuntimeException($cashBankBlockerMessage);
        }

        $company->update([
            'lifecycle_state' => 'live',
            'live_activated_at' => now(),
            'live_activated_by' => Auth::id(),
        ]);

        $this->lifecycle->forget($companyId);

        return $company->refresh();
    }
}
