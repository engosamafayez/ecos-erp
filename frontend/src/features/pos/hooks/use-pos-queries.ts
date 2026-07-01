import {
  useQuery,
  useMutation,
  useQueryClient,
} from '@tanstack/react-query';
import { usePosStore } from '@/features/pos/store/pos-store';
import {
  sessionService,
  shiftService,
  cartService,
  saleService,
  returnService,
  exchangeService,
  receiptService,
  catalogService,
} from '@/features/pos/services/pos-service';
import type {
  OpenSessionPayload,
  OpenShiftPayload,
  CloseShiftPayload,
  ApproveShiftPayload,
  RejectShiftPayload,
  OpenCartPayload,
  AddCartLinePayload,
  ProcessSalePayload,
  ProcessReturnPayload,
  ProcessExchangePayload,
} from '@/features/pos/types';

// ── Query keys ────────────────────────────────────────────────────────────────

export const posKeys = {
  session:  (id: string) => ['pos', 'session', id] as const,
  shift:    (id: string) => ['pos', 'shift', id] as const,
  cart:     (id: string) => ['pos', 'cart', id] as const,
  sale:     (id: string) => ['pos', 'sale', id] as const,
  receipt:  (id: string) => ['pos', 'receipt', id] as const,
  catalog:  (params: object) => ['pos', 'catalog', params] as const,
};

// ── Session ───────────────────────────────────────────────────────────────────

export function useSession() {
  const sessionId = usePosStore((s) => s.sessionId);
  return useQuery({
    queryKey: posKeys.session(sessionId ?? ''),
    queryFn:  () => sessionService.get(sessionId!),
    enabled:  !!sessionId,
    staleTime: 30_000,
  });
}

export function useOpenSession() {
  const { setSession, setCashier } = usePosStore();
  return useMutation({
    mutationFn: (payload: OpenSessionPayload) => sessionService.open(payload),
    onSuccess:  (session) => {
      setSession(session.id);
      setCashier(session.cashier_id, '');
    },
  });
}

export function useCloseSession() {
  const { reset } = usePosStore();
  return useMutation({
    mutationFn: (sessionId: string) => sessionService.close(sessionId),
    onSuccess:  () => reset(),
  });
}

// ── Shift ─────────────────────────────────────────────────────────────────────

export function useShift() {
  const shiftId = usePosStore((s) => s.shiftId);
  return useQuery({
    queryKey: posKeys.shift(shiftId ?? ''),
    queryFn:  () => shiftService.get(shiftId!),
    enabled:  !!shiftId,
    staleTime: 30_000,
  });
}

export function useOpenShift() {
  const { setShift } = usePosStore();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: OpenShiftPayload) => shiftService.open(payload),
    onSuccess:  (shift) => {
      setShift(shift.id);
      qc.setQueryData(posKeys.shift(shift.id), shift);
    },
  });
}

export function useCloseShift() {
  const qc = useQueryClient();
  const { setShift } = usePosStore();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: CloseShiftPayload }) =>
      shiftService.close(id, payload),
    onSuccess: (shift) => {
      qc.setQueryData(posKeys.shift(shift.id), shift);
      if (shift.status === 'closed') setShift(null);
    },
  });
}

export function useApproveShift() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ApproveShiftPayload }) =>
      shiftService.approve(id, payload),
    onSuccess: (shift) => qc.setQueryData(posKeys.shift(shift.id), shift),
  });
}

export function useRejectShift() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: RejectShiftPayload }) =>
      shiftService.reject(id, payload),
    onSuccess: (shift) => qc.setQueryData(posKeys.shift(shift.id), shift),
  });
}

// ── Cart ──────────────────────────────────────────────────────────────────────

export function useCart() {
  const cartId = usePosStore((s) => s.cartId);
  return useQuery({
    queryKey: posKeys.cart(cartId ?? ''),
    queryFn:  () => cartService.get(cartId!),
    enabled:  !!cartId,
    staleTime: 0,
    refetchOnWindowFocus: false,
  });
}

export function useOpenCart() {
  const { setCart } = usePosStore();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: OpenCartPayload) => cartService.open(payload),
    onSuccess:  (cart) => {
      setCart(cart.id);
      qc.setQueryData(posKeys.cart(cart.id), cart);
    },
  });
}

export function useAddCartLine() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ cartId, payload }: { cartId: string; payload: AddCartLinePayload }) =>
      cartService.addLine(cartId, payload),
    onSuccess: (cart) => qc.setQueryData(posKeys.cart(cart.id), cart),
  });
}

export function useRemoveCartLine() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ cartId, lineId }: { cartId: string; lineId: string }) =>
      cartService.removeLine(cartId, lineId),
    onSuccess: (cart) => qc.setQueryData(posKeys.cart(cart.id), cart),
  });
}

export function useHoldCart() {
  const qc = useQueryClient();
  const { clearTransaction } = usePosStore();
  return useMutation({
    mutationFn: (cartId: string) => cartService.hold(cartId),
    onSuccess: (cart) => {
      qc.setQueryData(posKeys.cart(cart.id), cart);
      clearTransaction();
    },
  });
}

export function useCancelCart() {
  const qc = useQueryClient();
  const { clearTransaction } = usePosStore();
  return useMutation({
    mutationFn: (cartId: string) => cartService.cancel(cartId),
    onSuccess: (cart) => {
      qc.removeQueries({ queryKey: posKeys.cart(cart.id) });
      clearTransaction();
    },
  });
}

// ── Sale ──────────────────────────────────────────────────────────────────────

export function useProcessSale() {
  const { clearTransaction, setLastReceipt } = usePosStore();
  return useMutation({
    mutationFn: (payload: ProcessSalePayload) => saleService.process(payload),
    onSuccess: (result) => {
      setLastReceipt(result.receipt_id);
      clearTransaction();
    },
  });
}

export function useSale(id: string | null) {
  return useQuery({
    queryKey: posKeys.sale(id ?? ''),
    queryFn:  () => saleService.get(id!),
    enabled:  !!id,
    staleTime: 60_000,
  });
}

// ── Return ────────────────────────────────────────────────────────────────────

export function useProcessReturn() {
  const { clearTransaction, setLastReceipt } = usePosStore();
  return useMutation({
    mutationFn: (payload: ProcessReturnPayload) => returnService.process(payload),
    onSuccess: (result) => {
      setLastReceipt(result.receipt_id);
      clearTransaction();
    },
  });
}

// ── Exchange ──────────────────────────────────────────────────────────────────

export function useProcessExchange() {
  const { clearTransaction, setLastReceipt } = usePosStore();
  return useMutation({
    mutationFn: (payload: ProcessExchangePayload) => exchangeService.process(payload),
    onSuccess: (result) => {
      setLastReceipt(result.receipt_id);
      clearTransaction();
    },
  });
}

// ── Receipt ───────────────────────────────────────────────────────────────────

export function useReceipt(id: string | null) {
  return useQuery({
    queryKey: posKeys.receipt(id ?? ''),
    queryFn:  () => receiptService.get(id!),
    enabled:  !!id,
    staleTime: 60_000,
  });
}

export function useReprintReceipt() {
  return useMutation({
    mutationFn: (id: string) => receiptService.reprint(id),
  });
}

// ── Product catalog ───────────────────────────────────────────────────────────

export function useCatalog(params: { search?: string; category_id?: string; page?: number }) {
  return useQuery({
    queryKey: posKeys.catalog(params),
    queryFn:  () => catalogService.list({ ...params, per_page: 48 }),
    staleTime: 60_000,
    placeholderData: (prev) => prev,
  });
}
