<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\Auth;
use Modules\Commerce\Orders\Application\Services\CreateOrderSnapshotService;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;

/**
 * Applies a partial field update to an order.
 *
 * Used exclusively for inline grid edits (area, status, location).
 * Full order updates continue to use UpdateOrderAction.
 */
final class PatchOrderAction extends BaseAction
{
    private const ALLOWED = ['status', 'area', 'governorate', 'google_maps_lat', 'google_maps_lng'];

    /** Only confirm_order triggers the snapshot — the one moment of financial commitment. */
    private const SNAPSHOT_TRIGGER_STATUSES = ['confirm_order'];

    private const VALID_TRANSITIONS = [
        'pending'            => ['in_progress', 'cancelled'],
        'in_progress'        => ['processing', 'cancelled'],
        'processing'         => ['preparing', 'awaiting_payment', 'confirm_order', 'cancelled'],
        'preparing'          => ['ready_for_loading', 'confirm_order', 'completed', 'cancelled'],
        'ready_for_loading'  => ['out_for_delivery', 'completed', 'cancelled'],
        'awaiting_payment'   => ['confirm_order', 'cancelled'],
        'confirm_order'      => ['preparing', 'out_for_delivery', 'completed', 'cancelled'],
        'out_for_delivery'   => ['completed', 'returned', 'cancelled'],
        'returned'           => ['cancelled'],
        'completed'          => [],
        'cancelled'          => [],
    ];

    public function __construct(
        private readonly CreateOrderSnapshotService $snapshotService,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $id   = (string) $arguments[0];
        $data = (array)  $arguments[1];

        /** @var Order $order */
        $order = Order::where('id', $id)
            ->where('company_id', Auth::user()?->company_id)
            ->firstOrFail();
        $update = array_intersect_key($data, array_flip(self::ALLOWED));

        // Validate status transition before applying any update.
        if (isset($update['status'])) {
            $currentStatus = $order->status instanceof \BackedEnum
                ? $order->status->value
                : (string) $order->status;
            $newStatus = $update['status'] instanceof \BackedEnum
                ? $update['status']->value
                : (string) $update['status'];

            $allowed = self::VALID_TRANSITIONS[$currentStatus] ?? [];
            if (!in_array($newStatus, $allowed, true)) {
                abort(422, "Invalid status transition from '{$currentStatus}' to '{$newStatus}'.");
            }
        }

        // Capture old values before the update so we can log deltas.
        $oldValues = [];
        foreach (array_keys($update) as $field) {
            $oldValues[$field] = $order->getAttribute($field);
        }

        if (!empty($update)) {
            $order->update($update);
        }

        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        foreach ($update as $field => $newValue) {
            $oldValue = $oldValues[$field] ?? null;
            $oldStr   = $oldValue instanceof \BackedEnum ? $oldValue->value : $oldValue;
            $newStr   = $newValue instanceof \BackedEnum ? $newValue->value : $newValue;

            if ((string) $oldStr !== (string) ($newStr ?? '')) {
                OrderEvent::log(
                    $order->id,
                    'field_updated',
                    "Field '{$field}' updated.",
                    [
                        'field'     => $field,
                        'old_value' => $oldStr,
                        'new_value' => $newStr,
                    ],
                    $actorId,
                );
            }
        }

        // Trigger immutable financial snapshot on first committed status transition.
        if (isset($update['status'])) {
            $statusValue = $update['status'] instanceof \BackedEnum
                ? $update['status']->value
                : (string) $update['status'];

            if (in_array($statusValue, self::SNAPSHOT_TRIGGER_STATUSES, true)) {
                $this->snapshotService->createIfAbsent($order);
            }
        }

        $order->load(['customer', 'lines.product.unit', 'channel']);

        return OperationResult::success($order, 'Order updated.');
    }
}
