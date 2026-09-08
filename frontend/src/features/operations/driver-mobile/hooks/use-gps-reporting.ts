import { useEffect, useRef } from 'react';

import { recordGps } from '../services/driver-mobile-service';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §15/§16/§23 — periodic GPS reporting
 * for an active/trackable trip, over the SAME write path the driver GPS
 * endpoint already exposed (recordGps() had zero callers before this task —
 * this is the one place that changes).
 *
 * Fixed, conservative cadence: the backend already dedups anything reported
 * inside its own `distribution.tracking.min_sample_interval_seconds` window
 * (20s by default), so this interval sits comfortably above that without
 * being tightly coupled to it — there is no shared-config endpoint, and none
 * is needed; a client reporting slightly faster than the backend stores is
 * harmless; it is simply dropped there.
 *
 * The driver never manually "starts" or "stops" tracking (§23) — this hook
 * carries no lifecycle of its own. It is only ever mounted with `active`
 * true while the canonical trip status is on-the-road (the caller's own
 * ON_THE_ROAD check), so the trackability boundary is entirely inherited,
 * never re-decided here. The backend independently re-checks
 * Trip::isTrackable() before persisting anything (custody too, not just
 * status) — this hook's job is only to call the endpoint, never to decide
 * whether the sample should count.
 */
const REPORT_INTERVAL_MS = 30_000;

/** Geolocation's own `coords.speed` is metres/second; the backend column is speed_kph. */
const MPS_TO_KPH = 3.6;

export function useGpsReporting(tripId: string, active: boolean) {
  const inFlightRef = useRef(false);

  useEffect(() => {
    if (!active || tripId === '' || !navigator.geolocation) {
      return;
    }

    function reportOnce() {
      if (inFlightRef.current) {
        return; // never stack a second request while one is still in flight
      }
      inFlightRef.current = true;

      navigator.geolocation.getCurrentPosition(
        (pos) => {
          const speedKph = pos.coords.speed === null ? undefined : pos.coords.speed * MPS_TO_KPH;
          void recordGps(
            tripId,
            pos.coords.latitude,
            pos.coords.longitude,
            speedKph,
            pos.coords.accuracy ?? undefined,
          ).finally(() => {
            inFlightRef.current = false;
          });
        },
        () => {
          // Permission denied / position unavailable — truthfully do nothing (§24).
          // No fabricated position is ever sent.
          inFlightRef.current = false;
        },
        { enableHighAccuracy: false, maximumAge: REPORT_INTERVAL_MS, timeout: 10_000 },
      );
    }

    reportOnce();
    const timer = setInterval(reportOnce, REPORT_INTERVAL_MS);
    return () => clearInterval(timer);
  }, [tripId, active]);
}
