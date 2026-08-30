<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Server-side Google Geocoding — turns a COMPLETE delivery address into a point.
 *
 * ┌─ WHY SERVER-SIDE ────────────────────────────────────────────────────────┐
 * │ The key is read from config (`services.google_maps.key`, backed by env     │
 * │ `GOOGLE_MAPS_API_KEY`) and never leaves the backend. The frontend calls an  │
 * │ ECOS endpoint; the key is not exposed to the browser.                       │
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * WHAT IS SENT: only the address string and the key. No customer name, phone,
 * order id, or any other order data is transmitted to Google.
 *
 * FAILS GRACEFULLY: a missing key is reported via {@see isConfigured()} (the caller
 * renders a "not configured" state); a network error, a non-OK Google status, or
 * zero results returns null (the caller renders "geocoding failed"). This service
 * never throws to the caller and never invents a coordinate.
 */
final class OrderGeocodingService
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /** True when a Google Geocoding key is configured. */
    public function isConfigured(): bool
    {
        return trim((string) config('services.google_maps.key', '')) !== '';
    }

    /**
     * Resolve a complete address to coordinates, or null when it cannot be resolved.
     *
     * Returns null (never a guess) on: not configured, network failure, a Google
     * status other than OK, or zero results. Only the first, highest-confidence
     * result's geometry is used.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(string $address): ?array
    {
        $key = trim((string) config('services.google_maps.key', ''));

        if ($key === '' || trim($address) === '') {
            return null;
        }

        try {
            // Only the address and the key are transmitted (rule §6/§7).
            $response = Http::timeout(8)->get(self::ENDPOINT, [
                'address' => $address,
                'key' => $key,
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();

        // Google returns a top-level "status"; anything but OK (ZERO_RESULTS,
        // OVER_QUERY_LIMIT, REQUEST_DENIED, …) means we have no trustworthy point.
        if (! is_array($body) || ($body['status'] ?? null) !== 'OK') {
            return null;
        }

        $location = $body['results'][0]['geometry']['location'] ?? null;

        if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
            return null;
        }

        $lat = (float) $location['lat'];
        $lng = (float) $location['lng'];

        // Sanity bounds — a valid coordinate or nothing.
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }
}
