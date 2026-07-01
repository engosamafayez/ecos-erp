import { useState, useCallback } from 'react';
import { AnimatePresence, motion } from 'framer-motion';

import { cn } from '@/lib/utils';
import { PosHeader } from '@/features/pos/components/pos-header';
import { ProductGrid } from '@/features/pos/components/product-grid';
import { CartPanel } from '@/features/pos/components/cart-panel';
import { PaymentPanel } from '@/features/pos/components/payment-panel';
import { ReceiptPanel } from '@/features/pos/components/receipt-panel';
import { ReturnPanel } from '@/features/pos/components/return-panel';
import { ExchangePanel } from '@/features/pos/components/exchange-panel';
import { ManagerPanel } from '@/features/pos/components/manager-panel';
import { CustomerPanel } from '@/features/pos/components/customer-panel';
import { KeyboardHelp } from '@/features/pos/components/keyboard-help';
import { useBarcodeScanner } from '@/features/pos/hooks/use-barcode-scanner';
import { useKeyboardShortcuts } from '@/features/pos/hooks/use-keyboard-shortcuts';
import { useAddCartLine, useOpenCart } from '@/features/pos/hooks/use-pos-queries';
import { usePosStore } from '@/features/pos/store/pos-store';
import type { PosMode, Product } from '@/features/pos/types';

// Right panel state — what's showing in the right sidebar
type RightPanel = 'cart' | 'payment' | 'receipt' | 'return' | 'exchange';

export function PosWorkspace() {
  const {
    mode, setMode, cartId, shiftId, sessionId,
    terminalId, cashierId, currency,
    paymentPanelOpen, openPayment, closePayment,
    lastReceiptId, setLastReceipt,
    toggleKeyboardHelp,
    clearTransaction,
  } = usePosStore();

  const [rightPanel, setRightPanel] = useState<RightPanel>('cart');

  const addLine     = useAddCartLine();
  const openCart    = useOpenCart();

  // ── Barcode scanner ────────────────────────────────────────────────────────
  const onScan = useCallback(
    async (barcode: string) => {
      // Lookup the product by SKU/barcode and add it to cart
      // The catalog endpoint is called inline; a full implementation would
      // use a dedicated barcode-lookup endpoint.
      console.info('[POS] Barcode scanned:', barcode);
    },
    [],
  );

  useBarcodeScanner({ onScan, enabled: mode === 'sale' });

  // ── Keyboard shortcuts ─────────────────────────────────────────────────────
  useKeyboardShortcuts([
    {
      key: 'f8',
      description: 'Open payment',
      handler: () => { if (mode === 'sale') { openPayment(); setRightPanel('payment'); } },
    },
    {
      key: 'f9',
      description: 'Hold cart',
      handler: () => {},
    },
    {
      key: 'Escape',
      description: 'Cancel / close',
      handler: () => {
        if (rightPanel === 'payment') { closePayment(); setRightPanel('cart'); }
        else if (rightPanel !== 'cart') setRightPanel('cart');
      },
    },
    {
      key: 'n',
      ctrl: true,
      description: 'New sale',
      handler: () => { clearTransaction(); setMode('sale'); setRightPanel('cart'); },
    },
    {
      key: 'r',
      ctrl: true,
      description: 'Return mode',
      handler: () => { setMode('return'); setRightPanel('return'); },
    },
    {
      key: 'e',
      ctrl: true,
      description: 'Exchange mode',
      handler: () => { setMode('exchange'); setRightPanel('exchange'); },
    },
    {
      key: '1',
      alt: true,
      description: 'Sale mode',
      handler: () => { setMode('sale'); setRightPanel('cart'); },
    },
    {
      key: 'm',
      ctrl: true,
      description: 'Manager view',
      handler: () => setMode('manager'),
    },
    {
      key: '?',
      shift: true,
      description: 'Keyboard help',
      handler: () => toggleKeyboardHelp(),
    },
  ]);

  // ── Product add to cart ────────────────────────────────────────────────────
  async function handleProductSelect(product: Product) {
    if (mode !== 'sale') return;

    let cId = cartId;

    if (!cId && sessionId && shiftId) {
      const cart = await openCart.mutateAsync({
        session_id: sessionId,
        shift_id:   shiftId,
        terminal_id: terminalId,
        cashier_id:  cashierId,
        currency,
      });
      cId = cart.id;
    }

    if (!cId) return;

    await addLine.mutateAsync({
      cartId: cId,
      payload: {
        product_id:   product.id,
        product_name: product.name,
        sku:          product.sku,
        quantity:     '1',
        unit_price:   String(product.selling_price ?? 0),
        currency:     product.currency ?? currency,
      },
    });
  }

  // ── Mode change ────────────────────────────────────────────────────────────
  function handleModeChange(m: PosMode) {
    setMode(m);
    if (m === 'return')   setRightPanel('return');
    else if (m === 'exchange') setRightPanel('exchange');
    else if (m === 'sale')    setRightPanel('cart');
  }

  // ── Payment success ────────────────────────────────────────────────────────
  function handlePaymentSuccess() {
    setRightPanel('receipt');
    closePayment();
  }

  // ── Return / exchange success ──────────────────────────────────────────────
  function handleTransactionSuccess() {
    setMode('sale');
    setRightPanel('receipt');
  }

  // ── Layout ─────────────────────────────────────────────────────────────────
  const isManagerMode = mode === 'manager';

  return (
    <div className="flex h-svh flex-col overflow-hidden bg-background">
      {/* Top header bar */}
      <PosHeader onModeChange={handleModeChange} />

      {/* Main workspace — 3-column layout */}
      <div className="flex flex-1 min-h-0">

        {/* Left panel — product catalog (hidden in manager mode) */}
        {!isManagerMode && (
          <div className="flex-1 min-w-0 border-r p-3 overflow-hidden flex flex-col">
            {/* Customer bar */}
            <div className="mb-2 shrink-0">
              <CustomerPanel />
            </div>

            {/* Mode-specific content */}
            {mode === 'sale' && (
              <ProductGrid onProductSelect={handleProductSelect} />
            )}
            {mode === 'return' && (
              <div className="flex h-24 items-center justify-center text-sm text-muted-foreground">
                Use the Return panel →
              </div>
            )}
            {mode === 'exchange' && (
              <div className="flex h-24 items-center justify-center text-sm text-muted-foreground">
                Use the Exchange panel →
              </div>
            )}
          </div>
        )}

        {/* Manager mode full panel */}
        {isManagerMode && (
          <div className="flex-1 overflow-y-auto">
            <ManagerPanel />
          </div>
        )}

        {/* Right panel — animated slide */}
        <AnimatePresence mode="wait" initial={false}>
          <motion.div
            key={paymentPanelOpen ? 'payment' : rightPanel}
            initial={{ x: 20, opacity: 0 }}
            animate={{ x: 0, opacity: 1 }}
            exit={{ x: 20, opacity: 0 }}
            transition={{ duration: 0.15 }}
            className={cn(
              'flex flex-col border-l bg-background',
              isManagerMode ? 'w-80' : 'w-[340px] shrink-0',
            )}
          >
            {paymentPanelOpen || rightPanel === 'payment' ? (
              <PaymentPanel
                onClose={() => { closePayment(); setRightPanel('cart'); }}
                onSuccess={handlePaymentSuccess}
              />
            ) : rightPanel === 'receipt' ? (
              <ReceiptPanel
                receiptId={lastReceiptId}
                onClose={() => { setLastReceipt(null); setRightPanel('cart'); }}
              />
            ) : rightPanel === 'return' ? (
              <ReturnPanel
                onClose={() => { setMode('sale'); setRightPanel('cart'); }}
                onSuccess={handleTransactionSuccess}
              />
            ) : rightPanel === 'exchange' ? (
              <ExchangePanel
                onClose={() => { setMode('sale'); setRightPanel('cart'); }}
                onSuccess={handleTransactionSuccess}
              />
            ) : (
              <CartPanel
                onCheckout={() => { openPayment(); setRightPanel('payment'); }}
              />
            )}
          </motion.div>
        </AnimatePresence>
      </div>

      {/* Keyboard help dialog */}
      <KeyboardHelp />
    </div>
  );
}
