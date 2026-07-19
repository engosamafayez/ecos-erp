import { useState } from 'react';
import {
  AlertCircle,
  CheckCircle,
  Clock,
  Loader2,
  PauseCircle,
  Send,
  ShoppingCart,
  Truck,
  XCircle,
} from 'lucide-react';

import { ErrorState, LoadingState } from '@/components/crud';
import { Button } from '@/components/ui/button';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { getMediaUrl } from '@/lib/media';
import { toast } from '@/components/ds/use-toast';

import {
  useApprovePurchaseMaterial,
  useAssignBuyer,
  useCancelPurchaseMaterial,
  useHoldPurchaseMaterial,
  useProductProcurementPanel,
  usePurchaseMaterialQuery,
  useRejectPurchaseMaterial,
  useSelectLineSupplier,
  useSubmitPurchaseMaterial,
} from '../hooks/use-purchase-materials';
import type { PurchaseMaterial, PurchaseMaterialLine } from '../types/purchase-material';
import { PurchaseMaterialStatusBadge } from './purchase-material-status-badge';
import { PurchaseMaterialPriorityBadge } from './purchase-material-priority-badge';

// ── Helpers ────────────────────────────────────────────────────────────────────

function fmt(d: string | null | undefined): string {
  if (!d) return '—';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(d));
}

function fmtNum(n: number | null | undefined, decimals = 2): string {
  if (n == null) return '—';
  return n.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

function SectionLabel({ children }: { children: React.ReactNode }) {
  return (
    <p className="text-[10px] uppercase tracking-wider font-semibold text-muted-foreground mb-1.5">
      {children}
    </p>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs text-muted-foreground">{label}</p>
      <div className="text-sm font-medium mt-0.5">{children}</div>
    </div>
  );
}

// ── Tab: Overview ──────────────────────────────────────────────────────────────

function OverviewTab({ material }: { material: PurchaseMaterial }) {
  return (
    <div className="flex flex-col gap-5 text-sm">
      <div className="grid grid-cols-2 gap-x-6 gap-y-3">
        <Field label="رقم الطلب">
          <span className="font-mono">{material.request_number}</span>
        </Field>
        <Field label="الحالة">
          <PurchaseMaterialStatusBadge status={material.status} />
        </Field>
        <Field label="الشركة">{material.company?.name ?? '—'}</Field>
        <Field label="القناة">{material.channel_id ?? '—'}</Field>
        <Field label="المستودع">{material.warehouse?.name ?? '—'}</Field>
        <Field label="الأولوية">
          <PurchaseMaterialPriorityBadge priority={material.priority} />
        </Field>
        <Field label="مطلوب بحلول">{fmt(material.required_date)}</Field>
        <Field label="طلب بواسطة">{material.requested_by ?? '—'}</Field>
        {material.assigned_buyer && (
          <Field label="المشتري المعيَّن">
            <span className="flex items-center gap-1.5">
              <Truck className="size-3.5 text-muted-foreground" />
              {material.assigned_buyer}
            </span>
          </Field>
        )}
        <Field label="تاريخ الإنشاء">{fmt(material.created_at)}</Field>
      </div>

      {material.notes && (
        <div>
          <SectionLabel>ملاحظات</SectionLabel>
          <p className="text-sm whitespace-pre-wrap rounded-md border bg-muted/20 px-3 py-2">{material.notes}</p>
        </div>
      )}

      {material.rejection_reason && (
        <div className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2">
          <p className="text-xs font-medium text-destructive mb-0.5">سبب الرفض</p>
          <p className="text-sm">{material.rejection_reason}</p>
        </div>
      )}

      <div>
        <SectionLabel>إحصاءات سريعة</SectionLabel>
        <div className="grid grid-cols-3 gap-2">
          {[
            { label: 'البنود', value: String(material.items_count), mono: false },
            { label: 'إجمالي الكمية', value: fmtNum(material.total_requested_qty, 0), mono: true },
            { label: 'القيمة التقديرية', value: fmtNum(material.estimated_value, 0), mono: true },
          ].map(({ label, value, mono }) => (
            <div key={label} className="rounded-lg border bg-muted/20 px-3 py-2.5 text-center">
              <p className="text-[10px] text-muted-foreground">{label}</p>
              <p className={`text-base font-semibold mt-0.5 ${mono ? 'font-mono tabular-nums' : ''}`}>{value}</p>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

// ── Tab: Requested Items ───────────────────────────────────────────────────────

function RequestedItemsTab({ material }: { material: PurchaseMaterial }) {
  const lines = material.lines ?? [];
  if (lines.length === 0) {
    return <p className="text-sm text-muted-foreground italic py-4">لا توجد بنود في هذا الطلب.</p>;
  }
  return (
    <div className="border rounded-md overflow-hidden">
      <table className="w-full text-sm">
        <thead className="bg-muted/40">
          <tr>
            <th className="px-3 py-2 text-start font-medium text-xs text-muted-foreground">المادة</th>
            <th className="px-3 py-2 text-end font-medium text-xs text-muted-foreground">الكمية المطلوبة</th>
            <th className="px-3 py-2 text-start font-medium text-xs text-muted-foreground">ملاحظات</th>
          </tr>
        </thead>
        <tbody>
          {lines.map((line) => (
            <tr key={line.id} className="border-t hover:bg-muted/20 transition-colors">
              <td className="px-3 py-2">
                <div className="flex items-center gap-2">
                  {line.product?.image_url ? (
                    <img
                      src={getMediaUrl(line.product.image_url) ?? undefined}
                      alt={line.product.name}
                      className="size-7 rounded object-cover border"
                    />
                  ) : (
                    <div className="size-7 rounded bg-muted flex items-center justify-center text-[10px] text-muted-foreground border">—</div>
                  )}
                  <div>
                    <p className="font-medium leading-tight">{line.product?.name ?? '—'}</p>
                    <p className="text-[10px] text-muted-foreground">{line.product?.sku}</p>
                  </div>
                </div>
              </td>
              <td className="px-3 py-2 text-end font-mono text-xs tabular-nums">
                {fmtNum(line.requested_qty, 4).replace(/\.?0+$/, '')}
                {line.unit_label && <span className="text-muted-foreground ml-1">{line.unit_label}</span>}
              </td>
              <td className="px-3 py-2 text-muted-foreground text-xs">{line.notes ?? '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// ── Tab: Demand Analysis ───────────────────────────────────────────────────────

function DemandAnalysisLineRow({ line, warehouseId }: { line: PurchaseMaterialLine; warehouseId: string }) {
  const { data: panel, isLoading } = useProductProcurementPanel(line.product_id, { warehouse_id: warehouseId });

  return (
    <div className="rounded-lg border p-3 flex flex-col gap-2">
      <div className="flex items-center gap-2">
        {line.product?.image_url ? (
          <img src={getMediaUrl(line.product.image_url) ?? undefined} alt="" className="size-6 rounded object-cover border" />
        ) : (
          <div className="size-6 rounded bg-muted border" />
        )}
        <p className="font-medium text-sm">{line.product?.name ?? '—'}</p>
      </div>
      {isLoading ? (
        <div className="flex items-center gap-2 text-xs text-muted-foreground py-1">
          <Loader2 className="size-3.5 animate-spin" /> جارٍ التحميل…
        </div>
      ) : panel ? (
        <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
          <div>
            <span className="text-muted-foreground">المتاح: </span>
            <span className="font-mono font-semibold">{fmtNum(panel.inventory.available_qty, 0)}</span>
          </div>
          <div>
            <span className="text-muted-foreground">متوسط يومي: </span>
            <span className="font-mono font-semibold">{fmtNum(panel.consumption.daily_avg, 2)}</span>
          </div>
          <div>
            <span className="text-muted-foreground">التغطية: </span>
            <span className="font-mono font-semibold">
              {panel.coverage.days_remaining != null ? `${fmtNum(panel.coverage.days_remaining, 0)} أيام` : '—'}
            </span>
          </div>
          <div>
            <span className="text-muted-foreground">الاتجاه: </span>
            <span className="capitalize font-medium">{panel.consumption.trend}</span>
          </div>
          {panel.recommendations.length > 0 && (
            <div className="col-span-2 mt-1">
              {panel.recommendations.map((r, i) => (
                <div
                  key={i}
                  className={`text-[10px] rounded px-2 py-1 border ${
                    r.severity === 'error' ? 'bg-red-50 border-red-200 text-red-700' :
                    r.severity === 'warning' ? 'bg-amber-50 border-amber-200 text-amber-700' :
                    'bg-blue-50 border-blue-200 text-blue-700'
                  }`}
                >
                  {r.message}
                </div>
              ))}
            </div>
          )}
        </div>
      ) : (
        <p className="text-xs text-muted-foreground">لا توجد بيانات طلب متاحة.</p>
      )}
    </div>
  );
}

function DemandAnalysisTab({ material }: { material: PurchaseMaterial }) {
  const lines = material.lines ?? [];
  if (lines.length === 0) {
    return <p className="text-sm text-muted-foreground italic py-4">لا توجد بنود للتحليل.</p>;
  }
  return (
    <div className="flex flex-col gap-3">
      {lines.map((line) => (
        <DemandAnalysisLineRow key={line.id} line={line} warehouseId={material.warehouse_id} />
      ))}
    </div>
  );
}

// ── Tab: Procurement Review ────────────────────────────────────────────────────

function ProcurementReviewTab({ material }: { material: PurchaseMaterial }) {
  const [buyerName, setBuyerName] = useState(material.assigned_buyer ?? '');
  const assignBuyer = useAssignBuyer(material.id);

  async function handleAssignBuyer() {
    if (!buyerName.trim()) return;
    try {
      await assignBuyer.mutateAsync(buyerName.trim());
      toast.success('تم تعيين المشتري.');
    } catch {
      toast.error('فشل تعيين المشتري.');
    }
  }

  return (
    <div className="flex flex-col gap-5 text-sm">
      <div>
        <SectionLabel>تعيين مشتري</SectionLabel>
        <div className="flex gap-2">
          <input
            className="flex-1 rounded-md border border-input bg-transparent px-3 py-1.5 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            placeholder="اسم المشتري…"
            value={buyerName}
            onChange={(e) => setBuyerName(e.target.value)}
          />
          <Button size="sm" disabled={!buyerName.trim() || assignBuyer.isPending} onClick={() => void handleAssignBuyer()}>
            {assignBuyer.isPending && <Loader2 className="size-3.5 animate-spin mr-1.5" />}
            تعيين
          </Button>
        </div>
        {material.assigned_buyer && (
          <p className="text-xs text-muted-foreground mt-1.5">
            معيَّن حاليًا لـ: <span className="font-medium text-foreground">{material.assigned_buyer}</span>
          </p>
        )}
      </div>

      {material.review_notes && (
        <div>
          <SectionLabel>ملاحظات المراجعة</SectionLabel>
          <p className="text-sm whitespace-pre-wrap rounded-md border bg-muted/20 px-3 py-2">
            {material.review_notes}
          </p>
        </div>
      )}

      {material.clarification_requested_at && (
        <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm">
          <p className="font-medium text-amber-800 text-xs mb-0.5">طُلب التوضيح</p>
          <p className="text-amber-700">{fmt(material.clarification_requested_at)}</p>
        </div>
      )}

      <div className="grid grid-cols-2 gap-3">
        <Field label="تاريخ الإرسال">{fmt(material.submitted_at)}</Field>
        <Field label="تاريخ الاعتماد">{fmt(material.approved_at)}</Field>
        <Field label="اعتمد بواسطة">{material.approved_by ?? '—'}</Field>
        {material.rejection_reason && (
          <Field label="سبب الرفض">{material.rejection_reason}</Field>
        )}
      </div>
    </div>
  );
}

// ── Tab: Supplier Selection ────────────────────────────────────────────────────

function SupplierSelectionLineRow({ line, materialId }: { line: PurchaseMaterialLine; materialId: string }) {
  const [supplierId, setSupplierId] = useState(line.supplier_id ?? '');
  const [agreedPrice, setAgreedPrice] = useState(line.agreed_price?.toString() ?? '');
  const [agreedQty, setAgreedQty] = useState(line.agreed_qty?.toString() ?? '');
  const [leadTime, setLeadTime] = useState(line.lead_time_days?.toString() ?? '');
  const selectSupplier = useSelectLineSupplier(materialId);

  const { data: panel } = useProductProcurementPanel(line.product_id);

  async function handleSelect() {
    if (!supplierId.trim()) return;
    try {
      await selectSupplier.mutateAsync({
        lineId: line.id,
        supplier_id: supplierId,
        agreed_price: agreedPrice ? parseFloat(agreedPrice) : null,
        agreed_qty: agreedQty ? parseFloat(agreedQty) : null,
        lead_time_days: leadTime ? parseInt(leadTime) : null,
      });
      toast.success('تم تحديد المورد للبند.');
    } catch {
      toast.error('فشل تحديد المورد.');
    }
  }

  return (
    <div className="rounded-lg border p-3 flex flex-col gap-3">
      <div className="flex items-center gap-2">
        {line.product?.image_url ? (
          <img src={getMediaUrl(line.product.image_url) ?? undefined} alt="" className="size-6 rounded object-cover border" />
        ) : (
          <div className="size-6 rounded bg-muted border" />
        )}
        <div className="flex-1 min-w-0">
          <p className="font-medium text-sm">{line.product?.name ?? '—'}</p>
          <p className="text-[10px] text-muted-foreground">{line.product?.sku}</p>
        </div>
        {line.supplier && (
          <span className="text-xs text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-full px-2 py-0.5 font-medium">
            {line.supplier.name}
          </span>
        )}
      </div>

      {/* Alternative suppliers for reference */}
      {panel && panel.alternative_suppliers.length > 0 && (
        <div>
          <p className="text-[10px] text-muted-foreground uppercase tracking-wide font-medium mb-1">مرجع: الموردون المعروفون</p>
          <div className="flex flex-col gap-1">
            {panel.alternative_suppliers.slice(0, 3).map((s) => (
              <div key={s.supplier_id} className="flex items-center justify-between rounded bg-muted/30 px-2 py-1 text-xs">
                <span className="font-medium">{s.supplier_name}</span>
                <span className="text-muted-foreground font-mono">
                  {s.last_price != null ? fmtNum(s.last_price, 2) : '—'}
                  {s.lead_time_days != null ? ` · ${s.lead_time_days}d` : ''}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Supplier entry (ID-based; full supplier picker deferred to Supplier Selection workspace) */}
      <div className="grid grid-cols-2 gap-2">
        <div className="col-span-2">
          <label className="text-xs text-muted-foreground">معرّف المورد</label>
          <input
            className="w-full mt-0.5 rounded-md border border-input bg-transparent px-3 py-1.5 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            placeholder="UUID المورد…"
            value={supplierId}
            onChange={(e) => setSupplierId(e.target.value)}
          />
        </div>
        <div>
          <label className="text-xs text-muted-foreground">السعر المتفق عليه</label>
          <input
            type="number"
            min="0"
            step="0.01"
            className="w-full mt-0.5 rounded-md border border-input bg-transparent px-3 py-1.5 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            placeholder="0.00"
            value={agreedPrice}
            onChange={(e) => setAgreedPrice(e.target.value)}
          />
        </div>
        <div>
          <label className="text-xs text-muted-foreground">الكمية المتفق عليها</label>
          <input
            type="number"
            min="0"
            step="0.0001"
            className="w-full mt-0.5 rounded-md border border-input bg-transparent px-3 py-1.5 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            placeholder={fmtNum(line.requested_qty, 4).replace(/\.?0+$/, '')}
            value={agreedQty}
            onChange={(e) => setAgreedQty(e.target.value)}
          />
        </div>
        <div>
          <label className="text-xs text-muted-foreground">مدة التوريد (أيام)</label>
          <input
            type="number"
            min="0"
            className="w-full mt-0.5 rounded-md border border-input bg-transparent px-3 py-1.5 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            placeholder="—"
            value={leadTime}
            onChange={(e) => setLeadTime(e.target.value)}
          />
        </div>
        <div className="col-span-2 flex justify-end pt-1">
          <Button size="sm" disabled={!supplierId.trim() || selectSupplier.isPending} onClick={() => void handleSelect()}>
            {selectSupplier.isPending && <Loader2 className="size-3.5 animate-spin mr-1.5" />}
            <ShoppingCart className="size-3.5 mr-1.5" />
            تأكيد المورد
          </Button>
        </div>
      </div>
    </div>
  );
}

function SupplierSelectionTab({ material }: { material: PurchaseMaterial }) {
  const lines = material.lines ?? [];
  const isSelectable =
    material.status === 'waiting_supplier_selection' || material.status === 'approved';

  if (!isSelectable) {
    return (
      <div className="flex flex-col items-center justify-center py-10 gap-2 text-muted-foreground text-sm">
        <ShoppingCart className="size-8 text-muted-foreground/30" />
        <p>يتوفر اختيار المورد بعد انتقال الطلب إلى حالة <strong>انتظار المورد</strong> أو <strong>معتمد</strong>.</p>
        <p className="text-xs">الحالة الحالية: {material.status_label}</p>
      </div>
    );
  }

  if (lines.length === 0) {
    return <p className="text-sm text-muted-foreground italic py-4">لا توجد بنود في هذا الطلب.</p>;
  }

  return (
    <div className="flex flex-col gap-3">
      <p className="text-xs text-muted-foreground">
        حدد موردًا لكل بند مادة. لا يمكن لمديري المستودعات رؤية هذه التبويبة.
      </p>
      {lines.map((line) => (
        <SupplierSelectionLineRow key={line.id} line={line} materialId={material.id} />
      ))}
    </div>
  );
}

// ── Tab: Financial Summary ─────────────────────────────────────────────────────

function FinancialSummaryTab({ material }: { material: PurchaseMaterial }) {
  const lines = material.lines ?? [];
  return (
    <div className="flex flex-col gap-5 text-sm">
      <div className="grid grid-cols-2 gap-3">
        {[
          { label: 'القيمة التقديرية', value: material.estimated_value },
          { label: 'القيمة المعتمدة', value: material.approved_value },
          { label: 'القيمة المشتراة', value: material.purchased_value },
          {
            label: 'المستحق',
            value: Math.max(0, (material.approved_value || material.estimated_value) - material.purchased_value),
          },
        ].map(({ label, value }) => (
          <div key={label} className="rounded-lg border bg-muted/20 px-3 py-2.5">
            <p className="text-[10px] text-muted-foreground">{label}</p>
            <p className="text-lg font-semibold font-mono tabular-nums mt-0.5">{fmtNum(value, 0)}</p>
          </div>
        ))}
      </div>

      {lines.length > 0 && (
        <div>
          <SectionLabel>قيمة البنود</SectionLabel>
          <div className="border rounded-md overflow-hidden">
            <table className="w-full text-xs">
              <thead className="bg-muted/40">
                <tr>
                  <th className="px-3 py-2 text-start font-medium text-muted-foreground">المادة</th>
                  <th className="px-3 py-2 text-end font-medium text-muted-foreground">الكمية المطلوبة</th>
                  <th className="px-3 py-2 text-end font-medium text-muted-foreground">تكلفة الوحدة</th>
                  <th className="px-3 py-2 text-end font-medium text-muted-foreground">قيمة البند</th>
                </tr>
              </thead>
              <tbody>
                {lines.map((line) => {
                  const unitCost = line.agreed_price ?? line.product?.average_cost ?? null;
                  const lineValue = unitCost != null ? line.requested_qty * unitCost : null;
                  return (
                    <tr key={line.id} className="border-t">
                      <td className="px-3 py-2">{line.product?.name ?? '—'}</td>
                      <td className="px-3 py-2 text-end font-mono tabular-nums">
                        {fmtNum(line.requested_qty, 4).replace(/\.?0+$/, '')}
                      </td>
                      <td className="px-3 py-2 text-end font-mono tabular-nums text-muted-foreground">
                        {unitCost != null ? fmtNum(unitCost, 2) : '—'}
                      </td>
                      <td className="px-3 py-2 text-end font-mono tabular-nums font-medium">
                        {lineValue != null ? fmtNum(lineValue, 0) : '—'}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}

// ── Tab: Timeline ──────────────────────────────────────────────────────────────

function TimelineTab({ material }: { material: PurchaseMaterial }) {
  const events = [
    { label: 'تم الإنشاء', date: material.created_at, icon: Clock, color: 'text-slate-500' },
    { label: 'تم الإرسال', date: material.submitted_at, icon: Send, color: 'text-blue-500' },
    { label: 'تم الاعتماد', date: material.approved_at, icon: CheckCircle, color: 'text-emerald-500' },
    { label: 'مكتمل', date: (material.status === 'completed' ? material.updated_at : null), icon: Truck, color: 'text-cyan-500' },
    { label: 'مرفوض', date: (material.status === 'rejected' ? material.updated_at : null), icon: XCircle, color: 'text-red-500' },
    { label: 'معلّق', date: (material.status === 'on_hold' ? material.updated_at : null), icon: PauseCircle, color: 'text-amber-500' },
    { label: 'ملغى', date: (material.status === 'cancelled' ? material.updated_at : null), icon: XCircle, color: 'text-slate-400' },
  ].filter((e) => e.date);

  if (events.length === 0) {
    return <p className="text-sm text-muted-foreground italic py-4">لا توجد أحداث في الجدول الزمني.</p>;
  }

  return (
    <div className="flex flex-col gap-0 relative">
      <div className="absolute left-[15px] top-4 bottom-4 w-px bg-border" />
      {events.map(({ label, date, icon: Icon, color }) => (
        <div key={label} className="flex gap-3 relative">
          <div className={`size-8 rounded-full border bg-background flex items-center justify-center shrink-0 z-10 ${color}`}>
            <Icon className="size-4" />
          </div>
          <div className="flex-1 py-1.5">
            <p className="text-sm font-medium">{label}</p>
            <p className="text-xs text-muted-foreground">{fmt(date)}</p>
          </div>
        </div>
      ))}
    </div>
  );
}

// ── Placeholder tab ────────────────────────────────────────────────────────────

function PlaceholderTab({ label }: { label: string }) {
  return (
    <div className="flex flex-col items-center justify-center py-16 gap-2 text-muted-foreground">
      <AlertCircle className="size-8 text-muted-foreground/30" />
      <p className="text-sm">{label} غير مُنفَّذ بعد في هذا الإصدار.</p>
    </div>
  );
}

// ── Main drawer ────────────────────────────────────────────────────────────────

const TABS = [
  { id: 'overview', label: 'نظرة عامة' },
  { id: 'items', label: 'البنود' },
  { id: 'demand', label: 'الطلب' },
  { id: 'review', label: 'المراجعة' },
  { id: 'supplier', label: 'المورد' },
  { id: 'purchasing', label: 'الشراء' },
  { id: 'receipt', label: 'الاستلام' },
  { id: 'financial', label: 'المالية' },
  { id: 'documents', label: 'المستندات' },
  { id: 'timeline', label: 'الجدول الزمني' },
  { id: 'activity', label: 'النشاط' },
] as const;

type Tab = (typeof TABS)[number]['id'];

type Props = {
  id: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

export function PurchaseMaterialDrawer({ id, open, onOpenChange }: Props) {
  const [tab, setTab] = useState<Tab>('overview');
  const [rejectReason, setRejectReason] = useState('');
  const [showRejectInput, setShowRejectInput] = useState(false);

  const { data: material, isLoading, isError } = usePurchaseMaterialQuery(id ?? '');
  const submitMutation = useSubmitPurchaseMaterial();
  const approveMutation = useApprovePurchaseMaterial();
  const rejectMutation = useRejectPurchaseMaterial();
  const holdMutation = useHoldPurchaseMaterial();
  const cancelMutation = useCancelPurchaseMaterial();

  function handleClose() {
    setTab('overview');
    setRejectReason('');
    setShowRejectInput(false);
    onOpenChange(false);
  }

  async function handleAction(action: 'submit' | 'approve' | 'reject' | 'hold' | 'cancel') {
    if (!id) return;
    try {
      if (action === 'submit') {
        await submitMutation.mutateAsync(id);
        toast.success('تم إرسال الطلب للمراجعة.');
      } else if (action === 'approve') {
        await approveMutation.mutateAsync(id);
        toast.success('تم اعتماد الطلب.');
      } else if (action === 'reject') {
        await rejectMutation.mutateAsync({ id, reason: rejectReason || undefined });
        toast.success('تم رفض الطلب.');
        setShowRejectInput(false);
        setRejectReason('');
      } else if (action === 'hold') {
        await holdMutation.mutateAsync(id);
        toast.success('تم تعليق الطلب.');
      } else if (action === 'cancel') {
        await cancelMutation.mutateAsync(id);
        toast.success('تم إلغاء الطلب.');
      }
    } catch {
      toast.error('فشل تنفيذ الإجراء.');
    }
  }

  const isBusy =
    submitMutation.isPending ||
    approveMutation.isPending ||
    rejectMutation.isPending ||
    holdMutation.isPending ||
    cancelMutation.isPending;

  return (
    <Sheet open={open} onOpenChange={handleClose}>
      <SheetContent className="w-full sm:max-w-2xl flex flex-col gap-0 p-0">
        {/* Header */}
        <SheetHeader className="px-6 pt-6 pb-4 border-b shrink-0">
          <div className="flex items-center gap-3">
            {material ? (
              <>
                <div className="flex-1 min-w-0">
                  <SheetTitle className="text-base font-semibold">{material.request_number}</SheetTitle>
                  <SheetDescription className="text-xs mt-0.5">
                    {[material.warehouse?.name, material.company?.name].filter(Boolean).join(' · ')}
                  </SheetDescription>
                </div>
                <div className="flex items-center gap-2">
                  <PurchaseMaterialPriorityBadge priority={material.priority} />
                  <PurchaseMaterialStatusBadge status={material.status} />
                </div>
              </>
            ) : (
              <SheetTitle className="text-base font-semibold">طلب مشتريات</SheetTitle>
            )}
          </div>
        </SheetHeader>

        {isLoading && <LoadingState label="جارٍ تحميل الطلب…" />}
        {isError && <ErrorState description="فشل تحميل الطلب." />}

        {material && (
          <>
            {/* Action bar */}
            {(material.status === 'draft' ||
              material.status === 'under_review' ||
              material.status === 'waiting_supplier_selection') && (
              <div className="flex flex-wrap gap-2 px-6 py-3 border-b bg-muted/30 shrink-0">
                {material.status === 'draft' && (
                  <Button size="sm" disabled={isBusy} onClick={() => void handleAction('submit')}>
                    {submitMutation.isPending && <Loader2 className="size-3.5 animate-spin mr-1.5" />}
                    <Send className="size-3.5 mr-1.5" />
                    إرسال للمراجعة
                  </Button>
                )}
                {(material.status === 'under_review' || material.status === 'waiting_supplier_selection') && (
                  <>
                    <Button size="sm" disabled={isBusy} onClick={() => void handleAction('approve')}>
                      {approveMutation.isPending && <Loader2 className="size-3.5 animate-spin mr-1.5" />}
                      <CheckCircle className="size-3.5 mr-1.5" />
                      اعتماد
                    </Button>
                    <Button size="sm" variant="outline" disabled={isBusy} onClick={() => setShowRejectInput((v) => !v)}>
                      <XCircle className="size-3.5 mr-1.5" />
                      رفض
                    </Button>
                    <Button size="sm" variant="outline" disabled={isBusy} onClick={() => void handleAction('hold')}>
                      {holdMutation.isPending && <Loader2 className="size-3.5 animate-spin mr-1.5" />}
                      <PauseCircle className="size-3.5 mr-1.5" />
                      تعليق
                    </Button>
                  </>
                )}
                {(material.status === 'draft' || material.status === 'under_review') && (
                  <Button
                    size="sm"
                    variant="ghost"
                    className="text-destructive hover:text-destructive"
                    disabled={isBusy}
                    onClick={() => void handleAction('cancel')}
                  >
                    {cancelMutation.isPending && <Loader2 className="size-3.5 animate-spin mr-1.5" />}
                    إلغاء
                  </Button>
                )}
              </div>
            )}

            {/* Reject reason */}
            {showRejectInput && (
              <div className="flex gap-2 px-6 py-3 border-b bg-red-50/50 dark:bg-red-950/20 shrink-0">
                <input
                  className="flex-1 rounded-md border border-input bg-transparent px-3 py-1.5 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                  placeholder="سبب الرفض (اختياري)…"
                  value={rejectReason}
                  onChange={(e) => setRejectReason(e.target.value)}
                />
                <Button size="sm" variant="destructive" disabled={isBusy} onClick={() => void handleAction('reject')}>
                  {rejectMutation.isPending && <Loader2 className="size-3.5 animate-spin mr-1.5" />}
                  تأكيد الرفض
                </Button>
              </div>
            )}

            {/* Tab nav */}
            <div className="flex border-b overflow-x-auto shrink-0 px-2">
              {TABS.map(({ id: tid, label }) => (
                <button
                  key={tid}
                  onClick={() => setTab(tid)}
                  className={`px-3 py-2.5 text-xs whitespace-nowrap transition-colors border-b-2 -mb-px ${
                    tab === tid
                      ? 'border-primary text-primary font-medium'
                      : 'border-transparent text-muted-foreground hover:text-foreground'
                  }`}
                >
                  {tid === 'items' ? `البنود (${material.items_count})` : label}
                </button>
              ))}
            </div>

            {/* Tab content */}
            <div className="flex-1 overflow-y-auto px-6 py-4">
              {tab === 'overview' && <OverviewTab material={material} />}
              {tab === 'items' && <RequestedItemsTab material={material} />}
              {tab === 'demand' && <DemandAnalysisTab material={material} />}
              {tab === 'review' && <ProcurementReviewTab material={material} />}
              {tab === 'supplier' && <SupplierSelectionTab material={material} />}
              {tab === 'purchasing' && <PlaceholderTab label="تفاصيل الشراء" />}
              {tab === 'receipt' && <PlaceholderTab label="استلام البضاعة" />}
              {tab === 'financial' && <FinancialSummaryTab material={material} />}
              {tab === 'documents' && <PlaceholderTab label="المستندات" />}
              {tab === 'timeline' && <TimelineTab material={material} />}
              {tab === 'activity' && <PlaceholderTab label="سجل النشاط" />}
            </div>
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}
