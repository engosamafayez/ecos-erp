import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useQuery } from '@tanstack/react-query';
import axios from 'axios';

import { Combobox } from '@/components/crud';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { goodsReceiptsService } from '@/features/goods-receipts/services/goods-receipts-service';
import type { GoodsReceipt, GoodsReceiptLine } from '@/features/goods-receipts/types/goods-receipt';
import { suppliersService } from '@/features/suppliers/services/suppliers-service';
import { useCreateSupplierReturn } from '@/features/supplier-returns/hooks/use-supplier-returns';
import type {
  CreditMethod,
  SupplierReturnLinePayload,
  SupplierReturnReason,
} from '@/features/supplier-returns/types/supplier-return';

type Props = {
  onCreated: () => void;
  onCancel: () => void;
};

type LineDraft = {
  returnQuantity: string;
  unitCost: string;
  notes: string;
};

const REASONS: SupplierReturnReason[] = [
  'defective', 'wrong_item', 'overdelivery', 'quality_issue', 'price_discrepancy', 'expired', 'damaged', 'other',
];
const QUALITY_CONDITIONS = ['new', 'used', 'damaged', 'expired'] as const;
const CREDIT_METHODS: CreditMethod[] = ['credit_note', 'refund', 'replacement'];

function extractMessage(error: unknown, fallback: string): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : fallback;
}

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

/**
 * The real Supplier Return creation flow (TASK-...-SUPPLIER-MASTER-AND-RETURNS-FINAL-018
 * §B), replacing the "create via POST /supplier-returns" placeholder. Built entirely on
 * the EXISTING canonical Goods Receipts and Supplier Returns APIs — no new backend
 * endpoint, no second returns engine.
 *
 * A return is anchored to a Goods Receipt (the canonical "what was actually received"
 * authority — SR-2), not typed in freehand: the user searches Posted receipts by number,
 * then picks which of that receipt's own lines to return and how much. Supplier and
 * Warehouse are derived from the receipt itself whenever it names a Purchase Order (the
 * common case), which makes "same supplier/company" true by construction rather than by
 * a client-side check the server would have to re-verify anyway. A receipt with no linked
 * Purchase Order (Purchase-Material or invoice-first anchored) cannot expose its supplier
 * from the existing Goods Receipt read model, so the form falls back to letting the user
 * confirm it explicitly — the server (ApproveSupplierReturnAction::resolveReceiptLine)
 * remains the real authority either way and will refuse a mismatch at approval time.
 *
 * The return-quantity ceiling itself (received minus already-returned) is enforced
 * server-side at Approve time (ReturnableQuantityService — by design, since Draft/
 * WaitingApproval never consume inventory). This form mirrors that design rather than
 * re-implementing the ceiling client-side: it caps against the line's own received
 * quantity as an honest, immediate sanity check, and surfaces the server's own message
 * if a stricter check (a prior return already consumed part of the line) rejects it later.
 */
export function SupplierReturnForm({ onCreated, onCancel }: Props) {
  const { t } = useTranslation('supplier-returns');
  const createReturn = useCreateSupplierReturn();

  // Explicit per-case label maps (matching useSupplierReturnLabels' own convention)
  // rather than a dynamic key lookup, which the typed i18n `t($ => $.a.b.c)` callback
  // shape cannot express.
  const reasonLabel: Record<SupplierReturnReason, string> = {
    defective:         t($ => $.reason.defective),
    wrong_item:        t($ => $.reason.wrong_item),
    overdelivery:      t($ => $.reason.overdelivery),
    quality_issue:     t($ => $.reason.quality_issue),
    price_discrepancy: t($ => $.reason.price_discrepancy),
    expired:           t($ => $.reason.expired),
    damaged:           t($ => $.reason.damaged),
    other:             t($ => $.reason.other),
  };
  const qualityConditionLabel: Record<(typeof QUALITY_CONDITIONS)[number], string> = {
    new:     t($ => $.returnDrawer.createForm.qualityConditions.new),
    used:    t($ => $.returnDrawer.createForm.qualityConditions.used),
    damaged: t($ => $.returnDrawer.createForm.qualityConditions.damaged),
    expired: t($ => $.returnDrawer.createForm.qualityConditions.expired),
  };
  const creditMethodLabel: Record<CreditMethod, string> = {
    credit_note: t($ => $.returnDrawer.createForm.creditMethods.credit_note),
    refund:      t($ => $.returnDrawer.createForm.creditMethods.refund),
    replacement: t($ => $.returnDrawer.createForm.creditMethods.replacement),
  };

  const [receiptSearch, setReceiptSearch] = useState('');
  const [receiptId, setReceiptId] = useState<string | null>(null);
  const [manualSupplierId, setManualSupplierId] = useState<string | null>(null);
  const [supplierSearch, setSupplierSearch] = useState('');

  const [returnDate, setReturnDate] = useState(todayIso());
  const [reason, setReason] = useState<SupplierReturnReason | ''>('');
  const [qualityCondition, setQualityCondition] = useState('');
  const [creditMethod, setCreditMethod] = useState<CreditMethod | ''>('');
  const [expectedCreditDate, setExpectedCreditDate] = useState('');
  const [notes, setNotes] = useState('');

  const [lines, setLines] = useState<Record<string, LineDraft>>({});
  const [formError, setFormError] = useState<string | null>(null);

  const { data: receiptOptions, isLoading: receiptsLoading } = useQuery({
    queryKey: ['supplier-return-receipt-options', receiptSearch],
    queryFn: () => goodsReceiptsService.list({ status: 'posted', search: receiptSearch || undefined, per_page: 20 }),
    staleTime: 30 * 1000,
  });

  const { data: receipt, isLoading: receiptLoading } = useQuery<GoodsReceipt>({
    queryKey: ['supplier-return-receipt-detail', receiptId],
    queryFn: () => goodsReceiptsService.get(receiptId!),
    enabled: receiptId !== null,
  });

  const derivedSupplier = receipt?.purchase_order?.supplier ?? null;
  const needsManualSupplier = receipt !== undefined && receipt !== null && derivedSupplier === null;

  const { data: supplierOptions, isLoading: suppliersLoading } = useQuery({
    queryKey: ['supplier-return-supplier-options', supplierSearch],
    queryFn: () => suppliersService.list({ search: supplierSearch || undefined, status: 'active', per_page: 20 }),
    enabled: needsManualSupplier,
    staleTime: 30 * 1000,
  });

  const receiptComboOptions = useMemo(
    () => (receiptOptions?.items ?? []).map((r) => ({
      value: r.id,
      label: `${r.receipt_number} · ${r.purchase_order?.supplier?.name ?? '—'} · ${r.receipt_date}`,
    })),
    [receiptOptions],
  );

  const supplierComboOptions = useMemo(
    () => (supplierOptions?.items ?? []).map((s) => ({ value: s.id, label: `${s.name} (${s.code})` })),
    [supplierOptions],
  );

  function selectReceipt(id: string) {
    setReceiptId(id || null);
    setManualSupplierId(null);
    setLines({});
  }

  function lineDraft(line: GoodsReceiptLine): LineDraft {
    return lines[line.id] ?? {
      returnQuantity: '',
      unitCost: String(line.landed_unit_cost ?? line.unit_price ?? 0),
      notes: '',
    };
  }

  function setLineField(line: GoodsReceiptLine, field: keyof LineDraft, value: string) {
    setLines((prev) => ({ ...prev, [line.id]: { ...lineDraft(line), [field]: value } }));
  }

  function resolvedSupplierId(): string | null {
    return derivedSupplier?.id ?? manualSupplierId;
  }

  async function handleSubmit() {
    setFormError(null);

    if (!receipt) {
      setFormError(t($ => $.returnDrawer.createForm.validation.noReceipt));
      return;
    }

    const supplierId = resolvedSupplierId();
    if (!supplierId) {
      setFormError(t($ => $.returnDrawer.createForm.validation.noSupplier));
      return;
    }

    const payloadLines: SupplierReturnLinePayload[] = [];
    for (const line of receipt.lines) {
      const draft = lines[line.id];
      const qty = draft ? parseFloat(draft.returnQuantity) : 0;
      if (!qty || qty <= 0) continue;

      if (qty > line.net_received_quantity) {
        setFormError(t($ => $.returnDrawer.createForm.validation.qtyExceedsReceived, {
          product: line.product?.name ?? '',
          max: line.net_received_quantity,
        }));
        return;
      }

      payloadLines.push({
        product_id: line.product_id,
        goods_receipt_line_id: line.id,
        return_quantity: qty,
        unit_cost: parseFloat(draft.unitCost) || 0,
        notes: draft.notes || undefined,
        uom_name_snapshot: line.uom_name_snapshot,
        uom_symbol_snapshot: line.uom_symbol_snapshot,
        original_received_qty: line.net_received_quantity,
        original_unit_cost: line.landed_unit_cost ?? line.unit_price,
      });
    }

    if (payloadLines.length === 0) {
      setFormError(t($ => $.returnDrawer.createForm.validation.noLines));
      return;
    }

    createReturn.mutate(
      {
        supplier_id: supplierId,
        warehouse_id: receipt.warehouse_id,
        purchase_order_id: receipt.purchase_order_id ?? undefined,
        goods_receipt_id: receipt.id,
        reason: reason || undefined,
        quality_condition: qualityCondition || undefined,
        return_date: returnDate,
        expected_credit_date: expectedCreditDate || undefined,
        notes: notes || undefined,
        credit_method: creditMethod || undefined,
        lines: payloadLines,
      },
      {
        onSuccess: () => onCreated(),
        onError: (err) => setFormError(extractMessage(err, t($ => $.returnDrawer.createForm.validation.failed))),
      },
    );
  }

  return (
    <div className="flex flex-col gap-4">
      {formError && (
        <Alert variant="destructive">
          <AlertDescription>{formError}</AlertDescription>
        </Alert>
      )}

      <div className="flex flex-col gap-1.5">
        <Label className="text-xs">{t($ => $.returnDrawer.createForm.receiptLabel)} <span className="text-destructive">*</span></Label>
        <Combobox
          options={receiptComboOptions}
          value={receiptId}
          onChange={selectReceipt}
          onSearchChange={setReceiptSearch}
          filterClientSide={false}
          loading={receiptsLoading}
          placeholder={t($ => $.returnDrawer.createForm.receiptPlaceholder)}
          emptyText={t($ => $.returnDrawer.createForm.receiptEmpty)}
        />
        <p className="text-xs text-muted-foreground">{t($ => $.returnDrawer.createForm.receiptHint)}</p>
      </div>

      {receiptId && (receiptLoading || !receipt) && (
        <p className="text-sm text-muted-foreground">{t($ => $.returnDrawer.loading)}</p>
      )}

      {receipt && (
        <>
          <div className="grid grid-cols-2 gap-3">
            <div className="flex flex-col gap-1.5">
              <Label className="text-xs">{t($ => $.returnDrawer.createForm.supplierLabel)} <span className="text-destructive">*</span></Label>
              {derivedSupplier ? (
                <>
                  <Input value={derivedSupplier.name} disabled className="text-muted-foreground" />
                  <p className="text-xs text-muted-foreground">{t($ => $.returnDrawer.createForm.supplierDerivedHint)}</p>
                </>
              ) : (
                <>
                  <Combobox
                    options={supplierComboOptions}
                    value={manualSupplierId}
                    onChange={setManualSupplierId}
                    onSearchChange={setSupplierSearch}
                    filterClientSide={false}
                    loading={suppliersLoading}
                    placeholder={t($ => $.returnDrawer.createForm.supplierPlaceholder)}
                  />
                  <p className="text-xs text-amber-600">{t($ => $.returnDrawer.createForm.supplierManualHint)}</p>
                </>
              )}
            </div>
            <div className="flex flex-col gap-1.5">
              <Label className="text-xs">{t($ => $.returnDrawer.createForm.warehouseLabel)}</Label>
              <Input value={receipt.warehouse?.name ?? '—'} disabled className="text-muted-foreground" />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="flex flex-col gap-1.5">
              <Label className="text-xs">{t($ => $.returnDrawer.createForm.returnDateLabel)} <span className="text-destructive">*</span></Label>
              <Input type="date" value={returnDate} onChange={(e) => setReturnDate(e.target.value)} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label className="text-xs">{t($ => $.returnDrawer.createForm.expectedCreditDateLabel)}</Label>
              <Input type="date" value={expectedCreditDate} onChange={(e) => setExpectedCreditDate(e.target.value)} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label className="text-xs">{t($ => $.returnDrawer.createForm.reasonLabel)}</Label>
              <select
                value={reason}
                onChange={(e) => setReason(e.target.value as SupplierReturnReason | '')}
                className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
              >
                <option value="">{t($ => $.returnDrawer.createForm.reasonPlaceholder)}</option>
                {REASONS.map((r) => <option key={r} value={r}>{reasonLabel[r]}</option>)}
              </select>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label className="text-xs">{t($ => $.returnDrawer.createForm.creditMethodLabel)}</Label>
              <select
                value={creditMethod}
                onChange={(e) => setCreditMethod(e.target.value as CreditMethod | '')}
                className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
              >
                <option value="">{t($ => $.returnDrawer.createForm.creditMethodPlaceholder)}</option>
                {CREDIT_METHODS.map((m) => (
                  <option key={m} value={m}>{creditMethodLabel[m]}</option>
                ))}
              </select>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label className="text-xs">{t($ => $.returnDrawer.createForm.qualityConditionLabel)}</Label>
              <select
                value={qualityCondition}
                onChange={(e) => setQualityCondition(e.target.value)}
                className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
              >
                <option value="">{t($ => $.returnDrawer.createForm.qualityConditionPlaceholder)}</option>
                {QUALITY_CONDITIONS.map((c) => (
                  <option key={c} value={c}>{qualityConditionLabel[c]}</option>
                ))}
              </select>
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label className="text-xs">{t($ => $.returnDrawer.createForm.notesLabel)}</Label>
            <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} placeholder={t($ => $.returnDrawer.createForm.notesPlaceholder)} />
          </div>

          <div className="border-t border-border/60 pt-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-1">
              {t($ => $.returnDrawer.createForm.linesTitle)}
            </p>
            <p className="text-xs text-muted-foreground mb-3">{t($ => $.returnDrawer.createForm.linesHint)}</p>

            {receipt.lines.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-4">{t($ => $.returnDrawer.createForm.linesEmpty)}</p>
            ) : (
              <div className="flex flex-col gap-2">
                {receipt.lines.map((line) => {
                  const draft = lineDraft(line);
                  return (
                    <div key={line.id} className="grid grid-cols-12 gap-2 items-center rounded-md border p-2">
                      <div className="col-span-4">
                        <p className="text-sm font-medium">{line.product?.name ?? '—'}</p>
                        <p className="text-xs text-muted-foreground">
                          {t($ => $.returnDrawer.createForm.columns.received)}: {line.net_received_quantity} {line.uom_symbol_snapshot ?? ''}
                        </p>
                      </div>
                      <div className="col-span-2">
                        <Input
                          type="number"
                          min={0}
                          max={line.net_received_quantity}
                          step="0.0001"
                          value={draft.returnQuantity}
                          onChange={(e) => setLineField(line, 'returnQuantity', e.target.value)}
                          placeholder="0"
                        />
                      </div>
                      <div className="col-span-2">
                        <Input
                          type="number"
                          min={0}
                          step="0.01"
                          value={draft.unitCost}
                          onChange={(e) => setLineField(line, 'unitCost', e.target.value)}
                        />
                      </div>
                      <div className="col-span-4">
                        <Input
                          value={draft.notes}
                          onChange={(e) => setLineField(line, 'notes', e.target.value)}
                          placeholder={t($ => $.returnDrawer.createForm.columns.lineNotes)}
                        />
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        </>
      )}

      <div className="flex justify-end gap-2 pt-2">
        <Button variant="outline" type="button" onClick={onCancel} disabled={createReturn.isPending}>
          {t($ => $.returnDrawer.createForm.cancel)}
        </Button>
        <Button type="button" onClick={handleSubmit} disabled={createReturn.isPending || !receipt}>
          {createReturn.isPending ? t($ => $.returnDrawer.createForm.submitting) : t($ => $.returnDrawer.createForm.submit)}
        </Button>
      </div>
    </div>
  );
}
