import { ShoppingCart, PauseCircle, X, CreditCard } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { CartLineRow } from '@/features/pos/components/cart-line';
import {
  useCart,
  useUpdateCartLine,
  useRemoveCartLine,
  useHoldCart,
  useCancelCart,
} from '@/features/pos/hooks/use-pos-queries';
import { usePosStore } from '@/features/pos/store/pos-store';
import type { CartLine } from '@/features/pos/types';

type CartPanelProps = {
  onCheckout: () => void;
  onViewHeld: () => void;
};

export function CartPanel({ onCheckout, onViewHeld }: CartPanelProps) {
  const { cartId, currency, openPayment, heldCartSnapshots } = usePosStore();

  const { data: cart } = useCart();
  const updateLine = useUpdateCartLine();
  const removeLine = useRemoveCartLine();
  const holdCart   = useHoldCart();
  const cancelCart = useCancelCart();

  const lines = cart?.lines ?? [];
  const total = cart?.total?.amount ?? '0.00';
  const currencyCode = cart?.currency ?? currency;
  const isBusy =
    updateLine.isPending || removeLine.isPending ||
    holdCart.isPending  || cancelCart.isPending;

  async function handleRemove(lineId: string) {
    if (!cartId) return;
    await removeLine.mutateAsync({ cartId, lineId });
  }

  async function handleQtyChange(lineId: string, newQty: number) {
    if (!cartId) return;
    const line = lines.find((l: CartLine) => l.id === lineId);
    if (!line) return;
    await updateLine.mutateAsync({ cartId, lineId, newQty, line });
  }

  async function handleHold() {
    if (!cartId) return;
    await holdCart.mutateAsync(cartId);
  }

  async function handleCancel() {
    if (!cartId) return;
    await cancelCart.mutateAsync(cartId);
  }

  const heldCount = heldCartSnapshots.length;

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="flex items-center justify-between px-3 py-2 shrink-0">
        <div className="flex items-center gap-2">
          <ShoppingCart className="size-4 text-muted-foreground" />
          <span className="text-sm font-semibold">Cart</span>
          {lines.length > 0 && (
            <span className="rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-bold text-primary-foreground">
              {lines.length}
            </span>
          )}
        </div>
        <div className="flex items-center gap-0.5">
          {/* Held carts button — always visible so cashier can always reach held carts */}
          <Button
            variant="ghost"
            size="sm"
            className="h-9 gap-1.5 px-2 text-xs text-muted-foreground hover:text-foreground"
            title="View held carts (Ctrl+H)"
            onClick={onViewHeld}
          >
            <PauseCircle className="size-3.5" />
            {heldCount > 0 ? (
              <span className="rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-white leading-none">
                {heldCount}
              </span>
            ) : (
              <span className="text-[10px]">Held</span>
            )}
          </Button>

          {cart && (
            <>
              <Button
                variant="ghost"
                size="icon"
                className="size-9"
                title="Hold cart (F9)"
                onClick={handleHold}
                disabled={isBusy || lines.length === 0}
              >
                <PauseCircle className="size-4" />
              </Button>
              <Button
                variant="ghost"
                size="icon"
                className="size-9 text-destructive hover:text-destructive"
                title="Cancel cart"
                onClick={handleCancel}
                disabled={isBusy}
              >
                <X className="size-4" />
              </Button>
            </>
          )}
        </div>
      </div>

      <Separator />

      {/* Lines */}
      <div className="flex-1 overflow-y-auto px-1 py-1">
        {lines.length === 0 ? (
          <div className="flex h-24 items-center justify-center text-xs text-muted-foreground">
            Scan a product or search above
          </div>
        ) : (
          lines.map((line: CartLine) => (
            <CartLineRow
              key={line.id}
              line={line}
              onRemove={handleRemove}
              onQtyChange={handleQtyChange}
              disabled={isBusy}
            />
          ))
        )}
      </div>

      <Separator />

      {/* Totals */}
      {cart && (
        <div className="px-3 py-2 space-y-1 shrink-0 text-sm">
          <div className="flex justify-between text-muted-foreground">
            <span>Subtotal</span>
            <span className="tabular-nums">{cart.subtotal.amount}</span>
          </div>
          {parseFloat(cart.discount_total.amount) > 0 && (
            <div className="flex justify-between text-emerald-600">
              <span>Discount</span>
              <span className="tabular-nums">−{cart.discount_total.amount}</span>
            </div>
          )}
          <div className="flex justify-between text-base font-bold">
            <span>Total</span>
            <span className="tabular-nums">{currencyCode} {total}</span>
          </div>
        </div>
      )}

      {/* Checkout */}
      <div className="px-3 pb-3 shrink-0">
        <Button
          className="w-full gap-2"
          size="lg"
          disabled={lines.length === 0 || isBusy}
          onClick={() => { openPayment(); onCheckout(); }}
        >
          <CreditCard className="size-4" />
          Pay {currencyCode} {total}
          <span className="ml-auto text-xs opacity-70">F8</span>
        </Button>
      </div>
    </div>
  );
}
