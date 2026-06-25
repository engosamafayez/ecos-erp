<?php

declare(strict_types=1);

namespace Modules\Inventory\CountSessions\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\CountSessions\Domain\Enums\CountSessionStatus;
use Modules\Inventory\CountSessions\Domain\Models\InventoryCountLine;
use Modules\Inventory\CountSessions\Domain\Models\InventoryCountSession;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class CompleteCountSessionAction
{
    public function execute(InventoryCountSession $session): InventoryCountSession
    {
        if (! $session->status->canTransitionTo(CountSessionStatus::Completed)) {
            throw new UnprocessableEntityHttpException(
                "Count session [{$session->count_number}] cannot be completed from status [{$session->status->value}]."
            );
        }

        return DB::transaction(function () use ($session): InventoryCountSession {
            $session->loadMissing('lines');

            // Calculate variance per line and store computed variance_value
            foreach ($session->lines as $line) {
                /** @var InventoryCountLine $line */
                if ($line->counted_qty === null) {
                    continue;
                }

                $varianceQty = round((float) $line->counted_qty - (float) $line->system_qty, 4);

                // Variance value uses average_cost as a proxy (product must be loaded)
                $line->loadMissing('product');
                $avgCost      = (float) ($line->product?->average_cost ?? 0);
                $varianceValue = round($varianceQty * $avgCost, 2);

                $line->update([
                    'variance_qty'   => $varianceQty,
                    'variance_value' => $varianceValue,
                ]);
            }

            $session->update([
                'status'       => CountSessionStatus::Completed,
                'completed_at' => now(),
            ]);

            return $session->refresh();
        });
    }
}
