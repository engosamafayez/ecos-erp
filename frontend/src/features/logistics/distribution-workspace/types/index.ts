/**
 * TASK-SHIPPING-DISTRIBUTION-WORKSPACE-UI-E2E-001
 *
 * These types mirror the Laravel Distribution API payloads exactly. They are a
 * transport contract, not a second model: nothing here re-derives eligibility,
 * capacity, ranking or aggregation — the backend owns all of it and the UI only
 * renders what it is told.
 */

export type DistributionWindowStatus = 'open' | 'cutoff' | 'closed' | string;

/** GET /logistics/distribution/windows/current → data.window */
export type DistributionWindow = {
  id: string;
  window_date: string;
  opens_at: string;
  closes_at: string;
  status: DistributionWindowStatus;
  status_label: string;
  accepts_automatic_ingestion: boolean;
  accepts_manual_assignment: boolean;
  next_window_id: string | null;
};

/** data.zones[] — aggregated BY ZONE, never by zone × slot. */
export type ZoneSummary = {
  zone_id: number | null;
  zone_code: string | null;
  zone_name: string | null;
  /** The zone's PLANNED slot. Orders may sit elsewhere — see spans_slots. */
  virtual_slot_id: string | null;
  order_count: number;
  total_value: number;
  /** True when the zone's orders are spread over more than one slot. */
  spans_slots: boolean;
  /** Distinct products across the zone's orders (never a line count). */
  products_count: number;
  /** PaymentState-derived split. unpaid_orders is the complement, not a second sum. */
  paid_orders: number;
  unpaid_orders: number;
};

/**
 * data.slots[] — a Virtual Capacity Slot, surfaced to operators as a
 * **Distribution Group**.
 *
 * A group is a planning container holding one or more Zones and their orders.
 * It is NOT a vehicle: the table carries no vehicle_id and no driver_id, and
 * that absence is deliberate — vehicle planning is a later phase.
 */
export type SlotSummary = {
  slot_id: string;
  code: string;
  name: string | null;
  /** The Group belongs to exactly one warehouse. Never null — the column is NOT NULL. */
  warehouse_id: string;
  zone_ids: number[];
  zone_names: string[];
  zones_count: number;
  capacity_orders: number | null;
  capacity_stops: number | null;
  capacity_weight_kg: number | null;
  demand_orders: number;
  /**
   * `capacity_orders - demand_orders`, floored at 0. null = no maximum.
   *
   * DERIVED SERVER-SIDE and never stored. The UI must render this rather than
   * subtract the two fields itself, so the screen and the write-path guard that
   * enforces the limit can never disagree about how much room a group has.
   */
  remaining_orders: number | null;
  utilisation: number | null;
  overflow_orders: number;
  is_over_capacity: boolean;
  is_warning: boolean;
  // -- Group rollup --
  orders_count: number;
  products_count: number;
  total_value: number;
  paid_orders: number;
  unpaid_orders: number;
  /** One state exists today. Reported by the backend, not assumed here. */
  status: string;
};

/**
 * The operational cycle the workspace plans against.
 *
 * Distribution runs no schedule of its own — these boundaries ARE the active
 * Preparation Wave's, read as stored. Timestamps are UTC; render them in
 * `timezone`, which is the company's operational zone.
 */
export type PreparationWaveCycle = {
  wave_id: string;
  wave_number: string;
  planning_date: string;
  starts_at: string;
  cutoff_at: string;
  ends_at: string;
  status: string;
  timezone: string | null;
};

/** The Order's OWN address fields. Nulls are real and are shown as missing. */
export type ShippingAddress = {
  recipient: string | null;
  phone: string | null;
  secondary_phone: string | null;
  street: string | null;
  building: string | null;
  floor: string | null;
  apartment: string | null;
  landmark: string | null;
  area: string | null;
  city: string | null;
  governorate: string | null;
  postcode: string | null;
  notes: string | null;
};

/** GET /windows/{id}/orders */
/**
 * Why an order carries no zone. Null when it does.
 *
 * The backend decides this from state that already exists — it is never inferred
 * in the UI, because a reason the operator acts on must come from the same place
 * the zone decision came from.
 */
export type UnassignedReason =
  | 'address_incomplete'
  | 'city_not_resolved'
  | 'zone_not_configured'
  | 'unresolved';

export type DistributionOrder = {
  assignment_id: string;
  order_id: string;
  order_number: string;
  order_status: string;
  customer_name: string | null;
  phone: string | null;
  address: string | null;
  total: number;
  payment_method: string | null;
  zone_id: number | null;
  zone_name: string | null;
  virtual_slot_id: string | null;
  assignment_source: string;
  assigned_at: string | null;
  // -- Address binding + zone diagnostics --
  city_id: number | null;
  city_name: string | null;
  /** The raw address text the resolver was given - shown when it matched nothing. */
  city_text: string | null;
  governorate_id: number | null;
  governorate_name: string | null;
  unassigned_reason: UnassignedReason | null;
  // -- Order content --
  products_count: number;
  total_quantity: number;
  received_at: string | null;
  last_updated_at: string | null;
  // -- Payment --
  payment_status: string | null;
  /** payment_method, falling back to the manual-entry method. */
  payment_method_effective: string | null;
  payment_state: 'paid' | 'partially_paid' | 'unpaid' | string;
  // -- Distribution --
  distribution_status: string;
  is_late: boolean;
  warehouse_name: string | null;
  shipping_address: ShippingAddress;
  // -- Captured delivery location (real coordinates only; null = not recorded) --
  latitude: number | null;
  longitude: number | null;
  google_maps_url: string | null;
};

/** POST /windows/collect - bind, collect, reconcile, all reported. */
export type CollectResult = {
  collected: number;
  cities_bound: number;
  cities_unresolved: number;
  city_failure_reasons: Record<string, number>;
  rezoned: number;
};

/**
 * Whether the read could identify a planning window at all — TASK-1-A §1.
 *
 * A TRANSPORT discriminator, not a business status: `DistributionWindowStatus`
 * remains the only window lifecycle. This exists because the server used to answer
 * an unresolvable read with today's freshly-created calendar window, which the UI
 * then rendered as a complete but empty board.
 */
export type WindowResolution = 'resolved' | 'no_planning_window';

/**
 * Why no window could be resolved.
 *
 * H1 = Option B leaves exactly one server-side reason: this tenant has no existing
 * Distribution Window. A missing Preparation Wave is NOT a reason — the wave selects the
 * current operational cycle, it does not gate reading Distribution — and "no warehouse
 * selected" is a client context question the server cannot answer.
 */
export type WindowResolutionReason = 'no_window_available';

/** GET /logistics/distribution/windows/current → data */
export type CurrentWindowResponse = {
  resolution: WindowResolution;
  /** Non-null exactly when `resolution === 'no_planning_window'`. */
  resolution_reason: WindowResolutionReason | null;
  /**
   * Null when unresolved. The board must not be rendered from a null window — the
   * whole point of the discriminator is that "no window" stops being drawable as
   * an empty one.
   */
  window: DistributionWindow | null;
  /** null when no Preparation Wave is active for the company. */
  preparation_wave: PreparationWaveCycle | null;
  zones: ZoneSummary[];
  slots: SlotSummary[];
};

/**
 * One product required by ONE Distribution Group — LP-1.
 *
 * ┌─ WHAT THIS ROW IS, AND WHAT IT IS NOT ───────────────────────────────────┐
 * │ REQUIRED is group-specific: the server sums `order_lines.quantity` over   │
 * │ exactly this Group's currently-eligible orders, for this Group's          │
 * │ warehouse.                                                               │
 * │                                                                          │
 * │ There is deliberately NO `prepared` and NO `remaining` field. Preparation │
 * │ records prepared quantity per WAVE + PRODUCT, never per Group, so a       │
 * │ group-scoped "prepared" figure cannot be derived without inventing an     │
 * │ allocation rule. LP-1 shows Required only rather than show a number it    │
 * │ cannot justify (D-4, Option A).                                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
export type GroupRequiredProduct = {
  product_id: string;
  product_name: string | null;
  product_sku: string | null;
  /** Unit of measure, travelling with the quantity. Null when the product has none. */
  unit_code: string | null;
  unit_symbol: string | null;
  /** REQUIRED. The canonical live aggregation — never stored, never recomputed here. */
  total_quantity: number;
  /**
   * PREPARED — what the warehouse has recorded as separated for THIS Group.
   *
   * A different fact from `wave_product_demand.prepared_qty`, which is per WAVE and
   * per product and cannot be attributed to a Group. The two are never summed or
   * compared; they answer different questions at different grains.
   */
  prepared_qty: number;
  /** REMAINING — max(0, required − prepared), DERIVED BY THE SERVER. Never stored. */
  remaining_qty: number;
  /**
   * max(0, prepared − required), derived by the server.
   *
   * Non-zero means Required FELL after the floor had already separated stock — an
   * order left the Group, was cancelled, or was postponed. Remaining is floored at
   * zero, so without this the operator would be told "nothing to do" about a pallet
   * that now holds more than the Group needs.
   */
  over_prepared_qty: number;
};

/**
 * GET /logistics/distribution/windows/{window}/slots/{slot}/trips
 * POST …/finalize
 *
 * The Group's TRANSPORT EXECUTION object(s).
 *
 * A Group plans; a Trip executes. One Group owns 1..N Trips — normally one, and
 * more only when Trip.capacity forces a split. One Trip belongs to exactly one
 * Group, structurally: the reference is a single column on the Trip.
 *
 * Vehicle and Driver are READ THROUGH the canonical pairing
 * (`logistics_driver_vehicle_assignments`). They are not stored on the Trip and
 * certainly not on the Group — this screen displaying them does not make either
 * one their owner.
 */
export type GroupTrip = {
  trip_id: string;
  trip_number: string;
  name: string | null;
  status: string;
  capacity: number;
  orders_count: number;
  /** Stamped by Finalize. Null means the Group has not been finalized. */
  finalized_at: string | null;
  dispatched_at: string | null;
  driver_vehicle_assignment_id: number | null;
  vehicle: { id: number; plate_number: string | null; name: string | null } | null;
  driver: { id: number; full_name: string | null; mobile: string | null } | null;
  /**
   * `Trip::remainingCapacity()` as the server computes it — never recomputed here,
   * so this and the capacity refusal inside `assignOrder` cannot disagree. Trip
   * capacity stays independent of Group capacity; neither derives from the other.
   */
  remaining_capacity: number;
};

/**
 * GET /logistics/distribution/windows/{window}/slots/{slot}/reconciliation
 *
 * ┌─ WHY THIS PAYLOAD EXISTS ────────────────────────────────────────────────┐
 * │ A Group PLANS; a Trip EXECUTES. `distribution_trip_orders` is an          │
 * │ execution manifest — a snapshot taken at Finalize — while Group           │
 * │ membership lives in `distribution_window_orders.virtual_slot_id`. The     │
 * │ approved contract deliberately does NOT synchronise them, and a certified │
 * │ test enforces that a repeated Finalize re-syncs nothing.                  │
 * │                                                                          │
 * │ The two sets can therefore legitimately differ, and until now no screen   │
 * │ said so. This payload reports the difference. It never closes it.        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * BOTH DIFFERENCES ARE COMPUTED SERVER-SIDE. The client renders them and derives
 * no membership of its own — and the difference is NOT `group_orders -
 * trip_orders`: on live DG-001 that subtraction gives 4 while the true answer is
 * 5 unassigned plus 1 exception, because one manifest row is no longer a member.
 */
/**
 * Where the Group is in its lifecycle — decided by the SERVER, never derived here.
 *
 * "Not assigned to a trip" is not an operational state, and presenting it as one was
 * the defect this replaces. The same raw difference means four different things and
 * each asks the operator for something different.
 */
export type GroupTripState =
  /** Members exceed the Group's PLANNING capacity — Finalize will refuse. */
  | 'capacity_decision_required'
  /**
   * Over the planning capacity, and the operator explicitly accepted it. The overflow
   * is still real and still reported; the planning capacity is unchanged.
   */
  | 'overflow_approved'
  /** Within capacity, no Trip yet. Finalize is the next legitimate step. */
  | 'awaiting_finalization'
  /** Finalized, but members joined afterwards — the snapshot cannot self-update. */
  | 'added_after_finalization'
  /** Every member is on a Trip. */
  | 'resolved';

export type GroupTripReconciliation = {
  state: GroupTripState;
  /**
   * Rendered as supplied. `maximum: null` means unconstrained — never zero — exactly
   * as GroupCapacityGuard and Finalize already read `capacity_orders`.
   */
  capacity: {
    /**
     * The PLANNING capacity, always. An approved Group with 25 orders still reports a
     * maximum of 20 — approval records an accepted exception, never a new limit.
     */
    maximum: number | null;
    current: number;
    remaining: number | null;
    overflow: number;
    /** True only while the approved count still covers current occupancy. */
    overflow_approved: boolean;
    overflow_approved_orders: number | null;
    overflow_approved_at: string | null;
  };
  summary: {
    /** Group members under the canonical loading-eligible predicate. */
    group_orders: number;
    /** Rows in the live (non-cancelled) Trips' manifests. */
    trip_orders: number;
    /** Group members absent from every manifest. */
    unassigned_orders: number;
    /** Manifest rows whose order is no longer a Group member. */
    exception_orders: number;
  };
  trips: GroupTrip[];
  /** Rendered as supplied by the canonical order aggregate. */
  unassigned_orders: DistributionOrder[];
  exceptions: {
    order_id: string;
    order_number: string;
    order_status: string;
    trip_number: string;
    assignment_type: string;
    assigned_at: string | null;
    zone_code_snapshot: string | null;
  }[];
};

/**
 * GET /logistics/distribution/windows/{window}/awaiting-group
 *
 * Orders that no Group covers, with the ROOT blocker for each — decided server-side.
 *
 * An eligible Order can sit in a Window, carry a Zone, and still belong to no Group,
 * because a Group holds only the Zones an operator attached to it. And because every
 * Group-side read is warehouse-scoped, an Order with no warehouse matched nothing and
 * disappeared from the board entirely. This payload is what makes both visible.
 *
 * The blocker is never inferred in the UI: an Order appears in exactly one bucket, and
 * anything else true about it travels as `secondary_reason`.
 */
export type GroupAssignmentBlocker =
  /** No warehouse. Root blocker even when the Zone is also uncovered. */
  | 'warehouse_unassigned'
  /** Warehouse present, but the Zone is attached to no Group in this Window. */
  | 'zone_not_in_group'
  /** Warehouse and a covered Zone, yet still no Group — not expected, so reported. */
  | 'awaiting_group_assignment';

export type OrderAwaitingGroup = {
  order_id: string;
  order_number: string;
  order_status: string;
  customer_name: string | null;
  total: number | null;
  payment_state: string | null;
  payment_method_effective: string | null;
  products_count: number | null;
  city_name: string | null;
  governorate_name: string | null;
  zone_id: number | null;
  zone_name: string | null;
  warehouse_id: string | null;
  warehouse_name: string | null;
  blocker: GroupAssignmentBlocker;
  /**
   * The EXISTING zone-level reason, carried through unchanged. It answers "why is there
   * no Zone", which is a different question from "why is there no Group".
   */
  secondary_reason: UnassignedReason | null;
};

/**
 * The same gap one level up: a Zone that holds work but belongs to no Group.
 *
 * This is the ROOT cause of the order-level list. A Group holds only the Zones an
 * operator attached to it, so configuring one Zone clears every Order behind it —
 * whereas the order list invites triaging the same problem once per Order.
 *
 * Rolled up SERVER-SIDE from the very rows in `orders`, so the two grains cannot
 * disagree. An Order with no Zone contributes no row: a Zone that does not exist cannot
 * be configured, and inventing one would offer an action that leads nowhere.
 */
export type ZoneWithoutGroup = {
  zone_id: number;
  zone_name: string | null;
  /** Orders in this Zone that no Group covers. */
  orders_waiting: number;
  /**
   * How many of those ALSO have no warehouse. A second, different blocker — kept
   * separate so the UI never collapses "no Group" and "no warehouse" into one message.
   */
  orders_needing_warehouse: number;
  /** Distinct governorates among the waiting Orders — a Zone spans cities. */
  governorates: string[];
  warehouses: string[];
};

/**
 * What the server committed for an atomic multi-order move.
 *
 * `moved` is the SERVER'S count, not the length of what was sent. There is deliberately
 * no per-order status array: the operation is all-or-nothing, so a shape that could
 * express "3 of 5 succeeded" would describe a state this endpoint cannot produce.
 */
export type BatchMoveResult = {
  moved: number;
  slot_id: string | null;
  assignment_ids: string[];
  order_ids: string[];
};

export type OrdersAwaitingGroupResponse = {
  summary: {
    total: number;
    warehouse_unassigned: number;
    zone_not_in_group: number;
    awaiting_group_assignment: number;
  };
  orders: OrderAwaitingGroup[];
  /** Busiest Zone first — the one blocking the most work is the one to configure next. */
  zones: ZoneWithoutGroup[];
};

export type WorkspaceFilters = {
  zone_id?: number | null;
  slot_id?: string | null;
};

/**
 * VP-1 — the Vehicle/Driver selectors for a Group.
 *
 * `id` is the CROSS-MODULE uuid (decision D1-C), never the canonical bigint —
 * the backend deliberately does not publish the internal key.
 *
 * `fits_group` and `group_orders` are SERVER-decided (D4-C). The drawer renders
 * them; it must never recompute the fit, or the number shown could disagree with
 * the number that decides the write.
 */
export interface FleetVehicleOption {
  id: string;
  plate_number: string | null;
  name: string | null;
  status: string | null;
  capacity_orders: number;
  fits_group: boolean;
  /**
   * The drivers ACTIVELY paired to this vehicle in the canonical ledger, as
   * driver uuids. The Driver selector offers only these — an empty array means
   * no driver is assigned to this vehicle, which the drawer states explicitly.
   *
   * Optional so a not-yet-updated backend degrades to "no eligible drivers"
   * rather than crashing the drawer.
   */
  driver_ids?: string[];
}

export interface FleetDriverOption {
  id: string;
  full_name: string | null;
  driver_code: string | null;
  mobile: string | null;
}

export interface GroupFleetOptions {
  group_orders: number;
  vehicles: FleetVehicleOption[];
  drivers: FleetDriverOption[];
}

export interface GroupVehicleAssignmentResult {
  trip: GroupTrip | null;
  group_orders: number;
  vehicle_capacity: number;
  remaining_capacity: number;
}

/**
 * Group → Trip → Vehicle/Driver → Loading execution context.
 *
 * ECOS CAPACITY CONTRACT: capacity is an ORDER COUNT. There is deliberately no
 * weight, volume or refrigeration field here — they are not business constraints
 * in this platform, and giving them a place in the type is how they creep back
 * in as operational requirements.
 */
export interface GroupLoadingContext {
  group: {
    id: string;
    code: string;
    name: string | null;
    warehouse_id: string | null;
    /** null = the group is unconstrained by order count, NOT "zero orders". */
    capacity_orders: number | null;
  };
  /** `id` is the Trip's public UUID — the internal bigint is never published. */
  trip: { id: string; trip_number: string | null; status: string | null };
  vehicle: { id: string; plate_number: string | null; capacity_orders: number } | null;
  driver: { id: string; full_name: string | null; mobile: string | null } | null;
  loading: {
    session_id: string;
    session_number: string | null;
    session_status: string | null;
    assignment_id: string;
    assignment_number: string | null;
    assignment_status: string | null;
  };
  products: GroupRequiredProduct[];
}


// ── Group capacity (order count only) ────────────────────────────────────────

/**
 * The one Group configuration edit surface: PATCH .../slots/{slot}.
 *
 * `capacity_orders` is the ONLY capacity axis. Sending null REMOVES the limit —
 * it does not mean a limit of zero, which is the same contract the backend and
 * the database column carry.
 */
export type UpdateGroupPayload = {
  name?: string | null;
  capacity_orders?: number | null;
};

/** PATCH .../slots/{slot} → data. Live capacity, derived server-side. */
export type GroupConfiguration = {
  slot_id: string;
  code: string;
  name: string | null;
  warehouse_id: string;
  capacity_orders: number | null;
  orders_count: number;
  /** max_orders - current_orders, floored at 0. null = no maximum. Never stored. */
  remaining_orders: number | null;
};

// ── Map ──────────────────────────────────────────────────────────────────────

/**
 * GET .../windows/{window}/map
 *
 * Read-only. Every coordinate is a REAL captured `orders.google_maps_lat/lng`.
 * A zone has no stored geometry: its position is derived by the backend from its
 * own orders, falling back to its cities' coordinates. `has_location: false`
 * means no location was recorded — it must never be replaced with a substitute.
 */
export type MapOrder = {
  order_id: string;
  order_number: string | null;
  customer_name: string | null;
  total: number;
  city: string | null;
  zone_id: number | null;
  slot_id: string | null;
  latitude: number | null;
  longitude: number | null;
  has_location: boolean;
};

/** Which contract placed a zone, or null when nothing could. */
export type ZoneCentroidSource = 'orders' | 'cities' | null;

export type MapZone = {
  zone_id: number;
  zone_code: string | null;
  zone_name: string | null;
  /** `distribution_zones.color` — the existing zone colour, not a new palette. */
  color: string | null;
  order_count: number;
  plotted_count: number;
  slot_ids: string[];
  latitude: number | null;
  longitude: number | null;
  centroid_source: ZoneCentroidSource;
  has_location: boolean;
};

export type MapGroup = {
  slot_id: string;
  code: string;
  name: string | null;
  zone_ids: number[];
  orders_count: number;
  capacity_orders: number | null;
  remaining_orders: number | null;
};

export type MapData = {
  zones: MapZone[];
  groups: MapGroup[];
  orders: MapOrder[];
  summary: {
    orders_total: number;
    orders_plotted: number;
    orders_without_location: number;
    zones_total: number;
    zones_plotted: number;
  };
};

// ── Group templates ──────────────────────────────────────────────────────────

/**
 * A reusable Group CONFIGURATION — never a Group and never a snapshot.
 *
 * There is deliberately no orders / vehicle / driver / trip / loading / prepared
 * field, because the table has no column for one. If a field like that ever
 * appears here, the template has stopped being configuration.
 */
export type GroupTemplate = {
  id: string;
  name: string;
  /** null = no limit. Order count only. */
  capacity_orders: number | null;
  zone_ids: number[];
  zones_count: number;
  /**
   * Recommended Driver ids — SUGGESTIONS only, never an assignment. Applying a
   * template never copies these into the Group; the Group's Driver stays open.
   */
  driver_ids: number[];
  drivers_count: number;
  created_at: string | null;
  updated_at: string | null;
};

/**
 * Which Template currently owns a Zone, for this company.
 *
 * TASK-DISTRIBUTION-TEMPLATES-ZONE-EXCLUSIVITY-001 — a Zone belongs to at most ONE
 * Template per company. This arrives WITH the template list, computed by the same
 * server method the write-path guard uses, so the picker can never label a Zone free
 * that the save would then refuse.
 *
 * Archived templates do not appear here: archiving leaves the Zone rows in place so a
 * restore is intact, and counting them would strand a Zone behind a template nobody
 * can open.
 */
export type ZoneTemplateOwnership = {
  zone_id: number;
  template_id: string;
  template_name: string;
};

export type GroupTemplatesResult = {
  templates: GroupTemplate[];
  ownership: ZoneTemplateOwnership[];
};

/**
 * One condition in the backend's readiness decision.
 *
 * `key` is a stable identifier the UI maps to an i18n label — never a class name, a
 * column or an exception. The set and the order come from the server; the frontend adds
 * no checks of its own and removes none.
 */
export type TripReadinessCheck = {
  key: string;
  ok: boolean;
};

/**
 * THE CANONICAL READINESS DECISION, as the server computed it.
 *
 * `GroupLoadingContextService::readiness()` runs the very guards `open()` runs, so this
 * is not a description of the rules — it is the outcome of the rules. The UI renders it
 * and never recomputes it: a screen that decided readiness for itself would eventually
 * show READY on a trip that then refuses to open.
 *
 * `reason` is the server's sentence for the FIRST failing check, already
 * operator-readable.
 */
export type TripReadiness = {
  trip_id: string;
  ready: boolean;
  checks: TripReadinessCheck[];
  reason: string | null;
};

export type GroupTripsResult = {
  trips: GroupTrip[];
  readiness: TripReadiness[];
};

export type SaveGroupTemplatePayload = {
  name?: string;
  capacity_orders?: number | null;
  zone_ids?: number[];
  /**
   * Recommended Driver ids — suggestions only. Absent leaves the set alone (edit),
   * an empty array clears it. Never applied to a Group.
   */
  driver_ids?: number[];
  /**
   * The operator's confirmation of the Move dialog — nothing else sets it.
   *
   * Absent or false, the server REFUSES a Zone owned by another Template. True, it
   * detaches the Zone from its previous owner first, so the Zone is moved and never
   * duplicated. The server is the authority either way; this only carries the answer.
   */
  move_zones?: boolean;
};

/**
 * Applying a template. Every field overrides the template's own value, because
 * the operator must be able to adjust name, zones and limit BEFORE the group
 * exists. `warehouse_id` has no template default by design: a Group's owner is
 * always chosen explicitly.
 */
export type ApplyGroupTemplatePayload = {
  warehouse_id: string;
  code: string;
  name?: string | null;
  capacity_orders?: number | null;
  zone_ids?: number[];
};

export type AppliedGroupTemplate = {
  slot_id: string;
  code: string;
  name: string | null;
  warehouse_id: string;
  capacity_orders: number | null;
  applied_from_template_id: string;
};
