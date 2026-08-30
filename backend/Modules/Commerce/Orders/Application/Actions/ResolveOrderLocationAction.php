<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use Modules\Commerce\Orders\Application\Services\OrderGeocodingService;
use Modules\Commerce\Orders\Domain\Models\Order;

/**
 * Resolve an order's map point, and why — the single decision layer.
 *
 *   Priority 1  captured coordinates                 -> 'available'
 *   Priority 2  a COMPLETE address, geocoded         -> 'resolved_from_address'
 *   otherwise   not configured / failed / no address -> honest state, no point
 *
 * ┌─ NEVER A SUBSTITUTE POINT ───────────────────────────────────────────────┐
 * │ A city/governorate/zone/area centroid is NOT the customer's location, so   │
 * │ it is never used (rule §5). The address query requires a street/building    │
 * │ line ON TOP of the locality; a locality-only address is treated as         │
 * │ unavailable rather than dropped on a district centre someone would drive to.│
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * PERSISTENCE reuses the EXISTING location architecture (rule §8): a successful
 * geocode writes `orders.google_maps_lat/lng` and stamps `location_source =
 * 'geocoded'`, so the point behaves like any located order AND stays
 * distinguishable from an operator-captured one. Captured coordinates are never
 * overwritten — Priority 1 returns before any write.
 */
final class ResolveOrderLocationAction
{
    public function __construct(
        private readonly OrderGeocodingService $geocoder,
    ) {}

    /**
     * @return array{status: string, latitude: float|null, longitude: float|null, source: string|null, address: string|null}
     */
    public function execute(Order $order): array
    {
        // Priority 1 — captured coordinates win outright and cost no request.
        if ($order->google_maps_lat !== null && $order->google_maps_lng !== null) {
            return [
                'status' => 'available',
                'latitude' => (float) $order->google_maps_lat,
                'longitude' => (float) $order->google_maps_lng,
                'source' => $order->location_source ?? 'captured',
                'address' => null,
            ];
        }

        // Priority 2 — the COMPLETE delivery address, or nothing worth geocoding.
        $address = $this->completeAddress($order);

        if ($address === null) {
            return $this->unresolved('address_unavailable');
        }

        if (! $this->geocoder->isConfigured()) {
            return $this->unresolved('not_configured', $address);
        }

        $coords = $this->geocoder->geocode($address);

        if ($coords === null) {
            return $this->unresolved('geocoding_failed', $address);
        }

        // Persist into the existing location columns, stamped as geocoded.
        $order->forceFill([
            'google_maps_lat' => $coords['lat'],
            'google_maps_lng' => $coords['lng'],
            'location_source' => 'geocoded',
        ])->save();

        return [
            'status' => 'resolved_from_address',
            'latitude' => $coords['lat'],
            'longitude' => $coords['lng'],
            'source' => 'geocoded',
            'address' => $address,
        ];
    }

    /**
     * The full address a geocoder needs: a specific street/building line PLUS a
     * locality, narrow -> wide. Returns null when either half is missing.
     */
    private function completeAddress(Order $order, string $country = 'Egypt'): ?string
    {
        $specific = array_values(array_filter([
            $this->clean($order->shipping_address),
            $this->clean($order->building) === null ? null : 'Bldg '.$this->clean($order->building),
            $this->clean($order->apartment) === null ? null : 'Apt '.$this->clean($order->apartment),
        ]));

        $locality = array_values(array_filter([
            $this->clean($order->area),
            $this->clean($order->city),
            $this->clean($order->governorate),
        ]));

        if ($specific === [] || $locality === []) {
            return null;
        }

        return implode(', ', [...$specific, ...$locality, $country]);
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array{status: string, latitude: null, longitude: null, source: null, address: string|null}
     */
    private function unresolved(string $status, ?string $address = null): array
    {
        return [
            'status' => $status,
            'latitude' => null,
            'longitude' => null,
            'source' => null,
            'address' => $address,
        ];
    }
}
