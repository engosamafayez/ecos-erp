<?php

declare(strict_types=1);

namespace Modules\Inventory\CountSessions\Application\Actions;

use Modules\Inventory\CountSessions\Domain\Enums\CountSessionStatus;
use Modules\Inventory\CountSessions\Domain\Models\InventoryCountSession;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class StartCountSessionAction
{
    public function execute(InventoryCountSession $session): InventoryCountSession
    {
        if (! $session->status->canTransitionTo(CountSessionStatus::InProgress)) {
            throw new UnprocessableEntityHttpException(
                "Count session [{$session->count_number}] cannot be started from status [{$session->status->value}]."
            );
        }

        $session->update([
            'status'     => CountSessionStatus::InProgress,
            'started_at' => now(),
        ]);

        return $session->refresh();
    }
}
