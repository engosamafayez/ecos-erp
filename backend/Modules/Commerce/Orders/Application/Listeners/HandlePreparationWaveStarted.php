<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Listeners;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Preparation\Domain\Events\WaveStarted;

/**
 * Updates all orders in a started wave to status = 'preparing'.
 * Triggered by preparation.wave.started (WaveStarted domain event).
 */
final class HandlePreparationWaveStarted
{
    public function handle(WaveStarted $event): void
    {
        if (empty($event->orderIds)) {
            return;
        }

        // company_id guard prevents a wave from one tenant updating orders that
        // happen to share the same UUID with another tenant's orders.
        DB::table('orders')
            ->where('company_id', $event->companyId)
            ->whereIn('id', $event->orderIds)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->update([
                'status'     => 'preparing',
                'updated_at' => now(),
            ]);
    }
}
