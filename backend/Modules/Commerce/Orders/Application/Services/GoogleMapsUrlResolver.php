<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Services;

use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Server-side resolution of Google Maps location URLs into coordinates.
 *
 * Two URL shapes reach this service:
 *
 *   1. Short `maps.app.goo.gl` links carry NO coordinates in the URL itself — the
 *      lat/lng only appear after the link is followed to its canonical Maps URL,
 *      which needs a server-side HTTP redirect.
 *
 *   2. Full `google.com/maps` URLs already embed the coordinates in the path/query
 *      (`@lat,lng`, `!3d!4d`, `?q=`, `/maps/search/`). The frontend "Import Location"
 *      control can extract those client-side, but an operator who pastes a full URL
 *      WITHOUT importing (the ORD-00002 forensic case) would otherwise persist a URL
 *      with NULL lat/lng and the order shows "No GPS".
 *
 * This is the single authority for that resolution. Both create and update route
 * through {@see backfillCoordinates()}; a single {@see extractCoordinates()} parser
 * is shared by the offline (full URL) and online (short link) paths so the two can
 * never diverge.
 */
final class GoogleMapsUrlResolver
{
    /**
     * Back-fill google_maps_lat/lng when coordinates are absent but a URL is present.
     *
     * Short links are followed server-side to reveal their coordinates; full Maps
     * URLs already carry them and are parsed offline (no network). Coordinates the
     * caller already supplied are never overwritten (the early `isset` guard).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function backfillCoordinates(array $data): array
    {
        if (empty($data['google_maps_url']) || isset($data['google_maps_lat'])) {
            return $data;
        }

        $url = (string) $data['google_maps_url'];
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        $lat = $lng = null;

        if (str_contains($host, 'goo.gl')) {
            // Short link (maps.app.goo.gl / goo.gl/maps) — coordinates only exist
            // after following the redirect to the canonical URL. Needs the network.
            [$resolvedUrl, $lat, $lng] = $this->resolve($url);
            $data['google_maps_url'] = $resolvedUrl;
        } elseif (str_contains($host, 'google.') && str_contains(strtolower($url), '/maps')) {
            // Full Maps URL — coordinates are already embedded in the path/query.
            // Parse offline; this is the ORD-00002 case (place URL pasted without
            // clicking Import Location, so the client never extracted the pin).
            [$lat, $lng] = $this->extractCoordinates($url);
        } else {
            // Neither a short link nor a recognised Maps URL — leave untouched.
            return $data;
        }

        if ($lat !== null) {
            $data['google_maps_lat'] = $lat;
            $data['google_maps_lng'] = $lng;
            // Stamp provenance only when the caller has not already declared it.
            $data['location_source'] ??= 'google_maps';
        }

        return $data;
    }

    /**
     * Follow a Maps URL to its canonical form and extract coordinates from it.
     * Returns the (possibly redirected) URL plus lat/lng, or nulls when none can
     * be parsed. Network failure returns the original URL unchanged.
     *
     * @return array{0: string, 1: float|null, 2: float|null}
     */
    public function resolve(string $url): array
    {
        $finalUrl = $url;

        try {
            Http::withOptions([
                'allow_redirects' => ['max' => 10],
                'on_stats' => function (TransferStats $stats) use (&$finalUrl): void {
                    $finalUrl = (string) $stats->getEffectiveUri();
                },
            ])->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; ECOS/1.0)'])
                ->timeout(8)
                ->head($url);
        } catch (Throwable) {
            // Network or resolution failure — return the original URL unchanged.
        }

        [$lat, $lng] = $this->extractCoordinates($finalUrl);

        return [$finalUrl, $lat, $lng];
    }

    /**
     * Extract coordinates from a canonical Maps URL. Pure string parsing, no network.
     * Priority mirrors the frontend parser (google-maps-parser.ts) exactly so client
     * and server agree on which coordinate a URL yields.
     *
     * @return array{0: float|null, 1: float|null} [lat, lng]
     */
    private function extractCoordinates(string $url): array
    {
        $lat = $lng = null;

        // Priority 1: !3d<lat>!4d<lng> — the actual place-pin in Google Maps Place URLs.
        if (preg_match('/!3d(-?\d+\.?\d+)!4d(-?\d+\.?\d+)/', $url, $m)) {
            $candidate = [(float) $m[1], (float) $m[2]];
            if ($candidate[0] >= -90 && $candidate[0] <= 90 && $candidate[1] >= -180 && $candidate[1] <= 180) {
                [$lat, $lng] = $candidate;
            }
        }

        if ($lat === null) {
            if (preg_match('/@(-?\d+\.?\d*),(-?\d+\.?\d*)/', $url, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
            } elseif (preg_match('/[?&]q=(-?\d+\.?\d*)[,+](-?\d+\.?\d*)/', $url, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
            } elseif (preg_match('/\/maps\/search\/(-?\d+\.?\d*)[,+\s]+(-?\d+\.?\d*)/', $url, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
            }
        }

        return [$lat, $lng];
    }
}
