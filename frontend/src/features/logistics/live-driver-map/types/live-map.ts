/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §8/§13 — the two Live Map / Route
 * History read models. Field names mirror LiveMapController's own
 * presenters (backend/Modules/Logistics/Distribution/Presentation/Http/
 * Controllers/LiveMapController.php) exactly — no renaming in transit.
 */

export type LocationFreshness = 'fresh' | 'stale';

export interface LiveMapDriver {
  id: number;
  full_name: string;
  mobile: string | null;
}

export interface LiveMapVehicle {
  id: number;
  plate_number: string;
  name: string | null;
}

export interface LiveMapLocation {
  lat: number;
  lng: number;
  recorded_at: string;
  freshness: LocationFreshness;
}

/** One row of the Live Driver Map — always a currently trackable Trip. */
export interface LiveMapTrip {
  trip_id: string;
  trip_number: string;
  status: string;
  driver: LiveMapDriver | null;
  vehicle: LiveMapVehicle | null;
  /** null = Unknown — no GPS sample has ever been recorded for this trip. */
  location: LiveMapLocation | null;
  stops_total: number;
  stops_completed: number;
  has_exception: boolean;
}

export interface RouteHistorySample {
  lat: number;
  lng: number;
  recorded_at: string;
}

export interface RouteHistoryStop {
  id: string;
  sequence: number;
  status: string;
  /** Only a real canonical coordinate — null means "No location", never a substitute pin. */
  location: { lat: number; lng: number } | null;
  completed_at: string | null;
}

export interface RouteHistoryException {
  id: number;
  stop_id: string | null;
  exception_type: string;
  reported_at: string | null;
  resolved_at: string | null;
}

export interface RouteHistoryTrip {
  trip_id: string;
  trip_number: string;
  status: string;
  driver: LiveMapDriver | null;
  vehicle: LiveMapVehicle | null;
  trip_started_at: string | null;
  trip_finished_at: string | null;
}

export interface RouteHistory {
  trip: RouteHistoryTrip;
  /** Raw recorded GPS points in chronological order — never road-snapped. */
  samples: RouteHistorySample[];
  /** True when the bounded `limit` cut off further (older-than-shown) samples. */
  samples_truncated: boolean;
  stops: RouteHistoryStop[];
  exceptions: RouteHistoryException[];
}
