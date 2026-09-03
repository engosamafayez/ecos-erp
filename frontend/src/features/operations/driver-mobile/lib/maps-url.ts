/**
 * Build a Google Maps deep-link from a canonical GPS fix.
 *
 * No app-wide canonical "maps URL" authority exists yet (confirmed by a read-only
 * audit across Orders, Customers and Distribution — six independent inline
 * implementations were found, including the driver Stop Detail page's own). This is
 * a small, scoped helper for the Orders LIST card only, matching the same
 * `maps.google.com/?q=lat,lng` shape already used elsewhere in the driver-mobile
 * feature — not a new consolidation effort, which is a separate decision for later.
 */
export function buildMapsUrl(gps: { lat: number; lng: number } | null | undefined): string | null {
  if (!gps) {
    return null;
  }

  return `https://maps.google.com/?q=${gps.lat},${gps.lng}`;
}
