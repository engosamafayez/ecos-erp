import { useRef, useState, type ReactNode } from 'react';
import { useFormatter } from '@/hooks/use-formatter';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, CheckCircle2, Info, Link2, Loader2, Paperclip, Save, Upload, X } from 'lucide-react';
import { toast } from '@/components/ds/use-toast';

import { Combobox } from '@/components/crud';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { useOrganizationContext } from '@/features/organization/context/organization-context';
import { useCompanyOptions } from '@/features/channels/hooks/use-company-options';
import { useSupplierOptions } from '@/features/purchase-orders/hooks/use-supplier-options';
import { useWarehouseOptions } from '@/features/goods-receipts/hooks/use-warehouse-options';
import {
  useCreateSupplierInvoice,
  useSupplierInvoice,
  useUpdateSupplierInvoice,
  useValidateSupplierInvoice,
} from '@/features/supplier-invoices/hooks/use-supplier-invoices';
import { supplierInvoicesService } from '@/features/supplier-invoices/services/supplier-invoices-service';
import type {
  CreateSupplierInvoicePayload,
  SupplierInvoiceLinePayload,
} from '@/features/supplier-invoices/types/supplier-invoice';
import {
  EMPTY_LINE,
  computeLineTotal,
  type InvoiceLineState,
} from '@/features/supplier-invoices/components/invoice-line-calc';
import { InvoiceLineEditor } from '@/features/supplier-invoices/components/invoice-line-editor';
import { InvoiceAttachments } from '@/features/supplier-invoices/components/invoice-attachments';

const PAYMENT_METHODS = ['bank_transfer', 'cheque', 'cash'] as const;

const num = (s: string): number => {
  const v = parseFloat(s);
  return Number.isFinite(v) ? v : 0;
};

const PAYMENT_STATUS_STYLES: Record<string, string> = {
  unpaid: 'bg-muted text-muted-foreground',
  partially_paid: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
  paid: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
};

/** A titled form section — spacing + heading, no heavy nested panels. */
function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="space-y-3">
      <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</h3>
      {children}
    </section>
  );
}

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  invoiceId?: string | null;
};

export function SupplierInvoiceEditor({ open, onOpenChange, invoiceId = null }: Props) {
  const fmt = useFormatter();
  const { t } = useTranslation('supplier-invoices');
  const { activeCompanyId } = useOrganizationContext();
  const { data: companyOptions = [] } = useCompanyOptions();
  const { data: supplierOptions = [] } = useSupplierOptions();
  const { data: warehouseOptions = [] } = useWarehouseOptions();

  const isEdit = invoiceId !== null;
  const { data: invoice } = useSupplierInvoice(open ? invoiceId : null);
  const createMutation = useCreateSupplierInvoice();
  const updateMutation = useUpdateSupplierInvoice(invoiceId ?? '');
  const validateMutation = useValidateSupplierInvoice();
  const saving = createMutation.isPending || updateMutation.isPending;

  const companyName = companyOptions.find((c) => c.value === activeCompanyId)?.label ?? null;

  const [supplierId, setSupplierId] = useState('');
  const [warehouseId, setWarehouseId] = useState('');
  const [invoiceDate, setInvoiceDate] = useState(new Date().toISOString().slice(0, 10));
  const [dueDate, setDueDate] = useState('');
  const [supplierRef, setSupplierRef] = useState('');
  const [paymentMethod, setPaymentMethod] = useState('');
  const [freight, setFreight] = useState('0');
  const [additional, setAdditional] = useState('0');
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<InvoiceLineState[]>([{ ...EMPTY_LINE }]);

  // Create-time attachment (§1–§3): the file is staged in form state and uploaded AFTER the invoice
  // is created (the canonical DocumentService needs the new invoice id). `createdId` marks that the
  // invoice already exists so Retry never re-creates it.
  const fileRef = useRef<HTMLInputElement>(null);
  const [stagedFile, setStagedFile] = useState<File | null>(null);
  const [createdId, setCreatedId] = useState<string | null>(null);
  const [attaching, setAttaching] = useState(false);
  const [attachError, setAttachError] = useState(false);

  const syncKey = !open ? 'closed' : isEdit ? (invoice?.id ?? 'loading') : 'new';
  const [formKey, setFormKey] = useState('closed');
  if (syncKey !== formKey && syncKey !== 'closed' && syncKey !== 'loading') {
    setFormKey(syncKey);
    setStagedFile(null);
    setCreatedId(null);
    setAttachError(false);
    setAttaching(false);
    if (isEdit && invoice) {
      setSupplierId(invoice.supplier?.id ?? '');
      setWarehouseId(invoice.warehouse?.id ?? '');
      setInvoiceDate(invoice.invoice_date?.slice(0, 10) ?? new Date().toISOString().slice(0, 10));
      setDueDate(invoice.due_date?.slice(0, 10) ?? '');
      setSupplierRef(invoice.supplier_invoice_ref ?? '');
      setPaymentMethod(invoice.payment_method ?? '');
      setFreight(String(invoice.freight_amount ?? 0));
      setAdditional(String(invoice.additional_costs ?? 0));
      setNotes(invoice.notes ?? '');
      setLines(
        invoice.lines.length > 0
          ? invoice.lines.map((l) => ({
              entity_type: l.product_type === 'raw_material' ? 'raw_material' : 'product',
              product_id: l.product_id,
              product_name: l.product ? `${l.product.sku} — ${l.product.name}` : '',
              quantity: String(l.quantity),
              unit_price: String(l.unit_price),
              tax_rate: String(l.tax_rate),
              line_total: String(l.line_total),
            }))
          : [{ ...EMPTY_LINE }],
      );
    } else {
      setSupplierId('');
      setWarehouseId('');
      setInvoiceDate(new Date().toISOString().slice(0, 10));
      setDueDate('');
      setSupplierRef('');
      setPaymentMethod('');
      setFreight('0');
      setAdditional('0');
      setNotes('');
      setLines([{ ...EMPTY_LINE }]);
    }
  } else if (syncKey === 'closed' && formKey !== 'closed') {
    setFormKey('closed');
  }

  const itemsTotal = lines.reduce((s, l) => s + computeLineTotal(num(l.quantity), num(l.unit_price), num(l.tax_rate)), 0);
  const grandTotal = itemsTotal + num(freight) + num(additional);
  const editable = !isEdit || invoice?.status === 'draft' || invoice?.status === 'failed';
  const canValidate = isEdit && invoice?.status === 'draft';
  const payment = invoice?.payment;
  const receiptLinks = invoice?.receipt_links ?? [];

  function buildPayload(): CreateSupplierInvoicePayload {
    return {
      supplier_id: supplierId,
      warehouse_id: warehouseId,
      invoice_date: invoiceDate,
      due_date: dueDate || null,
      supplier_invoice_ref: supplierRef || null,
      payment_method: paymentMethod || null,
      freight_amount: num(freight),
      additional_costs: num(additional),
      notes: notes || null,
      lines: lines
        .filter((l) => l.product_id && num(l.quantity) > 0 && num(l.unit_price) >= 0)
        .map((l): SupplierInvoiceLinePayload => ({
          product_id: l.product_id,
          quantity: num(l.quantity),
          unit_price: num(l.unit_price),
          tax_rate: num(l.tax_rate) || 0,
        })),
    };
  }

  function validateForm(): boolean {
    if (!supplierId) { toast.error(t($ => $.editor.toast.supplierRequired)); return false; }
    if (!warehouseId) { toast.error(t($ => $.editor.toast.warehouseRequired)); return false; }
    if (!invoiceDate) { toast.error(t($ => $.editor.toast.invoiceDateRequired)); return false; }
    if (buildPayload().lines.length === 0) { toast.error(t($ => $.editor.toast.atLeastOneItem)); return false; }
    return true;
  }

  /** Upload the staged file to an existing invoice; true on success. */
  async function uploadStaged(id: string): Promise<boolean> {
    if (!stagedFile) return true;
    setAttaching(true);
    try {
      await supplierInvoicesService.uploadDocument(id, stagedFile);
      return true;
    } catch {
      return false;
    } finally {
      setAttaching(false);
    }
  }

  async function handleSave() {
    if (createdId) return; // invoice already created — use Retry, never re-create
    if (!validateForm()) return;

    if (isEdit && invoiceId) {
      await updateMutation.mutateAsync(buildPayload());
      onOpenChange(false);
      return;
    }

    const created = await createMutation.mutateAsync(buildPayload());
    setCreatedId(created.id);
    if (!stagedFile) { onOpenChange(false); return; }
    const ok = await uploadStaged(created.id);
    if (ok) { toast.success(t($ => $.attachments.uploaded)); onOpenChange(false); }
    else { setAttachError(true); toast.error(t($ => $.attachments.uploadFailed)); }
  }

  async function retryAttachment() {
    if (!createdId) return;
    const ok = await uploadStaged(createdId);
    if (ok) { setAttachError(false); toast.success(t($ => $.attachments.uploaded)); onOpenChange(false); }
    else { toast.error(t($ => $.attachments.uploadFailed)); }
  }

  function handleValidate() {
    if (!invoiceId) return;
    validateMutation.mutate(invoiceId, { onSuccess: () => onOpenChange(false) });
  }

  const invoiceCreatedAttachPending = createdId !== null && attachError;

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="overflow-y-auto w-full sm:max-w-3xl lg:max-w-4xl">
        <SheetHeader className="pb-4">
          <SheetTitle>{isEdit ? t($ => $.drawer.editTitle) : t($ => $.drawer.createTitle)}</SheetTitle>
          <p className="text-xs text-muted-foreground">{t($ => $.editor.commercialSubtitle)}</p>
        </SheetHeader>

        {/* Receiving/inventory boundary — explanatory only. */}
        <div className="mb-5 flex items-start gap-2 rounded-md border border-blue-200/60 bg-blue-50/50 dark:border-blue-900/40 dark:bg-blue-950/20 p-2.5">
          <Info className="w-3.5 h-3.5 text-blue-500 mt-0.5 shrink-0" />
          <p className="text-[11px] text-blue-700 dark:text-blue-300">{t($ => $.editor.boundaryNote)}</p>
        </div>

        <div className="space-y-6">
          {/* ── GENERAL INFORMATION ─────────────────────────────────────────────── */}
          <Section title={t($ => $.editor.sections.generalInfo)}>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div className="sm:col-span-2">
                <Label className="text-xs">{t($ => $.editor.fields.company)}</Label>
                <div className="mt-1 h-9 flex items-center rounded-md border bg-muted/40 px-3 text-sm text-muted-foreground">
                  {companyName ?? t($ => $.editor.companyContext)}
                </div>
              </div>
              <div>
                <Label className="text-xs">{t($ => $.editor.fields.supplier)}</Label>
                <div className="mt-1">
                  <Combobox options={supplierOptions} value={supplierId} onChange={setSupplierId} placeholder={t($ => $.editor.placeholders.selectSupplier)} />
                </div>
              </div>
              <div>
                <Label className="text-xs">{t($ => $.editor.fields.warehouse)}</Label>
                <div className="mt-1">
                  <Combobox options={warehouseOptions} value={warehouseId} onChange={setWarehouseId} placeholder={t($ => $.editor.placeholders.selectWarehouse)} />
                </div>
              </div>
              <div>
                <Label className="text-xs">{t($ => $.editor.fields.invoiceDate)}</Label>
                <Input type="date" value={invoiceDate} onChange={(e) => setInvoiceDate(e.target.value)} className="mt-1 h-9 text-sm" />
              </div>
              <div>
                <Label className="text-xs">{t($ => $.editor.fields.supplierRef)}</Label>
                <Input value={supplierRef} onChange={(e) => setSupplierRef(e.target.value)} placeholder={t($ => $.editor.placeholders.supplierRef)} className="mt-1 h-9 text-sm" />
              </div>
              <div className="sm:col-span-2">
                <Label className="text-xs">{t($ => $.editor.fields.notes)}</Label>
                <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder={t($ => $.editor.fields.notesPlaceholder)} className="mt-1 h-9 text-sm" />
              </div>
            </div>

            {/* Attachment (§1–§3): live control on edit; staged (upload-on-create) on create. */}
            {isEdit && invoiceId ? (
              <InvoiceAttachments invoiceId={invoiceId} />
            ) : createdId ? (
              <InvoiceAttachments invoiceId={createdId} />
            ) : (
              <div className="space-y-1.5">
                <Label className="text-xs font-semibold uppercase flex items-center gap-1.5">
                  <Paperclip className="w-3.5 h-3.5" />{t($ => $.attachments.title)}
                </Label>
                <input
                  ref={fileRef}
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png,.webp"
                  className="hidden"
                  onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) setStagedFile(f); }}
                />
                {stagedFile ? (
                  <div className="flex items-center gap-2 rounded-md border px-2.5 py-1.5">
                    <Paperclip className="w-3.5 h-3.5 text-muted-foreground shrink-0" />
                    <span className="text-xs font-medium truncate flex-1">{stagedFile.name}</span>
                    <Button variant="ghost" size="sm" className="h-7 w-7 p-0" type="button" onClick={() => setStagedFile(null)} aria-label={t($ => $.attachments.delete)}>
                      <X className="w-3.5 h-3.5" />
                    </Button>
                  </div>
                ) : (
                  <Button variant="outline" size="sm" className="h-8 text-xs gap-1.5" type="button" onClick={() => fileRef.current?.click()}>
                    <Upload className="w-3.5 h-3.5" />{t($ => $.editor.attachment.select)}
                  </Button>
                )}
                <p className="text-[10px] text-muted-foreground">{t($ => $.editor.attachment.stagedNote)}</p>
              </div>
            )}
          </Section>

          {/* ── ITEMS ───────────────────────────────────────────────────────────── */}
          <Section title={t($ => $.editor.sections.items)}>
            <InvoiceLineEditor lines={lines} onLinesChange={setLines} />
          </Section>

          {/* ── INVOICE TOTALS ──────────────────────────────────────────────────── */}
          <Section title={t($ => $.editor.sections.invoiceTotals)}>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-3">
                <div>
                  <Label className="text-xs">{t($ => $.editor.additionalCosts.freight)}</Label>
                  <Input type="number" min="0" step="0.01" value={freight} onChange={(e) => setFreight(e.target.value)} className="mt-1 h-9 text-sm text-end" />
                </div>
                <div>
                  <Label className="text-xs">{t($ => $.editor.additionalCosts.additionalCosts)}</Label>
                  <Input type="number" min="0" step="0.01" value={additional} onChange={(e) => setAdditional(e.target.value)} className="mt-1 h-9 text-sm text-end" />
                </div>
              </div>

              <div className="rounded-lg border bg-muted/40 p-4 space-y-2 self-start">
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">{t($ => $.editor.summary.itemsTotal)}</span>
                  <span className="tabular-nums">{fmt.money(itemsTotal)}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">{t($ => $.editor.summary.freight)}</span>
                  <span className="tabular-nums">{fmt.money(num(freight))}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">{t($ => $.editor.summary.additionalCosts)}</span>
                  <span className="tabular-nums">{fmt.money(num(additional))}</span>
                </div>
                <div className="my-1 border-t" />
                <div className="flex justify-between text-base font-semibold">
                  <span>{t($ => $.editor.summary.invoiceTotal)}</span>
                  <span className="tabular-nums">{fmt.money(grandTotal)}</span>
                </div>
              </div>
            </div>
          </Section>

          {/* ── PAYMENT SUMMARY ─── method/due editable; Paid/Remaining/Status DERIVED read-only.
               Actual payment RECORDING is a Finance/AP action (finance/ap/payments/*), not this form. */}
          <Section title={t($ => $.editor.sections.paymentSummary)}>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <Label className="text-xs">{t($ => $.editor.payment.method)}</Label>
                <select
                  value={paymentMethod}
                  onChange={(e) => setPaymentMethod(e.target.value)}
                  aria-label={t($ => $.editor.payment.method)}
                  className="mt-1 border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs"
                >
                  <option value="">{t($ => $.editor.payment.methodNone)}</option>
                  {PAYMENT_METHODS.map((m) => (
                    <option key={m} value={m}>{t($ => $.payment.methods[m])}</option>
                  ))}
                </select>
              </div>
              <div>
                <Label className="text-xs">{t($ => $.editor.fields.dueDate)}</Label>
                <Input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} className="mt-1 h-9 text-sm" />
              </div>
            </div>
            <p className="text-[10px] text-muted-foreground">{t($ => $.editor.payment.methodHelper)}</p>

            {payment ? (
              <div className="rounded-lg border p-3 grid grid-cols-3 gap-3">
                <div>
                  <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{t($ => $.editor.payment.paid)}</p>
                  <p className="text-sm tabular-nums">{fmt.money(payment.paid)}</p>
                </div>
                <div>
                  <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{t($ => $.editor.payment.remaining)}</p>
                  <p className="text-sm tabular-nums font-medium">{fmt.money(payment.remaining)}</p>
                </div>
                <div>
                  <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{t($ => $.editor.payment.status)}</p>
                  <Badge className={`${PAYMENT_STATUS_STYLES[payment.payment_status]} border-0 text-[10px] mt-0.5`} variant="secondary">
                    {t($ => $.editor.payment.statuses[payment.payment_status])}
                  </Badge>
                </div>
              </div>
            ) : (
              <div className="rounded-lg border border-dashed p-3 text-xs text-muted-foreground">
                {t($ => $.editor.payment.notYetAvailable)}
              </div>
            )}
          </Section>

          {/* ── PROCUREMENT LINKAGE (edit, read-only) ───────────────────────────── */}
          {isEdit && receiptLinks.length > 0 && (
            <Section title={t($ => $.editor.sections.linkage)}>
              <div className="space-y-2">
                {receiptLinks.map((link) => (
                  <div key={link.line_id} className="rounded-lg border p-3 text-xs space-y-1.5">
                    <div className="flex justify-between gap-2">
                      <span className="font-medium truncate flex items-center gap-1.5"><Link2 className="w-3 h-3 text-muted-foreground" />{link.product ?? '—'}</span>
                      <span className="font-mono text-muted-foreground shrink-0">{link.receipt_number ?? '—'}{link.po_number ? ` · ${link.po_number}` : ''}</span>
                    </div>
                    <div className="grid grid-cols-3 gap-2 tabular-nums">
                      <div><span className="text-muted-foreground">{t($ => $.detail.receiptLinks.ordered)}: </span>{link.ordered_qty ?? '—'}</div>
                      <div><span className="text-muted-foreground">{t($ => $.detail.receiptLinks.received)}: </span>{link.received_qty ?? '—'}</div>
                      <div><span className="text-muted-foreground">{t($ => $.detail.receiptLinks.invoiced)}: </span>{link.invoiced_qty}</div>
                    </div>
                  </div>
                ))}
              </div>
            </Section>
          )}

          {/* ── ACTIONS ─────────────────────────────────────────────────────────── */}
          {invoiceCreatedAttachPending ? (
            <div className="space-y-2 pt-2 border-t">
              <div className="flex items-start gap-2 rounded-md border border-amber-300/60 bg-amber-50/50 dark:border-amber-900/40 dark:bg-amber-950/20 p-2.5">
                <AlertTriangle className="w-3.5 h-3.5 text-amber-600 mt-0.5 shrink-0" />
                <p className="text-[11px] text-amber-700 dark:text-amber-300">{t($ => $.editor.attachment.failedTitle)}</p>
              </div>
              <div className="flex gap-2">
                <Button className="flex-1 gap-1.5" onClick={retryAttachment} disabled={attaching}>
                  {attaching ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Upload className="w-3.5 h-3.5" />}
                  {t($ => $.editor.attachment.retry)}
                </Button>
                <Button variant="outline" onClick={() => onOpenChange(false)}>{t($ => $.editor.close)}</Button>
              </div>
            </div>
          ) : (
            <div className="flex gap-2 pt-2 border-t">
              <Button className="flex-1 gap-1.5" onClick={handleSave} disabled={saving || attaching || !editable}>
                {saving || attaching ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
                {isEdit ? t($ => $.drawer.submitEdit) : t($ => $.drawer.submitCreate)}
              </Button>
              {canValidate && (
                <Button variant="outline" className="gap-1.5" onClick={handleValidate} disabled={validateMutation.isPending}>
                  <CheckCircle2 className="w-3.5 h-3.5" />
                  {t($ => $.detail.validate)}
                </Button>
              )}
              <Button variant="outline" onClick={() => onOpenChange(false)}>{t($ => $.editor.buttons.cancel)}</Button>
            </div>
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}
