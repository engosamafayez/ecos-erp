import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PackageCheck } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { toast } from '@/components/ds/use-toast';
import { extractApiErrorMessage } from '@/lib/api-error';
import { usePermission } from '@/features/authorization/use-authorization';
import { useConfirmReceiptQuantities } from '@/features/goods-receipts/hooks/use-goods-receipts';
import type { GoodsReceipt, GoodsReceiptLine } from '@/features/goods-receipts/types/goods-receipt';

function trimNum(value: number, decimals = 4): string {
  return value.toFixed(decimals).replace(/\.?0+$/, '');
}

type RowProps = {
  line: GoodsReceiptLine;
  value: string;
  onChange: (next: string) => void;
  disabled: boolean;
};

function ConfirmQuantityRow({ line, value, onChange, disabled }: RowProps) {
  const { t } = useTranslation('goods-receipts');
  const expected = line.ordered_quantity;
  const entered = value.trim() === '' ? 0 : Number(value);
  const variance = Number.isFinite(entered) ? entered - expected : 0;
  const invalid = Number.isFinite(entered) && entered > expected;

  return (
    <div className="rounded-lg border p-3 flex flex-col gap-2">
      <div className="flex items-center justify-between gap-2">
        <div className="min-w-0">
          <p className="font-medium text-sm truncate">{line.product?.name ?? '—'}</p>
          <p className="text-xs text-muted-foreground">{line.product?.sku}</p>
        </div>
      </div>

      <div className="grid grid-cols-3 gap-2 items-end">
        <div>
          <p className="text-[10px] text-muted-foreground uppercase tracking-wide">
            {t($ => $.confirmQuantities.expectedQty)}
          </p>
          <p className="text-sm font-mono mt-0.5">{trimNum(expected)}</p>
        </div>
        <div>
          <label className="text-[10px] text-muted-foreground uppercase tracking-wide">
            {t($ => $.confirmQuantities.acceptedQty)}
          </label>
          <input
            type="number"
            min="0"
            max={expected}
            step="0.0001"
            disabled={disabled}
            className={`no-spinner w-full mt-0.5 rounded-md border bg-transparent px-2 py-1.5 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 ${
              invalid
                ? 'border-destructive focus-visible:ring-destructive'
                : 'border-input focus-visible:ring-ring'
            }`}
            placeholder="0"
            value={value}
            onChange={(e) => onChange(e.target.value)}
          />
        </div>
        <div>
          <p className="text-[10px] text-muted-foreground uppercase tracking-wide">
            {t($ => $.confirmQuantities.variance)}
          </p>
          <p
            className={`text-sm font-mono mt-0.5 ${
              variance < 0 ? 'text-amber-600' : variance > 0 ? 'text-destructive' : 'text-muted-foreground'
            }`}
          >
            {variance > 0 ? '+' : ''}{trimNum(variance)}
          </p>
        </div>
      </div>

      {invalid && (
        <p className="text-xs text-destructive">{t($ => $.confirmQuantities.exceedsExpected)}</p>
      )}
    </div>
  );
}

/**
 * TASK-ECOS-PROCUREMENT-INVOICE-FIRST-RECEIVING-FLOW-014 — frontend closure.
 *
 * The warehouse's "record what actually arrived" step for a Draft, invoice-first receipt
 * (`receipt.is_invoice_originated`). Deliberately separate from the generic
 * create/edit form — that form is shaped around a Purchase Order and cannot represent this
 * receipt's anchor at all (see `ConfirmReceiptQuantitiesAction`'s own docblock on the backend).
 *
 * Submits to the new `confirm-quantities` endpoint only — it never posts. Posting stays the
 * existing `Post` action already on this page, so the backend's own reconciliation/costing gate
 * (an unposted receipt has no stamped landed cost) is never bypassed by this form.
 */
export function ConfirmReceiptQuantitiesForm({ receipt }: { receipt: GoodsReceipt }) {
  const { t } = useTranslation('goods-receipts');
  const { can } = usePermission();
  const confirmQuantities = useConfirmReceiptQuantities();

  const lines = useMemo(() => receipt.lines.filter((l) => l.ordered_quantity > 0), [receipt.lines]);

  const [quantities, setQuantities] = useState<Record<string, string>>(() =>
    Object.fromEntries(lines.map((l) => [l.id, l.net_received_quantity > 0 ? trimNum(l.net_received_quantity) : ''])),
  );

  const canReceive = can('purchasing.goods_receipts.update');

  const entries = lines.map((line) => ({
    line,
    qty: quantities[line.id]?.trim() === '' ? 0 : Number(quantities[line.id]),
  }));
  const hasExcess = entries.some(({ line, qty }) => Number.isFinite(qty) && qty > line.ordered_quantity);
  const canSubmit = canReceive && !hasExcess && !confirmQuantities.isPending;

  function setQty(lineId: string, next: string) {
    setQuantities((curr) => ({ ...curr, [lineId]: next }));
  }

  async function handleSubmit() {
    const payloadLines = entries.map(({ line, qty }) => ({
      line_id: line.id,
      accepted_qty: Number.isFinite(qty) ? Math.max(0, qty) : 0,
    }));

    try {
      await confirmQuantities.mutateAsync({ id: receipt.id, lines: payloadLines });
      toast.success(t($ => $.confirmQuantities.success));
    } catch (error) {
      toast.error(extractApiErrorMessage(error));
    }
  }

  if (lines.length === 0) {
    return <p className="text-sm text-muted-foreground italic">{t($ => $.confirmQuantities.empty)}</p>;
  }

  return (
    <div className="flex flex-col gap-3">
      <p className="text-xs text-muted-foreground">{t($ => $.confirmQuantities.hint)}</p>

      {lines.map((line) => (
        <ConfirmQuantityRow
          key={line.id}
          line={line}
          value={quantities[line.id] ?? ''}
          onChange={(next) => setQty(line.id, next)}
          disabled={confirmQuantities.isPending || !canReceive}
        />
      ))}

      {!canReceive && (
        <p className="text-xs text-destructive">{t($ => $.confirmQuantities.noPermission)}</p>
      )}

      <div className="flex justify-end pt-1">
        <Button size="sm" disabled={!canSubmit} onClick={() => void handleSubmit()}>
          <PackageCheck className="size-3.5 mr-1.5" />
          {t($ => $.confirmQuantities.submit)}
        </Button>
      </div>
    </div>
  );
}
