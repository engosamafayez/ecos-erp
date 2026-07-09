<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Preparation\Domain\Events\WaveCompleted;

/**
 * Advances orders from 'preparing' → 'ready_for_loading' when a wave completes.
 *
 * Only transitions orders that are still in 'preparing' — orders that have
 * already been cancelled or completed are left untouched.
 */
final class HandlePreparationWaveCompleted
{
    public function handle(WaveCompleted $event): void
    {
        $orderIds = DB::table('preparation_wave_orders')
            ->where('preparation_wave_id', $event->waveId)
            ->pluck('order_id')
            ->filter()
            ->unique()
            ->values();

        if ($orderIds->isEmpty()) {
            Log::info('[HandlePreparationWaveCompleted] Wave completed but no linked orders found', [
                'wave_id'      => $event->waveId,
                'wave_number'  => $event->waveNumber,
                'completed_at' => $event->completedAt,
            ]);

            return;
        }

        // Transition preparing orders → ready_for_loading in one query.
        $updated = DB::table('orders')
            ->whereIn('id', $orderIds->all())
            ->where('status', OrderStatus::Preparing->value)
            ->update([
                'status'                 => OrderStatus::ReadyForLoading->value,
                'preparation_completed_at' => now(),
                'updated_at'             => now(),
            ]);

        Log::info('[HandlePreparationWaveCompleted] Orders transitioned to ready_for_loading', [
            'wave_id'      => $event->waveId,
            'wave_number'  => $event->waveNumber,
            'order_count'  => $updated,
            'completed_by' => $event->completedBy,
        ]);
    }
}
