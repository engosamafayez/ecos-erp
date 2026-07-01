import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { PosMode } from '@/features/pos/types';

const POS_STORAGE_KEY = 'ecos_pos_context';

export type HeldCartSnapshot = {
  cartId: string;
  customerName: string | null;
  total: string;
  currency: string;
  lineCount: number;
  heldAt: string;
};

type PosState = {
  // Persisted operational context
  sessionId: string | null;
  shiftId: string | null;
  cartId: string | null;
  terminalId: string;
  cashierId: string;
  cashierName: string;
  currency: string;

  // Persisted held cart snapshots for this terminal
  heldCartSnapshots: HeldCartSnapshot[];

  // Current operational mode
  mode: PosMode;

  // Active customer for the current transaction
  activeCustomerId: string | null;
  activeCustomerName: string | null;

  // UI state
  keyboardHelpOpen: boolean;
  paymentPanelOpen: boolean;
  returnSaleId: string | null;
  exchangeSaleId: string | null;
  lastReceiptId: string | null;

  // Ctrl+K — customer search focus request (incremented to trigger focus)
  customerSearchTick: number;

  // Actions
  setSession: (id: string | null) => void;
  setShift: (id: string | null) => void;
  setCart: (id: string | null) => void;
  setTerminal: (id: string, name?: string) => void;
  setCashier: (id: string, name: string) => void;
  setCurrency: (currency: string) => void;
  setMode: (mode: PosMode) => void;
  setCustomer: (id: string | null, name: string | null) => void;
  openPayment: () => void;
  closePayment: () => void;
  openReturn: (saleId: string) => void;
  openExchange: (saleId: string) => void;
  clearTransaction: () => void;
  setLastReceipt: (id: string | null) => void;
  toggleKeyboardHelp: () => void;
  tickCustomerSearch: () => void;
  addHeldCartSnapshot: (snapshot: HeldCartSnapshot) => void;
  removeHeldCartSnapshot: (cartId: string) => void;
  reset: () => void;
};

export const usePosStore = create<PosState>()(
  persist(
    (set) => ({
      // Default persisted context
      sessionId:          null,
      shiftId:            null,
      cartId:             null,
      terminalId:         '',
      cashierId:          '',
      cashierName:        '',
      currency:           'EGP',
      heldCartSnapshots:  [],

      // Runtime state (not persisted)
      mode:                'sale',
      activeCustomerId:    null,
      activeCustomerName:  null,
      keyboardHelpOpen:    false,
      paymentPanelOpen:    false,
      returnSaleId:        null,
      exchangeSaleId:      null,
      lastReceiptId:       null,
      customerSearchTick:  0,

      // Actions
      setSession:  (id) => set({ sessionId: id }),
      setShift:    (id) => set({ shiftId: id }),
      setCart:     (id) => set({ cartId: id }),
      setTerminal: (id) => set({ terminalId: id }),
      setCashier:  (id, name) => set({ cashierId: id, cashierName: name }),
      setCurrency: (currency) => set({ currency }),

      setMode: (mode) =>
        set({ mode, returnSaleId: null, exchangeSaleId: null, paymentPanelOpen: false }),

      setCustomer: (id, name) =>
        set({ activeCustomerId: id, activeCustomerName: name }),

      openPayment:  () => set({ paymentPanelOpen: true }),
      closePayment: () => set({ paymentPanelOpen: false }),

      openReturn: (saleId) =>
        set({ mode: 'return', returnSaleId: saleId, paymentPanelOpen: false }),

      openExchange: (saleId) =>
        set({ mode: 'exchange', exchangeSaleId: saleId, paymentPanelOpen: false }),

      clearTransaction: () =>
        set({
          cartId:              null,
          activeCustomerId:    null,
          activeCustomerName:  null,
          paymentPanelOpen:    false,
          returnSaleId:        null,
          exchangeSaleId:      null,
          mode:                'sale',
        }),

      setLastReceipt:     (id) => set({ lastReceiptId: id }),
      toggleKeyboardHelp: ()  => set((s) => ({ keyboardHelpOpen: !s.keyboardHelpOpen })),
      tickCustomerSearch: ()  => set((s) => ({ customerSearchTick: s.customerSearchTick + 1 })),

      addHeldCartSnapshot: (snapshot) =>
        set((s) => ({
          heldCartSnapshots: [
            ...s.heldCartSnapshots.filter((h) => h.cartId !== snapshot.cartId),
            snapshot,
          ],
        })),

      removeHeldCartSnapshot: (cartId) =>
        set((s) => ({
          heldCartSnapshots: s.heldCartSnapshots.filter((h) => h.cartId !== cartId),
        })),

      reset: () =>
        set({
          sessionId:           null,
          shiftId:             null,
          cartId:              null,
          mode:                'sale',
          activeCustomerId:    null,
          activeCustomerName:  null,
          keyboardHelpOpen:    false,
          paymentPanelOpen:    false,
          returnSaleId:        null,
          exchangeSaleId:      null,
          lastReceiptId:       null,
          heldCartSnapshots:   [],
        }),
    }),
    {
      name: POS_STORAGE_KEY,
      partialize: (state) => ({
        sessionId:          state.sessionId,
        shiftId:            state.shiftId,
        cartId:             state.cartId,
        terminalId:         state.terminalId,
        cashierId:          state.cashierId,
        cashierName:        state.cashierName,
        currency:           state.currency,
        heldCartSnapshots:  state.heldCartSnapshots,
      }),
    },
  ),
);
