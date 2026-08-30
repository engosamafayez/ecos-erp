import type {
  DeliveryStop,
  DriverLoadingManifest,
  DriverTrip,
  TripSettlement,
  VehicleInventorySummary,
} from '../types/driver-mobile';
import { UNRESOLVED_LOADING } from './trip-lifecycle';

/**
 * DRIVER HOME — CANONICAL PRESENTATION DERIVATIONS.
 * TASK-DRIVER-APP-HOME-COMMAND-CENTER-001.
 *
 * Pure functions over the canonical driver read models (trip / loading manifest / delivery
 * stops / vehicle inventory / trip settlement). They derive ONLY presentation — the journey
 * a driver can already see is at, the order outcome counts, the next actionable stop, the
 * custody snapshot, and the real "needs attention" issues. They:
 *   - invent NO value (every number traces to a canonical field),
 *   - drive NO backend transition (this is read/display, not a lifecycle engine),
 *   - are the ONLY place the Home's contextual logic lives, so cards cannot disagree.
 *
 * The next PRIMARY ACTION stays with the existing `deriveState` resolver in the page — this
 * module does not duplicate it.
 */

/**
 * Canonical `TripStatus` ordering (backend enum). A rank lets the journey say "which stage
 * has this trip reached" without a second lifecycle: it reads the one canonical status.
 * `-1` = terminal-blocked; unknown statuses sort as 0 (planning).
 */
const STATUS_RANK: Record<string, number> = {
  planning: 0,
  loading: 1,
  loading_completed: 2,
  driver_accepted: 3,
  ready_for_dispatch: 4,
  dispatched: 5,
  out_for_delivery: 6,
  in_progress: 6,
  completed: 7,
  settlement_pending: 8,
  closed: 9,
  dispatch_blocked: -1,
  cancelled: -1,
};

export function statusRank(status: string | null | undefined): number {
  if (!status) {
    return 0;
  }
  return STATUS_RANK[status] ?? 0;
}

// ── Order metrics (§13–§15) ──────────────────────────────────────────────────

export interface OrderMetrics {
  /** Orders HANDED to the driver for execution = one delivery stop per order (§14). */
  received: number;
  /** Canonically completed deliveries. Partial is NOT counted as delivered (§15). */
  delivered: number;
  partial: number;
  failed: number;
  returned: number;
  /** pending + in_progress — still to work. */
  remaining: number;
  /** delivered / received, as a whole percent. Null when received is 0 (no false 0%). */
  deliveryRatePct: number | null;
}

export function buildOrderMetrics(stops: DeliveryStop[]): OrderMetrics {
  const received = stops.length;
  const delivered = stops.filter((s) => s.status === 'delivered').length;
  const partial = stops.filter((s) => s.status === 'partial').length;
  const failed = stops.filter((s) => s.status === 'failed').length;
  const returned = stops.filter((s) => s.status === 'returned').length;
  const remaining = stops.filter((s) => s.status === 'pending' || s.status === 'in_progress').length;

  return {
    received,
    delivered,
    partial,
    failed,
    returned,
    remaining,
    // Numerator = fully delivered stops; denominator = orders received. Partial is excluded
    // from the numerator on purpose (§15) — it is not a completed delivery.
    deliveryRatePct: received > 0 ? Math.round((delivered / received) * 100) : null,
  };
}

// ── Trip-scoped collections (§19) — canonical, never cross-trip ───────────────

export interface CollectionSummary {
  expected: number;
  collected: number;
  difference: number;
  hasData: boolean;
}

/**
 * Trip-scoped collections straight from the canonical `TripSettlement` read. NO cross-trip
 * aggregation, NO wallet: one trip's expected vs collected. Returns hasData=false when the
 * settlement read is absent so the card is omitted rather than showing a fabricated 0 (§19).
 */
export function buildCollectionSummary(settlement: TripSettlement | null | undefined): CollectionSummary {
  if (!settlement) {
    return { expected: 0, collected: 0, difference: 0, hasData: false };
  }
  const expected = settlement.cash_expected ?? 0;
  const collected = settlement.total_collected ?? 0;
  return {
    expected,
    collected,
    difference: Math.round((expected - collected) * 100) / 100,
    hasData: true,
  };
}

// ── Vehicle custody snapshot (§17) — from the Vehicle Inventory authority ──────

export interface CustodySnapshot {
  products: number;
  loaded: number;
  delivered: number;
  returned: number;
  /** What is physically still on the vehicle = the canonical on-hand, i.e. expected return. */
  onHand: number;
  hasData: boolean;
}

export function buildCustodySnapshot(summary: VehicleInventorySummary | null | undefined): CustodySnapshot {
  if (!summary) {
    return { products: 0, loaded: 0, delivered: 0, returned: 0, onHand: 0, hasData: false };
  }
  return {
    products: summary.products_count ?? 0,
    loaded: summary.total_quantity_loaded ?? 0,
    delivered: summary.total_quantity_delivered ?? 0,
    returned: summary.total_quantity_returned ?? 0,
    onHand: summary.total_quantity_on_hand ?? 0,
    hasData: true,
  };
}

// ── Next stop (§16) — the first unresolved stop by canonical sequence ─────────

/**
 * The next actionable stop = the lowest-`sequence` stop still pending/in_progress. This is
 * pure ordering over the canonical `sequence` the backend already assigned — it is NOT route
 * planning, distance, or zone sequencing (all Phase 3). Returns null when nothing is pending.
 */
export function nextStop(stops: DeliveryStop[]): DeliveryStop | null {
  const open = stops
    .filter((s) => s.status === 'pending' || s.status === 'in_progress')
    .sort((a, b) => a.sequence - b.sequence);
  return open[0] ?? null;
}

// ── Daily journey (§11) — read/presentation stage model ──────────────────────

export type JourneyStageState = 'done' | 'active' | 'upcoming';

export interface JourneyStage {
  key: 'loading' | 'custody' | 'tripStarted' | 'deliveries' | 'returnLeg' | 'closing';
  state: JourneyStageState;
  /** Optional canonical detail, e.g. "5 / 12" for deliveries. Never fabricated. */
  detail?: { delivered: number; total: number };
}

/**
 * The journey a driver can see their day is at — derived ENTIRELY from canonical status +
 * counts. It marks each stage done/active/upcoming; it never mutates and never invents a
 * completion. Later-phase stages (return, closing) show only neutral status until their
 * canonical data exists (§12).
 */
export function buildJourney(
  trip: DriverTrip,
  manifest: DriverLoadingManifest | null,
  metrics: OrderMetrics,
  custody: CustodySnapshot,
  settlement: TripSettlement | null | undefined,
): JourneyStage[] {
  const rank = statusRank(trip.status);
  const items = manifest?.items ?? [];
  const pendingConfirmations = items.filter(
    (i) => i.quantity_loaded > 0 && UNRESOLVED_LOADING.includes(i.workflow_state),
  ).length;

  // LOADING — done only when nothing awaits this driver (Phase-2 precedence) AND the trip
  // has reached loading_completed (or advanced past it).
  const loadingDone = pendingConfirmations === 0 && rank >= 2;
  const loading: JourneyStage = {
    key: 'loading',
    state: loadingDone ? 'done' : 'active',
  };

  // CUSTODY ACCEPTED — the trip has moved to driver_accepted or beyond.
  const custodyState: JourneyStageState =
    rank >= 3 ? 'done' : loadingDone ? 'active' : 'upcoming';

  // TRIP STARTED — dispatched / on the road or beyond.
  const startedState: JourneyStageState =
    rank >= 5 ? 'done' : rank >= 3 ? 'active' : 'upcoming';

  // DELIVERIES — active once on the road; done when the trip is completed or every stop is
  // resolved. Detail is the canonical delivered/received.
  const allStopsResolved = metrics.received > 0 && metrics.remaining === 0;
  const deliveriesState: JourneyStageState =
    rank >= 7 || (rank >= 5 && allStopsResolved) ? 'done' : rank >= 5 ? 'active' : 'upcoming';

  // RETURN LEG — meaningful only once deliveries are under way. "Done" when nothing remains
  // on the vehicle; "active" when the trip is winding down with custody still on board.
  const returnState: JourneyStageState = (() => {
    if (rank < 5) {
      return 'upcoming';
    }
    if (custody.hasData && custody.onHand === 0 && rank >= 6) {
      return 'done';
    }
    return rank >= 7 ? 'active' : 'upcoming';
  })();

  // CLOSING — the settlement. Done when closed; active when settlement is pending.
  const closingState: JourneyStageState = (() => {
    if (rank >= 9 || settlement?.status === 'closed') {
      return 'done';
    }
    if (rank === 8 || settlement?.status === 'submitted') {
      return 'active';
    }
    return 'upcoming';
  })();

  return [
    loading,
    { key: 'custody', state: custodyState },
    { key: 'tripStarted', state: startedState },
    {
      key: 'deliveries',
      state: deliveriesState,
      detail: metrics.received > 0 ? { delivered: metrics.delivered, total: metrics.received } : undefined,
    },
    { key: 'returnLeg', state: returnState },
    { key: 'closing', state: closingState },
  ];
}

// ── Needs Attention (§21) — only REAL, canonical issues ──────────────────────

export interface AttentionItem {
  key: 'pendingLoading' | 'ordersRemaining' | 'expectedReturn' | 'settlementDifference' | 'failedOrders';
  count: number;
}

/**
 * The real, currently-actionable issues — nothing fabricated, nothing shown when zero. Each
 * item traces to a canonical fact: pending loading confirmations, remaining orders, custody
 * still expected back, failed orders, and a settlement cash difference (read-only).
 */
export function buildAttention(
  manifest: DriverLoadingManifest | null,
  metrics: OrderMetrics,
  custody: CustodySnapshot,
  settlement: TripSettlement | null | undefined,
  tripStatus: string | null | undefined,
): AttentionItem[] {
  const out: AttentionItem[] = [];
  const rank = statusRank(tripStatus);

  const items = manifest?.items ?? [];
  const pendingLoading = items.filter(
    (i) => i.quantity_loaded > 0 && UNRESOLVED_LOADING.includes(i.workflow_state),
  ).length;
  if (pendingLoading > 0) {
    out.push({ key: 'pendingLoading', count: pendingLoading });
  }

  // Orders remaining and failed only matter once the trip is actually on the road.
  if (rank >= 5) {
    if (metrics.remaining > 0) {
      out.push({ key: 'ordersRemaining', count: metrics.remaining });
    }
    if (metrics.failed > 0) {
      out.push({ key: 'failedOrders', count: metrics.failed });
    }
  }

  // Custody still on the vehicle once deliveries are winding down = expected return.
  if (rank >= 6 && custody.hasData && custody.onHand > 0) {
    out.push({ key: 'expectedReturn', count: custody.onHand });
  }

  // A settlement cash difference (read-only signal, never a computed liability).
  if (settlement && typeof settlement.discrepancy === 'number' && Math.abs(settlement.discrepancy) > 0.005) {
    out.push({ key: 'settlementDifference', count: Math.abs(Math.round(settlement.discrepancy)) });
  }

  return out;
}
