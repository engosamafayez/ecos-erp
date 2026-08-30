import type { DistributionOrder, MapOrder } from '../types';

/**
 * TASK-DISTRIBUTION-MAP-LOCATION-UX-003 — the location resolution layer.
 *
 * ┌─ ONE PLACE DECIDES WHERE AN ORDER GOES ──────────────────────────────────┐
 * │ Priority 1  captured coordinates          -> RESOLVED_FROM_COORDINATES    │
 * │ Priority 2  a full address, geocoded      -> RESOLVED_FROM_ADDRESS        │
 * │ Priority 3  neither, or geocoding failed  -> UNRESOLVED                   │
 * │                                                                          │
 * │ UNRESOLVED never receives substitute coordinates. An invented point is    │
 * │ worse than a missing one: it looks identical to a real delivery address    │
 * │ on the map and someone will drive to it.                                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * DISPLAY ONLY. Nothing here writes to the database. `orders.google_maps_lat/lng`
 * remains the source of truth, written solely by the existing capture path — so a
 * resolution computed for the map can never overwrite what an operator recorded.
 */
export type LocationStatus =
  | 'RESOLVED_FROM_COORDINATES'
  | 'RESOLVED_FROM_ADDRESS'
  | 'UNRESOLVED';

export type ResolvedLocation = {
  status: LocationStatus;
  lat: number | null;
  lng: number | null;
};

/**
 * A geocoder turns a full address into a point, or returns null when it cannot.
 *
 * Declared as an interface with NO implementation registered. The repository has no
 * geocoding provider, and adding one means an external dependency plus a key or config
 * entry — which this task is not authorised to introduce. So the seam exists, the
 * priority order below is real, and Priority 2 stays unreachable until a provider is
 * approved. That is deliberately visible rather than hidden behind a stub that
 * silently returns nothing forever.
 */
export type AddressGeocoder = {
  /** Null means "could not resolve" — never a guess. */
  geocode: (query: string) => Promise<{ lat: number; lng: number } | null>;
};

/** No provider is registered. See AddressGeocoder. */
export const REGISTERED_GEOCODER: AddressGeocoder | null = null;

/**
 * The full address as a geocoder would need it, or null when too thin to try.
 *
 * "Maadi" alone is not an address — it matches a district centroid and would drop a pin
 * that looks exact and is not. A query is only worth making when it carries a street or
 * building line ON TOP of the locality, so the requirement here is at least one specific
 * part plus at least one locality part.
 *
 * Ordered narrow -> wide, which is what geocoders expect:
 *   "2 Shalaby, Bldg 22, Apt 2, Maadi, Cairo, Egypt"
 */
export function buildAddressQuery(
  order: Pick<
    DistributionOrder,
    'address' | 'city_name' | 'city_text' | 'governorate_name'
  > & {
    building?: string | null;
    apartment?: string | null;
    area?: string | null;
  },
  country = 'Egypt',
): string | null {
  const specific = [
    order.address,
    order.building == null || order.building === '' ? null : `Bldg ${order.building}`,
    order.apartment == null || order.apartment === '' ? null : `Apt ${order.apartment}`,
  ].filter((part): part is string => part != null && part !== '');

  const locality = [
    order.area,
    order.city_name ?? order.city_text,
    order.governorate_name,
  ].filter((part): part is string => part != null && part !== '');

  // Both halves are required: a street with no city is unplaceable, and a city with no
  // street is a centroid pretending to be a delivery point.
  if (specific.length === 0 || locality.length === 0) {
    return null;
  }

  return [...specific, ...locality, country].join(', ');
}

/**
 * Where this order belongs on the map, and why.
 *
 * Coordinates win outright and cost no request. Everything else depends on a geocoder
 * that is not registered, so it reports UNRESOLVED rather than pretending.
 */
export function resolveOrderLocation(
  order: Pick<MapOrder, 'latitude' | 'longitude'>,
): ResolvedLocation {
  if (order.latitude !== null && order.longitude !== null) {
    return {
      status: 'RESOLVED_FROM_COORDINATES',
      lat: order.latitude,
      lng: order.longitude,
    };
  }

  return { status: 'UNRESOLVED', lat: null, lng: null };
}

/**
 * Is this order worth a geocoding attempt once a provider exists?
 *
 * Used by the report and by the detail panel to distinguish "no address on file" from
 * "address on file, no provider to resolve it" — two different operator problems.
 */
export function isAddressResolvable(
  order: Parameters<typeof buildAddressQuery>[0],
): boolean {
  return buildAddressQuery(order) !== null;
}

// ── Coordinate aggregation (co-located orders → ONE marker) ──────────────────

/**
 * The precision at which two coordinates count as the SAME geographical point.
 *
 * The delivery coordinate is stored as DECIMAL(10,7), so seven fraction digits is
 * the coordinate's own representation — not an arbitrary rounding introduced here.
 * Orders geocoded from one address (or sharing a single captured pin) land on
 * byte-identical values and aggregate; two genuinely different points differ well
 * before the seventh digit and stay separate. Nothing coarser is applied, so two
 * real delivery addresses are never merged into one marker.
 */
export const CLUSTER_KEY_PRECISION = 7;

/** A deterministic key for a coordinate — identical inputs give an identical key. */
export function coordinateKey(lat: number, lng: number): string {
  return `${lat.toFixed(CLUSTER_KEY_PRECISION)},${lng.toFixed(CLUSTER_KEY_PRECISION)}`;
}

/** One geographical point and every order that shares its exact coordinate. */
export type OrderCluster<T> = {
  /** `coordinateKey(lat, lng)` — stable across renders for the same point. */
  key: string;
  lat: number;
  lng: number;
  /** Every order at this point, in input order. Never truncated. */
  orders: T[];
};

/**
 * Group located orders by their EXACT coordinate, so orders sharing a point become
 * ONE marker instead of a stack of overlapping pins where only the top one is
 * reachable.
 *
 * Aggregation is by coordinate ONLY — never by zone, city, governorate, group,
 * customer or address text — and it preserves every order: six orders at one
 * building come back as a single cluster of six, with none dropped. Orders without a
 * coordinate are skipped here (they are listed separately and never given a
 * substitute point). Deterministic: clusters and their members keep input order, so
 * markers do not reshuffle between renders.
 */
export function clusterByCoordinate<
  T extends { latitude: number | null; longitude: number | null },
>(orders: readonly T[]): OrderCluster<T>[] {
  const clusters = new Map<string, OrderCluster<T>>();

  for (const order of orders) {
    if (order.latitude === null || order.longitude === null) {
      continue;
    }

    const key = coordinateKey(order.latitude, order.longitude);
    const existing = clusters.get(key);

    if (existing === undefined) {
      clusters.set(key, { key, lat: order.latitude, lng: order.longitude, orders: [order] });
    } else {
      existing.orders.push(order);
    }
  }

  return [...clusters.values()];
}
