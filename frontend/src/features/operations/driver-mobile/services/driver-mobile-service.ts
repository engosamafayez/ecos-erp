import axios from 'axios';
import { api } from '@/lib/axios';
import type {
  DriverAdvancesReport,
  DriverGoodsMovement,
  DriverOrdersReport,
  DriverShortageReport,
  DriverStatement,
  DriverWallet,
  ReportPeriodValue,
} from '../types/reports';
import type { CreateTripExpenseInput, TripExpense, TripExpensesResponse } from '../types/trip-expenses';
import type {
  CustodyReturn,
  DeliveryException,
  DeliveryReturn,
  DeliveryStop,
  DeliveryStopDetail,
  DriverTrip,
  TripSettlement,
  TripTimeline,
  DriverLoadingManifest,
  DriverVehicleInventory,
  FailureReasonOption,
  DriverDeliveryProofResult,
  DriverPaymentProofResult,
} from '../types/driver-mobile';

// TASK-DRIVER-02 — the driver runtime MUST use the shared API client.
//
// This file previously created its own bare instance (`axios.create({ baseURL: '/api' })`).
// The app authenticates with a BEARER TOKEN attached by the shared client's request
// interceptor, so a private instance sent every driver request unauthenticated: the
// browser showed `/api/driver/loading` and `/api/driver/trips` returning 401 while
// `/api/auth/me` returned 200. Worse, the 401 surfaced as an EMPTY state ("no shipment
// assigned yet") rather than an error, so a broken session looked like an idle driver.
// The shared client also centralises 401 → logout. Same baseURL (`env.apiUrl` = '/api'),
// so this is a drop-in.
//
// `axios` itself is still imported for `isAxiosError` in loadingErrorMessage() below.

// ── Active trips ────────────────────────────────────────────────────────────

export async function fetchActiveTrips(): Promise<DriverTrip[]> {
  const { data } = await api.get<DriverTrip[]>('/driver/trips');
  return data;
}

export async function fetchTripDashboard(tripId: string): Promise<DriverTrip> {
  const { data } = await api.get<DriverTrip>(`/driver/trips/${tripId}`);
  return data;
}

// ── Trip lifecycle ───────────────────────────────────────────────────────────

export async function startTrip(
  tripId: string,
  lat: number,
  lng: number,
  odoStart?: number,
): Promise<DriverTrip> {
  const { data } = await api.post(`/driver/trips/${tripId}/start`, { lat, lng, odo_start: odoStart });
  return data.trip as DriverTrip;
}

export async function finishTrip(
  tripId: string,
  lat: number,
  lng: number,
  odoEnd?: number,
): Promise<DriverTrip> {
  const { data } = await api.post(`/driver/trips/${tripId}/finish`, { lat, lng, odo_end: odoEnd });
  return data.trip as DriverTrip;
}

export async function recordGps(
  tripId: string,
  lat: number,
  lng: number,
  speed?: number,
  accuracy?: number,
): Promise<void> {
  await api.post(`/driver/trips/${tripId}/gps`, { lat, lng, speed, accuracy });
}

// ── Stops ────────────────────────────────────────────────────────────────────

export async function fetchStopList(tripId: string): Promise<DeliveryStop[]> {
  const { data } = await api.get<DeliveryStop[]>(`/driver/trips/${tripId}/stops`);
  return data;
}

export async function fetchStopDetail(tripId: string, stopId: string): Promise<DeliveryStopDetail> {
  const { data } = await api.get<DeliveryStopDetail>(`/driver/trips/${tripId}/stops/${stopId}`);
  return data;
}

// ── Delivery actions ─────────────────────────────────────────────────────────

export interface DeliveryActionPayload {
  action_type: string;
  reason?: string;
  notes?: string;
  new_delivery_date?: string;
  corrected_lat?: number;
  corrected_lng?: number;
}

export async function submitDeliveryAction(
  stopId: string,
  payload: DeliveryActionPayload,
): Promise<DeliveryStop> {
  const { data } = await api.post(`/driver/stops/${stopId}/action`, payload);
  return data.stop as DeliveryStop;
}

// ── Canonical delivered-quantity recording (TASK-DRIVER-DELIVERY-ALLOCATION-BRIDGE-001) ──
// `delivered_qty` is the CUMULATIVE absolute total delivered so far for the line — never an
// increment. The backend (RecordProductDeliveryAction) is absolute-set; it refuses a total
// below what is already delivered and beyond the required/on-hand quantity.
export interface StopDeliveryLine {
  order_line_id: string;
  delivered_qty: number;
}

export async function submitStopDelivery(
  stopId: string,
  lines: StopDeliveryLine[],
): Promise<DeliveryStopDetail> {
  const { data } = await api.post(`/driver/stops/${stopId}/deliver`, { lines });
  return data.stop as DeliveryStopDetail;
}

/**
 * SECURE proof of delivery — TASK-DRIVER-APP-FINAL-CLOSURE-002 Part 2.
 *
 * ┌─ WHY THIS REPLACED THE OLD HELPER ───────────────────────────────────────┐
 * │ The previous `submitProofOfDelivery()` POSTed client-supplied STRINGS     │
 * │ (`signature_path`, `photos[]`) to the legacy `/proof` route. A client     │
 * │ that names its own storage path is not proof of anything, and it had no   │
 * │ callers, so nothing is lost by removing it.                              │
 * │                                                                          │
 * │ This sends REAL files to the certified secure endpoint, which stores them │
 * │ under a SERVER-generated private path and validates MIME and size. No     │
 * │ storage path is ever sent by, or returned to, the client.                │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
export async function uploadDeliveryProof(
  stopId: string,
  input: { signature?: File | null; photos?: File[]; notes?: string },
): Promise<DriverDeliveryProofResult> {
  const form = new FormData();

  if (input.signature) {
    form.append('signature', input.signature);
  }

  for (const photo of input.photos ?? []) {
    form.append('photos[]', photo);
  }

  if (input.notes) {
    form.append('notes', input.notes);
  }

  const { data } = await api.post<{ data: DriverDeliveryProofResult }>(
    `/driver/stops/${stopId}/delivery-proof`,
    form,
    // Clear the shared client's default JSON content-type so axios/the browser sets
    // `multipart/form-data` WITH the boundary; a hand-written value has no boundary
    // and the server cannot parse the parts.
    { headers: { 'Content-Type': undefined } },
  );

  return data.data;
}

export async function createException(
  stopId: string,
  payload: { exception_type: string; description: string; photos?: string[] },
): Promise<DeliveryException> {
  const { data } = await api.post(`/driver/stops/${stopId}/exception`, payload);
  return data.exception as DeliveryException;
}

// ── Collections (read-only) ────────────────────────────────────────────────────
// Money COLLECTION is frozen on the driver runtime (POST /driver/stops/{id}/payment
// returns 403). The driver never records a payment; this read-only list is all that
// remains. The former collectPayment() writer was removed (TASK-DRIVER-04 §20).

export async function fetchTripCollections(tripId: string): Promise<unknown[]> {
  const { data } = await api.get<unknown[]>(`/driver/trips/${tripId}/collections`);
  return data;
}

// ── Exceptions ───────────────────────────────────────────────────────────────

export async function fetchTripExceptions(tripId: string): Promise<DeliveryException[]> {
  const { data } = await api.get<DeliveryException[]>(`/driver/trips/${tripId}/exceptions`);
  return data;
}

// ── Returns ──────────────────────────────────────────────────────────────────

export async function fetchTripReturns(tripId: string): Promise<DeliveryReturn[]> {
  const { data } = await api.get<DeliveryReturn[]>(`/driver/trips/${tripId}/returns`);
  return data;
}

export interface AddReturnPayload {
  order_id: number;
  product_id: number;
  product_name: string;
  return_type: string;
  qty: number;
  reason?: string;
  photos?: string[];
}

export async function addReturn(
  tripId: string,
  payload: AddReturnPayload,
): Promise<DeliveryReturn> {
  const { data } = await api.post(`/driver/trips/${tripId}/returns`, payload);
  return data.return as DeliveryReturn;
}

// NOTE: no driver `confirmReturn` — the warehouse RECEIPT of a return is an operator authority
// (POST /api/loading/.../reconciliation/lines/{id}/receive, permission `loading.session.operate`),
// not a driver route. The driver DECLARES returns (addReturn) and reads the warehouse's
// confirmation; it never records the receipt (§3/§13).

// ── Payment method (change during active delivery) ─────────────────────────────
// Bridges into the canonical order authority (ChangeOrderPaymentMethodAction →
// ReevaluateOrderFulfillmentAction). The value is one of the five canonical order methods; the
// backend re-evaluates fulfilment and rejects (422) a change it cannot reconcile.
export async function changePaymentMethod(stopId: string, method: string): Promise<DeliveryStopDetail> {
  const { data } = await api.patch(`/driver/stops/${stopId}/payment-method`, { payment_method: method });
  return data.stop as DeliveryStopDetail;
}

// ── Settlement ───────────────────────────────────────────────────────────────

export async function fetchSettlement(tripId: string): Promise<TripSettlement> {
  const { data } = await api.get<TripSettlement>(`/driver/trips/${tripId}/settlement`);
  return data;
}

export async function submitSettlement(
  tripId: string,
  cashSubmitted: number,
  notes?: string,
): Promise<TripSettlement> {
  const { data } = await api.post(`/driver/trips/${tripId}/settlement/submit`, {
    cash_submitted: cashSubmitted,
    notes,
  });
  return data.settlement as TripSettlement;
}

// ── Custody returns ───────────────────────────────────────────────────────────

export async function fetchCustodyReturns(tripId: string): Promise<CustodyReturn[]> {
  const { data } = await api.get<CustodyReturn[]>(`/driver/trips/${tripId}/custody-returns`);
  return data;
}

export interface RecordCustodyReturnPayload {
  custody_type: string;
  dispatched_qty: number;
  returned_qty: number;
  notes?: string;
}

export async function recordCustodyReturn(
  tripId: string,
  payload: RecordCustodyReturnPayload,
): Promise<CustodyReturn> {
  const { data } = await api.post(`/driver/trips/${tripId}/custody-returns`, payload);
  return data.custody_return as CustodyReturn;
}

// ── Close trip ────────────────────────────────────────────────────────────────

export async function closeTrip(tripId: string): Promise<void> {
  await api.post(`/driver/trips/${tripId}/close`);
}

// ── Timeline ──────────────────────────────────────────────────────────────────

export async function fetchTimeline(tripId: string): Promise<TripTimeline> {
  const { data } = await api.get<TripTimeline>(`/driver/trips/${tripId}/timeline`);
  return data;
}


// ── Group loading (TASK-DRIVER-WAVE-1 Option 1) ──────────────────────────────

export async function fetchLoadingManifest(): Promise<DriverLoadingManifest> {
  const { data } = await api.get<{ data: DriverLoadingManifest }>('/driver/loading');
  return data.data;
}

export async function loadShipmentProduct(
  productId: string,
  quantityLoaded: number,
): Promise<DriverLoadingManifest> {
  const { data } = await api.post<{ data: DriverLoadingManifest }>(
    `/driver/loading/products/${productId}`,
    { quantity_loaded: quantityLoaded },
  );
  return data.data;
}

export async function completeShipmentLoading(): Promise<DriverLoadingManifest> {
  const { data } = await api.post<{ data: DriverLoadingManifest }>('/driver/loading/complete');
  return data.data;
}

// ── Vehicle inventory (read-only) ─────────────────────────────────────────────

/** The driver's OWN vehicle stock (loaded/delivered/returned/on-hand). Read-only. */
export async function fetchVehicleInventory(): Promise<DriverVehicleInventory> {
  const { data } = await api.get<{ data: DriverVehicleInventory }>('/driver/vehicle-inventory');
  return data.data;
}

/**
 * The driver acknowledges what they physically received.
 *
 * This does NOT move the warehouse's Loaded quantity — the driver owns only their own
 * count and confirmation. `expectedLoadedQty` is what this screen displayed; if the
 * warehouse has revised since, the server answers 409 rather than confirming a number
 * the driver never saw.
 */
export async function confirmReceivedProduct(
  productId: string,
  receivedQty: number,
  expectedLoadedQty: number,
): Promise<DriverLoadingManifest> {
  const { data } = await api.post<{ data: DriverLoadingManifest }>(
    `/driver/loading/products/${productId}/confirm`,
    { received_qty: receivedQty, expected_loaded_qty: expectedLoadedQty },
  );
  return data.data;
}

/**
 * The driver reports a different quantity and asks the warehouse to review.
 *
 * A REQUEST, NOT A CHANGE: the canonical Loaded quantity is untouched until the
 * warehouse accepts, edits or rejects.
 */
export async function requestQuantityAdjustment(
  productId: string,
  reportedQty: number,
  expectedLoadedQty: number,
  reason?: string,
): Promise<DriverLoadingManifest> {
  const { data } = await api.post<{ data: DriverLoadingManifest }>(
    `/driver/loading/products/${productId}/adjustment`,
    reason === undefined || reason === ''
      ? { reported_qty: reportedQty, expected_loaded_qty: expectedLoadedQty }
      : { reported_qty: reportedQty, expected_loaded_qty: expectedLoadedQty, reason },
  );
  return data.data;
}

/** Surface the backend 422 message (e.g. over-load refusal) rather than a bare status line. */
export function loadingErrorMessage(err: unknown): string {
  if (axios.isAxiosError(err)) {
    const msg = (err.response?.data as { message?: string } | undefined)?.message;
    return msg ?? err.message;
  }
  return err instanceof Error ? err.message : 'Unknown error';
}


// ── Started Delivery (TASK-DRIVER-WAVE-2, audit §10) ─────────────────────────

export async function startDelivery(stopId: string): Promise<DeliveryStop> {
  const { data } = await api.post(`/driver/stops/${stopId}/start`, {});
  return data.stop as DeliveryStop;
}


// ── Failure vocabulary (TASK-DRIVER-WAVE-2-PHASE-1, Part B) ──────────────────

/** The canonical FailureReason catalogue — the backend enum is the source of truth. */
export async function fetchFailureReasons(): Promise<FailureReasonOption[]> {
  const { data } = await api.get<{ data: FailureReasonOption[] }>('/driver/failure-reasons');
  return data.data;
}


// ── Payment-transfer proof (TASK-DRIVER-WAVE-2-PHASE-1, Part C) ──────────────

/**
 * Upload a payment-transfer proof file for the stop's order into the canonical
 * `payment_proofs` store. Driver-only capability: upload — never verify/settle.
 */
export async function uploadPaymentProof(
  stopId: string,
  file: File,
): Promise<DriverPaymentProofResult> {
  const form = new FormData();
  form.append('file', file);
  const { data } = await api.post<{ data: DriverPaymentProofResult }>(
    `/driver/stops/${stopId}/payment-proof`,
    form,
    // Clear the shared client's default JSON content-type so axios/the browser sets
    // `multipart/form-data` WITH the boundary; a hand-written value has no boundary
    // and the server cannot parse the parts.
    { headers: { 'Content-Type': undefined } },
  );
  return data.data;
}

// ── Wallet + Reports (Phase 6) — driver-scoped, server-derived reads ───────────

function periodParams(p: ReportPeriodValue): Record<string, string> {
  const params: Record<string, string> = { period: p.period };
  if (p.period === 'custom') {
    if (p.from) params.from = p.from;
    if (p.to) params.to = p.to;
  }
  return params;
}

export async function fetchDriverWallet(p: ReportPeriodValue): Promise<DriverWallet> {
  const { data } = await api.get('/driver/wallet', { params: periodParams(p) });
  return data.data as DriverWallet;
}

export async function fetchOrdersReport(p: ReportPeriodValue, page: number): Promise<DriverOrdersReport> {
  const { data } = await api.get('/driver/reports/orders', { params: { ...periodParams(p), page } });
  return data as DriverOrdersReport;
}

export async function fetchGoodsMovement(p: ReportPeriodValue): Promise<DriverGoodsMovement> {
  const { data } = await api.get('/driver/reports/goods-movement', { params: periodParams(p) });
  return data.data as DriverGoodsMovement;
}

export async function fetchShortageReport(p: ReportPeriodValue): Promise<DriverShortageReport> {
  const { data } = await api.get('/driver/reports/shortages', { params: periodParams(p) });
  return data.data as DriverShortageReport;
}

export async function fetchAdvancesReport(): Promise<DriverAdvancesReport> {
  const { data } = await api.get('/driver/reports/advances');
  return data.data as DriverAdvancesReport;
}

export async function fetchDriverStatement(month: string): Promise<DriverStatement> {
  const { data } = await api.get('/driver/statement', { params: { month } });
  return data.data as DriverStatement;
}

// ── Trip Expenses (operational movements) — TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §30–§43 ──
// Driver-scoped to the CURRENT active custody; the server resolves company/driver/trip (never the
// client). Read + create only — the driver never approves/settles.

export async function fetchTripExpenses(): Promise<TripExpensesResponse> {
  const { data } = await api.get('/driver/trip-expenses');
  return data.data as TripExpensesResponse;
}

export async function createTripExpense(input: CreateTripExpenseInput): Promise<TripExpense> {
  const form = new FormData();
  form.append('category', input.category);
  form.append('amount', String(input.amount));
  if (input.note) {
    form.append('note', input.note);
  }
  if (input.occurred_at) {
    form.append('occurred_at', input.occurred_at);
  }
  if (input.receipt) {
    form.append('receipt', input.receipt);
  }
  const { data } = await api.post<{ data: TripExpense }>(
    '/driver/trip-expenses',
    form,
    // Clear the shared client's default JSON content-type so the browser sets
    // multipart/form-data WITH the boundary (same idiom as the proof uploads).
    { headers: { 'Content-Type': undefined } },
  );
  return data.data;
}
