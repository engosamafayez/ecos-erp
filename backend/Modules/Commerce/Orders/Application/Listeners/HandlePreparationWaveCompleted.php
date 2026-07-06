<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Listeners;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Preparation\Domain\Events\WaveCompleted;

/**
 * Updates order status after a preparation wave completes.
 *
 * Orders stay in 'preparing' at this point — they become 'completed'
 * only after loading and delivery. This listener is a placeholder that
 * could update to a 'ready_for_loading' status when that status is added.
 */
final class HandlePreparationWaveCompleted
{
    public function handle(WaveCompleted $event): void
    {
        // Orders remain in 'preparing' until loaded and dispatched.
        // When a 'ready_for_loading' status is introduced in Commerce module,
        // update this listener to set that status.
        // Currently a no-op that confirms the integration wire is in place.
    }
}
