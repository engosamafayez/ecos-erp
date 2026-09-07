import { useTranslation } from 'react-i18next';
import { Boxes, Package, Plus, Trash2 } from 'lucide-react';
import { useFormatter } from '@/hooks/use-formatter';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  computeLineTotal,
  deriveUnitPrice,
  emptyLine,
  extraPerUnitPreview,
  finalUnitCostFor,
  parseNum,
  type InvoiceLineState,
  type LineEntityType,
} from '@/features/supplier-invoices/components/invoice-line-calc';
import { ProductLineSelect } from '@/features/supplier-invoices/components/product-line-select';
import { GoodsReceiptLineSelect } from '@/features/supplier-invoices/components/goods-receipt-line-select';

type Props = {
  lines: InvoiceLineState[];
  onLinesChange: (lines: InvoiceLineState[]) => void;
  supplierId: string;
  freight: number;
  additionalCosts: number;
  invoiceId?: string;
};

// §11 — every cell in the main row shares this fixed-height leading slot (a real label for
// Product/Goods-Receipt, an invisible spacer everywhere else) so every control below it — the
// combobox trigger, every Input — starts on the exact same baseline. Patching individual rows
// with ad-hoc margins was the thing that drifted them apart in the first place.
const LEADER_HEIGHT = 'h-[18px]';

export function InvoiceLineEditor({ lines, onLinesChange, supplierId, freight, additionalCosts, invoiceId }: Props) {
  const { t } = useTranslation('supplier-invoices');
  const fmt = useFormatter();

  const patch = (i: number, changes: Partial<InvoiceLineState>) =>
    onLinesChange(lines.map((l, idx) => (idx === i ? { ...l, ...changes } : l)));

  const setProduct = (i: number, productId: string, label: string) =>
    // A line's Goods Receipt anchor is specific to the product it settles — changing the
    // product invalidates any previously-picked anchor rather than silently keeping a mismatched
    // one (which would only surface as a confusing failure at POST time).
    patch(i, { product_id: productId, product_name: label, goods_receipt_line_id: null });
  const setQty = (i: number, v: string) =>
    patch(i, { quantity: v, line_total: String(computeLineTotal(parseNum(v), parseNum(lines[i].unit_price), parseNum(lines[i].tax_rate))) });
  const setPrice = (i: number, v: string) =>
    patch(i, { unit_price: v, line_total: String(computeLineTotal(parseNum(lines[i].quantity), parseNum(v), parseNum(lines[i].tax_rate))) });
  const setTax = (i: number, v: string) =>
    patch(i, { tax_rate: v, line_total: String(computeLineTotal(parseNum(lines[i].quantity), parseNum(lines[i].unit_price), parseNum(v))) });
  // §5 two-way — editing the line total derives the unit price (backend re-computes on save).
  const setLineTotal = (i: number, v: string) =>
    patch(i, { line_total: v, unit_price: String(deriveUnitPrice(parseNum(v), parseNum(lines[i].quantity), parseNum(lines[i].tax_rate))) });
  const setAnchor = (i: number, goodsReceiptLineId: string | null) =>
    patch(i, { goods_receipt_line_id: goodsReceiptLineId });

  const addLine = (entityType: LineEntityType) => onLinesChange([...lines, emptyLine(entityType)]);
  const removeLine = (i: number) => onLinesChange(lines.filter((_, idx) => idx !== i));

  const typeLabel = (l: InvoiceLineState) =>
    l.entity_type === 'raw_material' ? t($ => $.editor.items.rawMaterial) : t($ => $.editor.items.product);

  // §14 — one shared per-unit rate, live preview only (see invoice-line-calc.ts docblock).
  const extraPerUnit = extraPerUnitPreview(lines, freight, additionalCosts);

  return (
    <div>
      {/* Explicit Add Product / Add Raw Material — no ambiguous "Add Item" (§4). */}
      <div className="flex items-center justify-between mb-2 gap-2 flex-wrap">
        <Label className="text-xs font-semibold uppercase">{t($ => $.editor.items.title)}</Label>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" className="h-8 text-xs gap-1.5" onClick={() => addLine('product')} type="button">
            <Package className="w-3.5 h-3.5" />
            {t($ => $.editor.items.addProduct)}
          </Button>
          <Button variant="outline" size="sm" className="h-8 text-xs gap-1.5" onClick={() => addLine('raw_material')} type="button">
            <Boxes className="w-3.5 h-3.5" />
            {t($ => $.editor.items.addRawMaterial)}
          </Button>
        </div>
      </div>

      {lines.length === 0 && (
        <div className="rounded-lg border border-dashed p-4 text-center text-xs text-muted-foreground flex items-center justify-center gap-1.5">
          <Plus className="w-3.5 h-3.5" />
          {t($ => $.editor.items.emptyHint)}
        </div>
      )}

      {/* Desktop grid (lg+) */}
      {lines.length > 0 && (
        <div className="hidden lg:block space-y-3" data-testid="lines-desktop">
          <div className="grid grid-cols-12 gap-2 px-1">
            <span className="col-span-3 text-xs text-muted-foreground">{t($ => $.editor.items.columns.product)}</span>
            <span className="col-span-1 text-xs text-muted-foreground text-end">{t($ => $.editor.items.columns.qty)}</span>
            <span className="col-span-2 text-xs text-muted-foreground text-end">{t($ => $.editor.items.columns.unitPrice)}</span>
            <span className="col-span-1 text-xs text-muted-foreground text-end">{t($ => $.editor.items.columns.vatPct)}</span>
            <span className="col-span-2 text-xs text-muted-foreground text-end">{t($ => $.editor.items.columns.finalUnitCost)}</span>
            <span className="col-span-2 text-xs text-muted-foreground text-end">{t($ => $.editor.items.columns.total)}</span>
            <span className="col-span-1" />
          </div>
          {lines.map((line, i) => {
            const unitPrice = parseNum(line.unit_price);
            const finalUnitCost = finalUnitCostFor(unitPrice, extraPerUnit, null);
            return (
              <div key={i} className="rounded-md border p-2">
                <div className="grid grid-cols-12 gap-2 items-start">
                  <div className="col-span-3">
                    <div className={`flex items-center gap-1 mb-0.5 ${LEADER_HEIGHT}`}>
                      {line.entity_type === 'raw_material' ? <Boxes className="w-3 h-3 text-amber-600" /> : <Package className="w-3 h-3 text-blue-600" />}
                      <span className="text-[10px] uppercase tracking-wide text-muted-foreground">{typeLabel(line)}</span>
                    </div>
                    <ProductLineSelect entityType={line.entity_type} value={line.product_id} valueLabel={line.product_name} onChange={(id, label) => setProduct(i, id, label)} />
                  </div>
                  <div className="col-span-1">
                    <div className={`mb-0.5 ${LEADER_HEIGHT}`} />
                    <Input type="number" min="0.001" step="0.001" value={line.quantity} onChange={(e) => setQty(i, e.target.value)} aria-label={t($ => $.editor.items.columns.qty)} className="no-spinner h-9 text-sm text-end" />
                  </div>
                  <div className="col-span-2">
                    <div className={`mb-0.5 ${LEADER_HEIGHT}`} />
                    <Input type="number" min="0" step="0.01" value={line.unit_price} onChange={(e) => setPrice(i, e.target.value)} aria-label={t($ => $.editor.items.columns.unitPrice)} className="no-spinner h-9 text-sm text-end" placeholder="0.00" />
                  </div>
                  <div className="col-span-1">
                    <div className={`mb-0.5 ${LEADER_HEIGHT}`} />
                    <Input type="number" min="0" max="100" step="0.01" value={line.tax_rate} onChange={(e) => setTax(i, e.target.value)} aria-label={t($ => $.editor.items.columns.vatPct)} className="no-spinner h-9 text-sm text-end" />
                  </div>
                  <div className="col-span-2">
                    <div className={`mb-0.5 ${LEADER_HEIGHT}`} />
                    <div className="h-9 flex items-center justify-end text-sm tabular-nums text-muted-foreground" title={t($ => $.editor.landedCost.pendingPostHint)}>
                      {fmt.money(finalUnitCost)}
                    </div>
                  </div>
                  <div className="col-span-2">
                    <div className={`mb-0.5 ${LEADER_HEIGHT}`} />
                    <Input type="number" min="0" step="0.01" value={line.line_total} onChange={(e) => setLineTotal(i, e.target.value)} aria-label={t($ => $.editor.items.columns.total)} className="no-spinner h-9 text-sm text-end" placeholder="0.00" />
                  </div>
                  <div className="col-span-1 flex justify-end">
                    <div className={`mb-0.5 ${LEADER_HEIGHT}`} />
                    <Button variant="ghost" size="sm" className="h-9 w-8 p-0 text-muted-foreground hover:text-destructive" onClick={() => removeLine(i)} type="button">
                      <Trash2 className="w-3.5 h-3.5" />
                    </Button>
                  </div>
                </div>

                {/* §9 — explicit Goods Receipt Line anchor, its own row: rarely the visual focus,
                    always secondary to what's being bought/priced above. */}
                <div className="mt-2 pt-2 border-t flex items-center gap-2">
                  <Label className="text-[10px] text-muted-foreground shrink-0 w-32">{t($ => $.editor.anchor.label)}</Label>
                  <div className="flex-1 max-w-sm">
                    <GoodsReceiptLineSelect
                      supplierId={supplierId}
                      productId={line.product_id}
                      value={line.goods_receipt_line_id}
                      onChange={(id) => setAnchor(i, id)}
                      excludeInvoiceId={invoiceId}
                    />
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Mobile stacked cards (§25) */}
      {lines.length > 0 && (
        <div className="lg:hidden space-y-3" data-testid="lines-mobile">
          {lines.map((line, i) => {
            const unitPrice = parseNum(line.unit_price);
            const finalUnitCost = finalUnitCostFor(unitPrice, extraPerUnit, null);
            return (
              <div key={i} className="rounded-lg border p-3 space-y-3">
                <div className="flex items-center justify-between gap-2">
                  <span className="text-[11px] font-medium flex items-center gap-1.5">
                    {line.entity_type === 'raw_material' ? <Boxes className="w-3.5 h-3.5 text-amber-600" /> : <Package className="w-3.5 h-3.5 text-blue-600" />}
                    {typeLabel(line)} #{i + 1}
                  </span>
                  <Button variant="ghost" size="sm" className="h-7 w-7 p-0 text-muted-foreground hover:text-destructive" onClick={() => removeLine(i)} type="button">
                    <Trash2 className="w-3.5 h-3.5" />
                  </Button>
                </div>
                <ProductLineSelect entityType={line.entity_type} value={line.product_id} valueLabel={line.product_name} onChange={(id, label) => setProduct(i, id, label)} />
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <Label className="text-[11px] text-muted-foreground">{t($ => $.editor.items.columns.qty)}</Label>
                    <Input type="number" min="0.001" step="0.001" value={line.quantity} onChange={(e) => setQty(i, e.target.value)} aria-label={`${t($ => $.editor.items.columns.qty)} #${i + 1}`} className="no-spinner mt-1 h-9 text-sm text-end" />
                  </div>
                  <div>
                    <Label className="text-[11px] text-muted-foreground">{t($ => $.editor.items.columns.unitPrice)}</Label>
                    <Input type="number" min="0" step="0.01" value={line.unit_price} onChange={(e) => setPrice(i, e.target.value)} aria-label={`${t($ => $.editor.items.columns.unitPrice)} #${i + 1}`} className="no-spinner mt-1 h-9 text-sm text-end" placeholder="0.00" />
                  </div>
                  <div>
                    <Label className="text-[11px] text-muted-foreground">{t($ => $.editor.items.columns.vatPct)}</Label>
                    <Input type="number" min="0" max="100" step="0.01" value={line.tax_rate} onChange={(e) => setTax(i, e.target.value)} aria-label={`${t($ => $.editor.items.columns.vatPct)} #${i + 1}`} className="no-spinner mt-1 h-9 text-sm text-end" />
                  </div>
                  <div>
                    <Label className="text-[11px] text-muted-foreground">{t($ => $.editor.items.columns.total)}</Label>
                    <Input type="number" min="0" step="0.01" value={line.line_total} onChange={(e) => setLineTotal(i, e.target.value)} aria-label={`${t($ => $.editor.items.columns.total)} #${i + 1}`} className="no-spinner mt-1 h-9 text-sm text-end" placeholder="0.00" />
                  </div>
                  <div className="col-span-2">
                    <Label className="text-[11px] text-muted-foreground">{t($ => $.editor.items.columns.finalUnitCost)}</Label>
                    <div className="mt-1 h-9 flex items-center justify-end text-sm tabular-nums text-muted-foreground px-3 rounded-md border bg-muted/30" title={t($ => $.editor.landedCost.pendingPostHint)}>
                      {fmt.money(finalUnitCost)}
                    </div>
                  </div>
                </div>
                <div className="pt-2 border-t">
                  <Label className="text-[11px] text-muted-foreground">{t($ => $.editor.anchor.label)}</Label>
                  <div className="mt-1">
                    <GoodsReceiptLineSelect
                      supplierId={supplierId}
                      productId={line.product_id}
                      value={line.goods_receipt_line_id}
                      onChange={(id) => setAnchor(i, id)}
                      excludeInvoiceId={invoiceId}
                    />
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
