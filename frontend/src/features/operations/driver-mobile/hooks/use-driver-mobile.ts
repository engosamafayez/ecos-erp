import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { useToast } from '@/components/ds/use-toast';
import * as svc from '../services/driver-mobile-service';
import type { AddReturnPayload, DeliveryActionPayload, RecordCustodyReturnPayload, StopDeliveryLine } from '../services/driver-mobile-service';
import type { ReportPeriodValue } from '../types/reports';
import type { CreateTripExpenseInput } from '../types/trip-expenses';

const K = {
  trips:       'driver-trips',
  trip:        (id: string) => ['driver-trip', id] as const,
  stops:       (id: string) => ['driver-stops', id] as const,
  stopDetail:  (tripId: string, stopId: string) => ['driver-stop-detail', tripId, stopId] as const,
  collections: (id: string) => ['driver-collections', id] as const,
  exceptions:  (id: string) => ['driver-exceptions', id] as const,
  returns:     (id: string) => ['driver-returns', id] as const,
  settlement:  (id: string) => ['driver-settlement', id] as const,
  custody:     (id: string) => ['driver-custody', id] as const,
  timeline:    (id: string) => ['driver-timeline', id] as const,
};

// ── Active trips ─────────────────────────────────────────────────────────────

export function useDriverTrips() {
  return useQuery({
    queryKey: [K.trips],
    queryFn:  () => svc.fetchActiveTrips(),
    refetchInterval: 30_000,
  });
}

// ── Trip dashboard ────────────────────────────────────────────────────────────

export function useDriverTrip(tripId: string) {
  return useQuery({
    queryKey: K.trip(tripId),
    queryFn:  () => svc.fetchTripDashboard(tripId),
    enabled:  Boolean(tripId),
    refetchInterval: 20_000,
  });
}

// ── Stop list ─────────────────────────────────────────────────────────────────

export function useDriverStops(tripId: string) {
  return useQuery({
    queryKey: K.stops(tripId),
    queryFn:  () => svc.fetchStopList(tripId),
    enabled:  Boolean(tripId),
    refetchInterval: 15_000,
  });
}

// ── Stop detail ───────────────────────────────────────────────────────────────

export function useDriverStopDetail(tripId: string, stopId: string) {
  return useQuery({
    queryKey: K.stopDetail(tripId, stopId),
    queryFn:  () => svc.fetchStopDetail(tripId, stopId),
    enabled:  Boolean(tripId) && Boolean(stopId),
  });
}

// ── Start trip ────────────────────────────────────────────────────────────────

export function useStartTrip(tripId: string) {
  const qc = useQueryClient();
  const { toast } = useToast();

  return useMutation({
    mutationFn: ({ lat, lng, odoStart }: { lat: number; lng: number; odoStart?: number }) =>
      svc.startTrip(tripId, lat, lng, odoStart),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: K.trip(tripId) });
      void qc.invalidateQueries({ queryKey: K.stops(tripId) });
      toast({ title: 'Trip started', description: 'Delivery stops are now active.' });
    },
    onError: (err: Error) => {
      toast({ title: 'Failed to start trip', description: err.message, variant: 'destructive' });
    },
  });
}

// ── Finish trip ───────────────────────────────────────────────────────────────

export function useFinishTrip(tripId: string) {
  const qc = useQueryClient();
  const { toast } = useToast();

  return useMutation({
    mutationFn: ({ lat, lng, odoEnd }: { lat: number; lng: number; odoEnd?: number }) =>
      svc.finishTrip(tripId, lat, lng, odoEnd),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: K.trip(tripId) });
      void qc.invalidateQueries({ queryKey: [K.trips] });
      toast({ title: 'Trip finished', description: 'Proceed to settlement.' });
    },
    onError: (err: Error) => {
      toast({ title: 'Cannot finish trip', description: err.message, variant: 'destructive' });
    },
  });
}

// ── Record delivered quantities (canonical, cumulative) ────────────────────────

export function useSubmitStopDelivery(tripId: string, stopId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (lines: StopDeliveryLine[]) => svc.submitStopDelivery(stopId, lines),
    // No optimistic state: re-read the CANONICAL stop detail (and the lists) so delivered /
    // remaining quantities and the stop status reflect exactly what the backend committed.
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: K.stopDetail(tripId, stopId) });
      void qc.invalidateQueries({ queryKey: K.stops(tripId) });
      void qc.invalidateQueries({ queryKey: [K.trips] });
    },
  });
}

// ── Submit delivery action ────────────────────────────────────────────────────

export function useSubmitDeliveryAction(stopId: string) {
  const qc = useQueryClient();
  const { toast } = useToast();

  return useMutation({
    mutationFn: (payload: DeliveryActionPayload) => svc.submitDeliveryAction(stopId, payload),
    onSuccess: (_data, variables) => {
      void qc.invalidateQueries({ queryKey: [K.trips] });
      toast({
        title: 'Delivery recorded',
        description: `Action: ${variables.action_type}`,
      });
    },
    onError: (err: Error) => {
      toast({ title: 'Failed to record delivery', description: err.message, variant: 'destructive' });
    },
  });
}

// ── Trip collections ──────────────────────────────────────────────────────────

export function useTripCollections(tripId: string) {
  return useQuery({
    queryKey: K.collections(tripId),
    queryFn:  () => svc.fetchTripCollections(tripId),
    enabled:  Boolean(tripId),
  });
}

// ── Trip exceptions ───────────────────────────────────────────────────────────

export function useTripExceptions(tripId: string) {
  return useQuery({
    queryKey: K.exceptions(tripId),
    queryFn:  () => svc.fetchTripExceptions(tripId),
    enabled:  Boolean(tripId),
  });
}

// ── Trip returns ──────────────────────────────────────────────────────────────

export function useTripReturns(tripId: string) {
  return useQuery({
    queryKey: K.returns(tripId),
    queryFn:  () => svc.fetchTripReturns(tripId),
    enabled:  Boolean(tripId),
  });
}

// ── Add return ────────────────────────────────────────────────────────────────

export function useAddReturn(tripId: string) {
  const qc = useQueryClient();
  const { toast } = useToast();

  return useMutation({
    mutationFn: (payload: AddReturnPayload) => svc.addReturn(tripId, payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: K.returns(tripId) });
      toast({ title: 'Return recorded' });
    },
    onError: (err: Error) => {
      toast({ title: 'Failed to record return', description: err.message, variant: 'destructive' });
    },
  });
}

// ── Trip settlement ───────────────────────────────────────────────────────────

export function useTripSettlement(tripId: string) {
  return useQuery({
    queryKey: K.settlement(tripId),
    queryFn:  () => svc.fetchSettlement(tripId),
    enabled:  Boolean(tripId),
  });
}

// ── Submit settlement ─────────────────────────────────────────────────────────

export function useSubmitSettlement(tripId: string) {
  const qc = useQueryClient();
  const { toast } = useToast();

  return useMutation({
    mutationFn: ({ cashSubmitted, notes }: { cashSubmitted: number; notes?: string }) =>
      svc.submitSettlement(tripId, cashSubmitted, notes),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: K.settlement(tripId) });
      toast({ title: 'Settlement submitted' });
    },
    onError: (err: Error) => {
      toast({ title: 'Settlement failed', description: err.message, variant: 'destructive' });
    },
  });
}

// ── Custody returns ───────────────────────────────────────────────────────────

export function useCustodyReturns(tripId: string) {
  return useQuery({
    queryKey: K.custody(tripId),
    queryFn:  () => svc.fetchCustodyReturns(tripId),
    enabled:  Boolean(tripId),
  });
}

export function useRecordCustodyReturn(tripId: string) {
  const qc = useQueryClient();
  const { toast } = useToast();

  return useMutation({
    mutationFn: (payload: RecordCustodyReturnPayload) => svc.recordCustodyReturn(tripId, payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: K.custody(tripId) });
      toast({ title: 'Custody return recorded' });
    },
    onError: (err: Error) => {
      toast({ title: 'Failed', description: err.message, variant: 'destructive' });
    },
  });
}

// ── Close trip ────────────────────────────────────────────────────────────────

export function useCloseTrip(tripId: string) {
  const qc = useQueryClient();
  const { toast } = useToast();

  return useMutation({
    mutationFn: () => svc.closeTrip(tripId),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: K.trip(tripId) });
      void qc.invalidateQueries({ queryKey: [K.trips] });
      toast({ title: 'Trip closed' });
    },
    onError: (err: Error) => {
      toast({ title: 'Cannot close trip', description: err.message, variant: 'destructive' });
    },
  });
}

// ── Timeline ──────────────────────────────────────────────────────────────────

export function useTripTimeline(tripId: string) {
  return useQuery({
    queryKey: K.timeline(tripId),
    queryFn:  () => svc.fetchTimeline(tripId),
    enabled:  Boolean(tripId),
  });
}

// ── Create exception ──────────────────────────────────────────────────────────

export function useCreateException(stopId: string) {
  const qc = useQueryClient();
  const { toast } = useToast();

  return useMutation({
    mutationFn: (payload: { exception_type: string; description: string; photos?: string[] }) =>
      svc.createException(stopId, payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [K.trips] });
      toast({ title: 'Exception recorded' });
    },
    onError: (err: Error) => {
      toast({ title: 'Failed', description: err.message, variant: 'destructive' });
    },
  });
}

// NOTE: there is no driver "confirm return" hook. Recording a warehouse's RECEIPT of a
// return (actual received / accepted / damaged / shortage) is a Warehouse-operator authority
// under its own permission — never the driver's (§3/§13). The driver only DECLARES a return
// via useAddReturn; the warehouse confirmation is shown read-only on the returns screen.

// ── Group loading (TASK-DRIVER-WAVE-1 Option 1) ──────────────────────────────

export function useDriverLoading() {
  return useQuery({
    queryKey: ['driver-loading'],
    queryFn: () => svc.fetchLoadingManifest(),
    refetchInterval: 30_000,
  });
}

/** The driver's OWN vehicle inventory (read-only). */
export function useVehicleInventory() {
  return useQuery({
    queryKey: ['driver-vehicle-inventory'],
    queryFn: () => svc.fetchVehicleInventory(),
    refetchInterval: 30_000,
  });
}

/**
 * The driver confirms RECEIPT of what they counted.
 *
 * Distinct from `useLoadShipmentProduct`, which sets the warehouse's Loaded quantity:
 * this writes only the driver's own count and confirmation. The server returns the
 * refreshed manifest, so Difference and the workflow state come back canonical rather
 * than being recomputed here.
 */
export function useConfirmReceivedProduct() {
  const qc = useQueryClient();
  const { toast } = useToast();
  const { t } = useTranslation('driver-mobile');

  return useMutation({
    mutationFn: ({
      productId,
      receivedQty,
      expectedLoadedQty,
    }: {
      productId: string;
      receivedQty: number;
      expectedLoadedQty: number;
    }) => svc.confirmReceivedProduct(productId, receivedQty, expectedLoadedQty),
    onSuccess: (data) => {
      qc.setQueryData(['driver-loading'], data);
    },
    onError: (err: unknown) => {
      toast({
        title: t(($) => $.loadingScreen.toasts.loadFailed),
        description: svc.loadingErrorMessage(err),
        variant: 'destructive',
      });
    },
  });
}

/**
 * The driver asks the warehouse to review a discrepancy.
 *
 * A REQUEST, NOT A CHANGE — the warehouse quantity is untouched until the warehouse
 * accepts, edits or rejects.
 */
export function useRequestQuantityAdjustment() {
  const qc = useQueryClient();
  const { toast } = useToast();
  const { t } = useTranslation('driver-mobile');

  return useMutation({
    mutationFn: ({
      productId,
      reportedQty,
      expectedLoadedQty,
      reason,
    }: {
      productId: string;
      reportedQty: number;
      expectedLoadedQty: number;
      reason?: string;
    }) => svc.requestQuantityAdjustment(productId, reportedQty, expectedLoadedQty, reason),
    onSuccess: (data) => {
      qc.setQueryData(['driver-loading'], data);
    },
    onError: (err: unknown) => {
      toast({
        title: t(($) => $.loadingScreen.toasts.loadFailed),
        description: svc.loadingErrorMessage(err),
        variant: 'destructive',
      });
    },
  });
}

export function useLoadShipmentProduct() {
  const qc = useQueryClient();
  const { toast } = useToast();
  const { t } = useTranslation('driver-mobile');

  return useMutation({
    mutationFn: ({ productId, quantityLoaded }: { productId: string; quantityLoaded: number }) =>
      svc.loadShipmentProduct(productId, quantityLoaded),
    onSuccess: (data) => {
      qc.setQueryData(['driver-loading'], data);
    },
    onError: (err: unknown) => {
      toast({ title: t(($) => $.loadingScreen.toasts.loadFailed), description: svc.loadingErrorMessage(err), variant: 'destructive' });
    },
  });
}

export function useCompleteShipmentLoading() {
  const qc = useQueryClient();
  const { toast } = useToast();
  const { t } = useTranslation('driver-mobile');

  return useMutation({
    mutationFn: () => svc.completeShipmentLoading(),
    onSuccess: (data) => {
      qc.setQueryData(['driver-loading'], data);
      void qc.invalidateQueries({ queryKey: [K.trips] });
      toast({ title: t(($) => $.loadingScreen.toasts.completed) });
    },
    onError: (err: unknown) => {
      toast({ title: t(($) => $.loadingScreen.toasts.completeFailed), description: svc.loadingErrorMessage(err), variant: 'destructive' });
    },
  });
}


// ── Started Delivery (TASK-DRIVER-WAVE-2, audit §10) ─────────────────────────

export function useStartDelivery(tripId: string, stopId: string) {
  const qc = useQueryClient();
  const { toast } = useToast();
  const { t } = useTranslation('driver-mobile');

  return useMutation({
    mutationFn: () => svc.startDelivery(stopId),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: K.stopDetail(tripId, stopId) });
      void qc.invalidateQueries({ queryKey: K.stops(tripId) });
    },
    onError: (err: unknown) => {
      toast({ title: t(($) => $.stop.startFailed), description: svc.loadingErrorMessage(err), variant: 'destructive' });
    },
  });
}


// ── Failure vocabulary (TASK-DRIVER-WAVE-2-PHASE-1, Part B) ──────────────────

export function useFailureReasons() {
  return useQuery({
    queryKey: ['driver-failure-reasons'],
    queryFn: () => svc.fetchFailureReasons(),
    staleTime: 60 * 60 * 1000, // the canonical enum is effectively static
  });
}


// ── Payment-transfer proof (TASK-DRIVER-WAVE-2-PHASE-1, Part C) ──────────────

/**
 * SECURE proof of delivery upload — TASK-DRIVER-APP-FINAL-CLOSURE-002 Part 2.
 *
 * Sends real files to the certified `/delivery-proof` endpoint. The driver may only
 * UPLOAD; retrieval is through the tenant-scoped download route, and no storage path
 * is ever held by the client.
 */
export function useUploadDeliveryProof(tripId: string, stopId: string) {
  const qc = useQueryClient();
  const { toast } = useToast();
  const { t } = useTranslation('driver-mobile');

  return useMutation({
    mutationFn: (input: { signature?: File | null; photos?: File[]; notes?: string }) =>
      svc.uploadDeliveryProof(stopId, input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: K.stopDetail(tripId, stopId) });
      toast({ title: t(($) => $.stop.deliveryProof.uploaded) });
    },
    onError: (err: unknown) => {
      toast({
        title: t(($) => $.stop.deliveryProof.uploadFailed),
        description: svc.loadingErrorMessage(err),
        variant: 'destructive',
      });
    },
  });
}

export function useUploadPaymentProof(tripId: string, stopId: string) {
  const qc = useQueryClient();
  const { toast } = useToast();
  const { t } = useTranslation('driver-mobile');

  return useMutation({
    mutationFn: (file: File) => svc.uploadPaymentProof(stopId, file),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: K.stopDetail(tripId, stopId) });
      toast({ title: t(($) => $.stop.paymentProof.uploaded) });
    },
    onError: (err: unknown) => {
      toast({ title: t(($) => $.stop.paymentProof.uploadFailed), description: svc.loadingErrorMessage(err), variant: 'destructive' });
    },
  });
}

/**
 * Change the order's payment method during an active delivery — TASK-DRIVER-APP-PHASE-4-
 * PAYMENT-METHOD-CLOSURE-001. The backend re-evaluates fulfilment canonically and may reject the
 * change (422); on either outcome we reload canonical stop/order truth so the UI never shows a
 * method the backend did not accept (§8/§10).
 */
export function useChangePaymentMethod(tripId: string, stopId: string) {
  const qc = useQueryClient();
  const { toast } = useToast();
  const { t } = useTranslation('driver-mobile');

  return useMutation({
    mutationFn: (method: string) => svc.changePaymentMethod(stopId, method),
    onSuccess: () => {
      toast({ title: t(($) => $.stop.changeMethod.updated) });
    },
    onError: (err: unknown) => {
      toast({ title: t(($) => $.stop.changeMethod.failed), description: svc.loadingErrorMessage(err), variant: 'destructive' });
    },
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: K.stopDetail(tripId, stopId) });
      void qc.invalidateQueries({ queryKey: K.stops(tripId) });
    },
  });
}

// ── Wallet + Reports (Phase 6) — driver-scoped server reads ────────────────────

export function useDriverWallet(period: ReportPeriodValue) {
  return useQuery({ queryKey: ['driver-wallet', period], queryFn: () => svc.fetchDriverWallet(period) });
}

export function useDriverOrdersReport(period: ReportPeriodValue, page: number) {
  return useQuery({
    queryKey: ['driver-orders-report', period, page],
    queryFn: () => svc.fetchOrdersReport(period, page),
    placeholderData: keepPreviousData,
  });
}

export function useDriverGoodsMovement(period: ReportPeriodValue) {
  return useQuery({ queryKey: ['driver-goods-movement', period], queryFn: () => svc.fetchGoodsMovement(period) });
}

export function useDriverShortages(period: ReportPeriodValue) {
  return useQuery({ queryKey: ['driver-shortages', period], queryFn: () => svc.fetchShortageReport(period) });
}

export function useDriverAdvances() {
  return useQuery({ queryKey: ['driver-advances'], queryFn: () => svc.fetchAdvancesReport() });
}

export function useDriverStatement(month: string) {
  return useQuery({ queryKey: ['driver-statement', month], queryFn: () => svc.fetchDriverStatement(month) });
}

// ── Trip Expenses (operational movements) — TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §30–§43 ──

export function useTripExpenses() {
  return useQuery({ queryKey: ['driver-trip-expenses'], queryFn: () => svc.fetchTripExpenses() });
}

export function useCreateTripExpense() {
  const qc = useQueryClient();
  const { toast } = useToast();
  const { t } = useTranslation('driver-mobile');

  return useMutation({
    mutationFn: (input: CreateTripExpenseInput) => svc.createTripExpense(input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['driver-trip-expenses'] });
      toast({ title: t(($) => $.tripExpenses.created) });
    },
    onError: (err: unknown) => {
      toast({
        title: t(($) => $.tripExpenses.createFailed),
        description: svc.loadingErrorMessage(err),
        variant: 'destructive',
      });
    },
  });
}
