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
    private const ALLOWED = ['status', 'area', 'city', 'governorate', 'google_maps_lat', 'google_maps_lng', 'google_maps_url'];

    /** Only confirmed triggers the snapshot — the one moment of financial commitment. */
    private const SNAPSHOT_TRIGGER_STATUSES = ['confirmed'];

    private const VALID_TRANSITIONS = [
        'pending'            => ['in_progress', 'cancelled'],
        'in_progress'        => ['processing', 'cancelled'],
        'processing'         => ['preparing', 'awaiting_payment', 'confirmed', 'cancelled'],
        'preparing'          => ['ready_for_loading', 'confirmed', 'completed', 'cancelled'],
        'ready_for_loading'  => ['out_for_delivery', 'completed', 'cancelled'],
        'awaiting_payment'   => ['confirmed', 'cancelled'],
        'confirmed'          => ['preparing', 'out_for_delivery', 'completed', 'cancelled'],
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

        // Auto-resolve short Google Maps URLs and extract coordinates server-side.
        if (!empty($update['google_maps_url']) && !isset($update['google_maps_lat'])) {
            $urlHost = strtolower((string) parse_url($update['google_maps_url'], PHP_URL_HOST));
            if (str_contains($urlHost, 'maps.app.goo.gl')) {
                [$resolvedUrl, $lat, $lng] = $this->resolveShortMapsUrl($update['google_maps_url']);
                $update['google_maps_url'] = $resolvedUrl;
                if ($lat !== null) {
                    $update['google_maps_lat'] = $lat;
                    $update['google_maps_lng'] = $lng;
                }
            }
        }

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
        $previousStatus = $order->status instanceof \BackedEnum ? $order->status->value : (string) $order->status;
        $oldValues = [];
        foreach (array_keys($update) as $field) {
            $oldValues[$field] = $order->getAttribute($field);
        }

        if (!empty($update)) {
            $order->update($update);
        }

        // Stamp status transition audit fields when status actually changed
        $actorId    = Auth::id() !== null ? (string) Auth::id() : null;
        $actorName  = Auth::user()?->name ?? null;
        $actorEmail = Auth::user()?->email ?? null;
        $actorRole  = Auth::user()?->roles()->value('name');
        $reason     = $data['reason'] ?? null;

        if (isset($update['status'])) {
            $newStatusVal = $update['status'] instanceof \BackedEnum
                ? $update['status']->value
                : (string) $update['status'];

            if ($newStatusVal !== $previousStatus) {
                $order->update([
                    'previous_status'   => $previousStatus,
                    'status_entered_by' => $actorName,
                    'status_entered_at' => now(),
                ]);
            }
        }

        foreach ($update as $field => $newValue) {
            $oldValue = $oldValues[$field] ?? null;
            $oldStr   = $oldValue instanceof \BackedEnum ? $oldValue->value : $oldValue;
            $newStr   = $newValue instanceof \BackedEnum ? $newValue->value : $newValue;

            if ((string) $oldStr !== (string) ($newStr ?? '')) {
                // Status transitions get their own event type for richer frontend rendering.
                $eventType   = $field === 'status' ? 'status_changed' : 'field_updated';
                $description = $field === 'status'
                    ? "Status changed from '{$oldStr}' to '{$newStr}'."
                    : "Field '{$field}' updated.";

                OrderEvent::log(
                    orderId:       $order->id,
                    type:          $eventType,
                    description:   $description,
                    payload:       ['field' => $field, 'old_value' => $oldStr, 'new_value' => $newStr],
                    actorId:       $actorId,
                    actorName:     $actorName,
                    previousValue: [$field => $oldStr],
                    newValue:      [$field => $newStr],
                    actionType:    $field === 'status' ? 'workflow' : 'updated',
                    reason:        $field === 'status' ? $reason : null,
                    actorRole:     $actorRole,
                    actorEmail:    $actorEmail,
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

    /** @return array{string, float|null, float|null} */
    private function resolveShortMapsUrl(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS      => 10,
            \CURLOPT_NOBODY         => true,
            \CURLOPT_TIMEOUT        => 8,
            \CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ECOS/1.0)',
            \CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_exec($ch);
        $finalUrl = curl_getinfo($ch, \CURLINFO_EFFECTIVE_URL) ?: $url;
        curl_close($ch);

        $lat = $lng = null;
        if (preg_match('/@(-?\d+\.?\d*),(-?\d+\.?\d*)/', $finalUrl, $m)) {
            $lat = (float) $m[1];
            $lng = (float) $m[2];
        } elseif (preg_match('/[?&]q=(-?\d+\.?\d*)[,+](-?\d+\.?\d*)/', $finalUrl, $m)) {
            $lat = (float) $m[1];
            $lng = (float) $m[2];
        } elseif (preg_match('/\/maps\/search\/(-?\d+\.?\d*)[,+\s]+(-?\d+\.?\d*)/', $finalUrl, $m)) {
            $lat = (float) $m[1];
            $lng = (float) $m[2];
        }

        return [$finalUrl, $lat, $lng];
    }
}
