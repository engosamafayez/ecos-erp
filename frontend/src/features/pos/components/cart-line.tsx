import { Minus, Plus, Trash2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import type { CartLine } from '@/features/pos/types';

type CartLineProps = {
  line: CartLine;
  onRemove: (lineId: string) => void;
  onQtyChange?: (lineId: string, qty: number) => void;
  disabled?: boolean;
};

export function CartLineRow({ line, onRemove, onQtyChange, disabled }: CartLineProps) {
  const qty = parseFloat(line.quantity);

  return (
    <div className="flex items-start gap-2 rounded-md px-2 py-2 hover:bg-accent/50 group">
      {/* Qty controls */}
      <div className="flex items-center gap-1 shrink-0">
        <Button
          variant="ghost"
          size="icon"
          className="size-6"
          disabled={disabled || qty <= 1}
          onClick={() => onQtyChange?.(line.id, qty - 1)}
        >
          <Minus className="size-3" />
        </Button>
        <span className="w-6 text-center text-sm tabular-nums font-medium">{qty}</span>
        <Button
          variant="ghost"
          size="icon"
          className="size-6"
          disabled={disabled}
          onClick={() => onQtyChange?.(line.id, qty + 1)}
        >
          <Plus className="size-3" />
        </Button>
      </div>

      {/* Product info */}
      <div className="flex-1 min-w-0">
        <p className="truncate text-sm font-medium">{line.product_name}</p>
        <p className="text-xs text-muted-foreground">{line.sku}</p>
      </div>

      {/* Line total */}
      <div className="flex items-center gap-2 shrink-0">
        <span className="text-sm font-semibold tabular-nums">
          {line.line_total.amount}
        </span>
        <Button
          variant="ghost"
          size="icon"
          className="size-6 opacity-0 group-hover:opacity-100 text-destructive hover:text-destructive"
          disabled={disabled}
          onClick={() => onRemove(line.id)}
        >
          <Trash2 className="size-3" />
        </Button>
      </div>
    </div>
  );
}
