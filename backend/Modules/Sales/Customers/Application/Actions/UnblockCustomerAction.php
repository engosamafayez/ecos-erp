<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Sales\Customers\Domain\Models\CustomerBlock;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009 (§28).
 *
 * Marks one block episode inactive and records the unblock. Deliberately does
 * NOTHING else: it does not touch any Order. §28 is explicit that unblocking
 * "MUST NOT automatically release every existing ON HOLD Order" — those require
 * an explicit per-Order decision (OverrideOrderBlockAction, or an operator
 * manually resuming an Order once ProcessOrderWorkflow's guard finds no active
 * block left). Clearing `is_active` also frees the row's `active_phone_key`
 * (see the creating migration), so the same phone can be blocked again later.
 */
final class UnblockCustomerAction extends BaseAction
{
    /**
     * @param  mixed  ...$arguments  [0] companyId, [1] blockId, [2] reason, [3] ?actorId
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $companyId = (string) $arguments[0];
        $blockId = (string) $arguments[1];
        $reason = trim((string) $arguments[2]);
        $actorId = $arguments[3] ?? null;

        $block = CustomerBlock::query()
            ->where('id', $blockId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->firstOrFail();

        $block->update([
            'is_active' => false,
            'unblock_reason' => $reason,
            'unblocked_by' => $actorId,
            'unblocked_at' => now(),
        ]);

        return OperationResult::success($block, 'Customer/phone unblocked.');
    }
}
