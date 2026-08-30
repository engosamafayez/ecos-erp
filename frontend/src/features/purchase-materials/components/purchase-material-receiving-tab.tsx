import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import axios from 'axios';
import { Loader2, PackageCheck, Truck } from 'lucide-react';

import { ConfirmDialog } from '@/components/crud';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ds/use-toast';
import { getMediaUrl } from '@/lib/media';
import { usePermission } from '@/features/authorization/use-authorization';
import { useSupplierOptions } from '@/features/purchase-orders/hooks/use-supplier-options';
import {
  useCreateGoodsReceipt,
  usePostGoodsReceipt,
} from '@/features/goods-receipts/hooks/use-goods-receipts';
import type { GoodsReceiptLinePayload } from '@/features/goods-receipts/types/goods-receipt';

import type { PurchaseMaterial, PurchaseMaterialLine } from '../types/purchase-material';

/**
 * Purchase Material receiving — TASK-PROC-PURCHASING-PHASE2-PART1-...-RECEIVING-UI-001.
 *
 * The backend has supported receiving straight against a Purchase Material line since
 * Phase 2 Part 1 (RD-1: supplier identity comes from `purchase_material_lines.supplier_id`),
 * but the only receipt form in the app demanded a Purchase Order, so the certified path was
 * unreachable by an operator. This tab is that missing path — and nothing more.
 *
 * TWO RULES THIS COMPONENT OBEYS.
 *
 * 1. It NEVER computes Required / Received / Remaining. Those three numbers arrive already
 *    computed from `PurchaseMaterialReceivingService` via `PurchaseMaterialLineResource`. A
 *    second implementation here is exactly how two screens start disagreeing about what is
 *    still owed, so the inputs are clamped against the server's `remaining_qty` and the
 *    server stays the final authority on every submission.
 * 2. It asks for NO Purchase Order. The receipt is anchored by `purchase_material_line_id`;
 *    `purchase_order_id` is simply absent from the payload.
 */

const RECEIVABLE_STATUSES = ['approved', 'purchasing', 'receiving'] as const;

function extractMessage(error: unknown, fallback: string): string {
  if (!axios.isAxiosError(error)) return fallback;

  // Laravel validation (422) reports the useful detail per field; a bare `message` there is
  // only "The given data was invalid." Surface the first field error instead of that.
  const errors = error.response?.data?.errors as Record<string, string[]> | undefined;
  const first = errors ? Object.values(errors)[0]?.[0] : undefined;
  if (typeof first === 'string' && first.length > 0) return first;

  const message = error.response?.data?.message;
  return typeof message === 'string' && message.length > 0 ? message : fallback;
}

function trimNum(value: number, decimals = 4): string {
  return value
    .toFixed(decimals)
    .replace(/\.?0+$/, '')
    .concat('');
}

// ── One receivable line ────────────────────────────────────────────────────────

type LineRowProps = {
  line: PurchaseMaterialLine;
  supplierLabel: string | null;
  value: string;
  onChange: (next: string) => void;
  disabled: boolean;
};

function ReceivingLineRow({ line, supplierLabel, value, onChange, disabled }: LineRowProps) {
  const { t } = useTranslation('purchase-materials');

  const remaining = line.remaining_qty;
  const entered = value.trim() === '' ? 0 : Number(value);
  const invalid = Number.isFinite(entered) && entered > remaining;

  return (
    <div className="rounded-lg border p-3 flex flex-col gap-3">
      <div className="flex items-center gap-2">
        {line.product?.image_url ? (
          <img
            src={getMediaUrl(line.product.image_url) ?? undefined}
            alt=""
            className="size-6 rounded object-cover border"
          />
        ) : (
          <div className="size-6 rounded bg-muted border" />
        )}
        <div className="flex-1 min-w-0">
          <p className="font-medium text-sm">{line.product?.name ?? '—'}</p>
          <p className="text-[10px] text-muted-foreground">{line.product?.sku}</p>
        </div>
        {supplierLabel && (
          <span className="text-xs text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-full px-2 py-0.5 font-medium">
            {supplierLabel}
          </span>
        )}
      </div>

      <div className="grid grid-cols-4 gap-2">
        <div>
          <p className="text-[10px] text-muted-foreground uppercase tracking-wide">
            {t($ => $.purchaseDrawer.receiving.required)}
          </p>
          <p className="text-sm font-mono mt-0.5">{trimNum(line.required_qty)}</p>
        </div>
        <div>
          <p className="text-[10px] text-muted-foreground uppercase tracking-wide">
            {t($ => $.purchaseDrawer.receiving.received)}
          </p>
          <p className="text-sm font-mono mt-0.5">{trimNum(line.received_qty)}</p>
        </div>
        <div>
          <p className="text-[10px] text-muted-foreground uppercase tracking-wide">
            {t($ => $.purchaseDrawer.receiving.remaining)}
          </p>
          <p className="text-sm font-mono mt-0.5 font-medium">{trimNum(remaining)}</p>
        </div>
        <div>
          <label className="text-[10px] text-muted-foreground uppercase tracking-wide">
            {t($ => $.purchaseDrawer.receiving.qtyToReceive)}
          </label>
          <input
            type="number"
            min="0"
            max={remaining}
            step="0.0001"
            disabled={disabled || remaining <= 0}
            className={`w-full mt-0.5 rounded-md border bg-transparent px-2 py-1.5 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 ${
              invalid
                ? 'border-destructive focus-visible:ring-destructive'
                : 'border-input focus-visible:ring-ring'
            }`}
            placeholder="0"
            value={value}
            onChange={(e) => onChange(e.target.value)}
          />
        </div>
      </div>

      <div className="flex items-center justify-between text-[10px]">
        <span className="text-muted-foreground">
          {line.unit_label ?? ''}
        </span>
        {invalid && (
          <span className="text-destructive font-medium">
            {t($ => $.purchaseDrawer.receiving.exceedsRemaining)}
          </span>
        )}
      </div>
    </div>
  );
}

// ── Tab ────────────────────────────────────────────────────────────────────────

export function PurchaseMaterialReceivingTab({ material }: { material: PurchaseMaterial }) {
  const { t } = useTranslation('purchase-materials');
  const { can } = usePermission();

  const [quantities, setQuantities] = useState<Record<string, string>>({});
  const [reviewOpen, setReviewOpen] = useState(false);

  const createReceipt = useCreateGoodsReceipt();
  const postReceipt = usePostGoodsReceipt();

  // The Purchase Material payload carries `supplier_id` but not the supplier relation, so the
  // name is resolved from the same option list the Supplier tab already uses. Identity still
  // comes from the LINE (RD-1) — only the label is looked up.
  const { data: supplierOptions = [] } = useSupplierOptions();
  const supplierLabels = useMemo(
    () => new Map(supplierOptions.map((o) => [o.value, o.label])),
    [supplierOptions],
  );
  const labelFor = (line: PurchaseMaterialLine): string | null =>
    line.supplier?.name ?? (line.supplier_id ? supplierLabels.get(line.supplier_id) ?? null : null);

  const lines = useMemo(() => material.lines ?? [], [material.lines]);
  const isReceivableStatus = (RECEIVABLE_STATUSES as readonly string[]).includes(material.status);

  // Only lines that still owe something can be received against.
  const receivableLines = useMemo(() => lines.filter((l) => l.remaining_qty > 0), [lines]);

  const entries = useMemo(
    () =>
      receivableLines
        .map((line) => ({ line, qty: Number(quantities[line.id] ?? '') }))
        .filter((e) => Number.isFinite(e.qty) && e.qty > 0),
    [receivableLines, quantities],
  );

  const hasExcess = entries.some((e) => e.qty > e.line.remaining_qty);
  // RD-1: a receipt line's supplier is resolved from the Purchase Material line, so a line
  // without one cannot be attributed to a FIFO layer. Block it here rather than let the
  // posting fail halfway.
  const missingSupplier = entries.some((e) => !e.line.supplier_id);
  const isBusy = createReceipt.isPending || postReceipt.isPending;
  const canReceive = can('purchasing.goods_receipts.create') && can('purchasing.goods_receipts.update');
  const canSubmit = entries.length > 0 && !hasExcess && !missingSupplier && !isBusy && canReceive;

  function setQty(lineId: string, next: string) {
    setQuantities((curr) => ({ ...curr, [lineId]: next }));
  }

  async function handleConfirm() {
    const payloadLines: GoodsReceiptLinePayload[] = entries.map(({ line, qty }) => ({
      // No purchase_order_line_id — this receipt is anchored to the Purchase Material.
      purchase_material_line_id: line.id,
      product_id: line.product_id,
      ordered_quantity: line.required_qty,
      gross_received_quantity: qty,
      net_received_quantity: qty,
      ...(line.agreed_price != null ? { unit_price: line.agreed_price } : {}),
    }));

    try {
      const receipt = await createReceipt.mutateAsync({
        warehouse_id: material.warehouse_id,
        receipt_date: new Date().toISOString().slice(0, 10),
        lines: payloadLines,
      });

      // "Confirm Receipt" is one operator action, so the draft is posted immediately. Both
      // calls are the existing certified endpoints; nothing new was introduced.
      await postReceipt.mutateAsync(receipt.id);

      toast.success(t($ => $.purchaseDrawer.receiving.toastConfirmed));
      setQuantities({});
      setReviewOpen(false);
    } catch (error) {
      toast.error(extractMessage(error, t($ => $.purchaseDrawer.receiving.toastFailed)));
      setReviewOpen(false);
    }
  }

  if (!isReceivableStatus) {
    return (
      <div className="flex flex-col items-center justify-center py-10 gap-2 text-muted-foreground text-sm">
        <Truck className="size-8 text-muted-foreground/30" />
        <p>{t($ => $.purchaseDrawer.receiving.unavailableTitle)}</p>
        <p className="text-xs">
          {t($ => $.purchaseDrawer.receiving.currentStatus)}
          {material.status_label}
        </p>
      </div>
    );
  }

  if (lines.length === 0) {
    return (
      <p className="text-sm text-muted-foreground italic py-4">
        {t($ => $.purchaseDrawer.receiving.empty)}
      </p>
    );
  }

  if (receivableLines.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center py-10 gap-2 text-muted-foreground text-sm">
        <PackageCheck className="size-8 text-emerald-500/40" />
        <p>{t($ => $.purchaseDrawer.receiving.fullyReceived)}</p>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-3">
      <p className="text-xs text-muted-foreground">{t($ => $.purchaseDrawer.receiving.hint)}</p>

      <div className="rounded-lg bg-muted/30 border px-3 py-2 grid grid-cols-2 gap-2 text-xs">
        <div>
          <span className="text-muted-foreground">{t($ => $.purchaseDrawer.receiving.warehouse)}</span>
          <p className="font-medium mt-0.5">{material.warehouse?.name ?? '—'}</p>
        </div>
        <div>
          <span className="text-muted-foreground">{t($ => $.purchaseDrawer.receiving.receiptDate)}</span>
          <p className="font-medium mt-0.5 font-mono">{new Date().toISOString().slice(0, 10)}</p>
        </div>
      </div>

      {receivableLines.map((line) => (
        <ReceivingLineRow
          key={line.id}
          line={line}
          supplierLabel={labelFor(line)}
          value={quantities[line.id] ?? ''}
          onChange={(next) => setQty(line.id, next)}
          disabled={isBusy || !canReceive}
        />
      ))}

      {!canReceive && (
        <p className="text-xs text-destructive">{t($ => $.purchaseDrawer.receiving.noPermission)}</p>
      )}
      {missingSupplier && (
        <p className="text-xs text-destructive">{t($ => $.purchaseDrawer.receiving.supplierRequired)}</p>
      )}

      <div className="flex justify-end pt-1">
        <Button size="sm" disabled={!canSubmit} onClick={() => setReviewOpen(true)}>
          {isBusy && <Loader2 className="size-3.5 animate-spin mr-1.5" />}
          <PackageCheck className="size-3.5 mr-1.5" />
          {t($ => $.purchaseDrawer.receiving.confirmReceipt)}
        </Button>
      </div>

      <ConfirmDialog
        open={reviewOpen}
        onOpenChange={setReviewOpen}
        title={t($ => $.purchaseDrawer.receiving.reviewTitle)}
        confirmLabel={t($ => $.purchaseDrawer.receiving.confirmReceipt)}
        loading={isBusy}
        onConfirm={() => void handleConfirm()}
        description={
          <span className="flex flex-col gap-2 text-sm">
            <span className="flex justify-between">
              <span className="text-muted-foreground">
                {t($ => $.purchaseDrawer.receiving.warehouse)}
              </span>
              <span className="font-medium">{material.warehouse?.name ?? '—'}</span>
            </span>
            <span className="flex justify-between">
              <span className="text-muted-foreground">
                {t($ => $.purchaseDrawer.receiving.totalLines)}
              </span>
              <span className="font-medium">{entries.length}</span>
            </span>
            <span className="flex flex-col gap-1 pt-1 border-t">
              {entries.map(({ line, qty }) => (
                <span key={line.id} className="flex justify-between gap-3">
                  <span className="truncate">
                    {line.product?.name ?? '—'}
                    {labelFor(line) ? (
                      <span className="text-muted-foreground"> · {labelFor(line)}</span>
                    ) : null}
                  </span>
                  <span className="font-mono shrink-0">
                    {trimNum(qty)} {line.unit_label ?? ''}
                  </span>
                </span>
              ))}
            </span>
          </span>
        }
      />
    </div>
  );
}
