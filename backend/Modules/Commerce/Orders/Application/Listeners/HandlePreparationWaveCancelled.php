<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Listeners;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Preparation\Domain\Events\WaveCancelled;

/**
 * Returns orders to 'processing' status when a preparation wave is cancelled.
 * Triggered by preparation.wave.cancelled (WaveCancelled domain event).
 */
final class HandlePreparationWaveCancelled
{
    public function handle(WaveCancelled $event): void
    {
        if (empty($event->orderIds)) {
            return;
        }

        DB::table('orders')
            ->whereIn('id', $event->orderIds)
            ->where('status', 'preparing')
            ->update([
                'status'     => 'processing',
                'updated_at' => now(),
            ]);
    }
}
