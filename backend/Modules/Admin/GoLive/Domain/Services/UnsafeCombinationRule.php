<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Domain\Services;

use Modules\Admin\GoLive\Domain\Enums\ResetDomain;

/**
 * TASK-...-026 §6 — the unsafe-combination check, extracted as a pure function (no DB, no
 * container) so it can be proven correct in isolation. {@see \Modules\Admin\GoLive\Application\Actions\PreviewGoLiveResetAction}
 * resolves `$remainingOrders` from the DB and passes it in; this class only ever reasons about
 * the numbers and selections it is given.
 *
 * The rule: clearing Inventory or Finance transactional history while Orders that reference it
 * are deliberately being KEPT (Commerce not also selected, and the company still has Orders)
 * would leave those surviving Orders with no reservation/shipment/posting trail — the exact §6
 * examples ("clear inventory state while preserving incompatible movement/valuation facts",
 * "remove Finance postings while leaving dependent balances that derive from them"). Inventory
 * and Finance's own link to Orders is polymorphic, not a real FK (confirmed by reading their
 * migrations directly), so the DB itself will not stop this combination — only this check does.
 */
final class UnsafeCombinationRule
{
    /**
     * @param  list<ResetDomain>  $selected
     * @return list<string> empty = safe
     */
    public static function evaluate(array $selected, int $remainingOrders): array
    {
        $blockers = [];
        $hasCommerce = in_array(ResetDomain::Commerce, $selected, true);

        if (! $hasCommerce && $remainingOrders > 0) {
            if (in_array(ResetDomain::Inventory, $selected, true)) {
                $blockers[] = "Cannot reset Inventory transactions while {$remainingOrders} Order(s) are kept — select Commerce too, or deselect Inventory.";
            }
            if (in_array(ResetDomain::Finance, $selected, true)) {
                $blockers[] = "Cannot reset Finance transactions while {$remainingOrders} Order(s) are kept — select Commerce too, or deselect Finance.";
            }
        }

        return $blockers;
    }
}
