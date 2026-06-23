type TotalsProps = {
  subtotal: number;
  total: number;
};

function fmt(n: number) {
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function PurchaseOrderTotals({ subtotal, total }: TotalsProps) {
  return (
    <div className="flex flex-col items-end gap-1 border-t pt-3 text-sm">
      <div className="flex gap-8">
        <span className="text-muted-foreground">Subtotal</span>
        <span className="w-28 text-right font-medium">{fmt(subtotal)}</span>
      </div>
      <div className="flex gap-8 text-base font-semibold">
        <span>Total</span>
        <span className="w-28 text-right">{fmt(total)}</span>
      </div>
    </div>
  );
}
