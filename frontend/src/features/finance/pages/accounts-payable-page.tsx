import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router-dom';
import { AlertTriangle, Coins, Users, Wallet } from 'lucide-react';

import { UniversalDataGrid, type DataGridColumnDef } from '@/components/data-grid';
import { Card, CardContent } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { WorkspacePage } from '@/components/page';
import { WorkspaceHeader, type WorkspaceMetric } from '@/components/workspace';
import { usePermission } from '@/features/authorization';
import { useFormatter } from '@/hooks/use-formatter';

import { BillStatusBadge, PaymentStatusBadge, SupplierRef } from '../components/ap-badges';
import { BillDetailDrawer } from '../components/bill-detail-drawer';
import { PaymentDetailDrawer } from '../components/payment-detail-drawer';
import { SupplierLedgerDrawer } from '../components/supplier-ledger-drawer';
import { useApAging, useApBills, useApPayments } from '../hooks/use-finance-ap';
import { AP_AGING_BUCKETS, type ApAgingSupplierRow, type ApBill, type ApPayment } from '../types/finance-ap';

/**
 * EPIC-FINANCE-UI-001 · Phase 5 — Accounts Payable, extended by
 * TASK-ECOS-FINANCE-AP-AR-MUTATION-UX with the Payments tab's detail drawer
 * (allocate / auto-allocate / reverse posting — see PaymentDetailDrawer).
 * Consumes the certified AP endpoints (aging, bills, payments, supplier ledger, allocation).
 * Values are shown exactly as returned — never recalculated in the browser. The AP API
 * exposes only `supplier_id` (no name); ids are shown verbatim (see the report's Finance ↔
 * vendor boundary). IAM-gated by finance.ap.view (the drawers' own write actions are
 * separately gated: finance.allocation.manage / finance.journal.post on payments,
 * finance.ap.advance.apply on the explicit, single-bill "Apply Supplier Advance" action —
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-FINAL-IMPLEMENTATION-CLOSURE-002 — added to the
 * Bills tab's own detail drawer, never an automatic sweep); EN/AR; responsive.
 *
 * TASK-ECOS-CUSTOMER-SUPPLIER-LEDGER-LINKS-CLOSURE-001 — also the destination for the
 * Supplier 360 "Account Statement" action: an optional `?supplier_id=<uuid>` query
 * param opens SupplierLedgerDrawer directly for that supplier on mount, reusing this
 * exact page/permission gate/drawer rather than adding a second statement surface.
 */
export function AccountsPayablePage() {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const { can } = usePermission();

  const aging = useApAging();
  const [ledgerSupplier, setLedgerSupplier] = useState<string | null>(null);
  const [ledgerOpen, setLedgerOpen] = useState(false);
  // Hoisted to the page (not left inside PaymentsTab, which lives inside a
  // TabsContent that Radix unmounts when its tab isn't active) so the drawer
  // survives a tab switch — the same reason SupplierLedgerDrawer sits here.
  const [detailId, setDetailId] = useState<string | null>(null);
  const [detailOpen, setDetailOpen] = useState(false);
  // TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-FINAL-IMPLEMENTATION-CLOSURE-002 —
  // the Bills tab's own detail drawer (hosts the explicit "Apply Supplier Advance"
  // action). Hoisted for the same reason as detailId/detailOpen above.
  const [billDetailId, setBillDetailId] = useState<string | null>(null);
  const [billDetailOpen, setBillDetailOpen] = useState(false);

  const openLedger = (supplierId: string) => { setLedgerSupplier(supplierId); setLedgerOpen(true); };
  const openDetail = (id: string) => { setDetailId(id); setDetailOpen(true); };
  const openBillDetail = (id: string) => { setBillDetailId(id); setBillDetailOpen(true); };

  // TASK-ECOS-CUSTOMER-SUPPLIER-LEDGER-LINKS-CLOSURE-001 — deep-link entry point from
  // Supplier 360 ("Account Statement"): ?supplier_id=<uuid> opens this supplier's
  // existing ledger drawer directly, without requiring the Aging tab drill-down.
  const [searchParams] = useSearchParams();
  const supplierIdParam = searchParams.get('supplier_id');
  useEffect(() => {
    // Syncing UI state FROM the external URL search param on navigation — the canonical
    // exception this rule itself documents, not an internal render-driven update.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    if (supplierIdParam) openLedger(supplierIdParam);
  }, [supplierIdParam]);

  const metrics = useMemo<WorkspaceMetric[]>(() => {
    const totals = aging.data?.totals;
    return [
      { id: 'total', icon: Wallet, label: t(($) => $.ap.kpi.totalOutstanding), value: fmt.money(totals?.total), isLoading: aging.isLoading },
      { id: 'current', icon: Coins, label: t(($) => $.ap.kpi.current), value: fmt.money(totals?.current), isLoading: aging.isLoading },
      { id: 'over90', icon: AlertTriangle, label: t(($) => $.ap.kpi.over90), value: fmt.money(totals?.['90_plus']), isLoading: aging.isLoading, colorClass: 'text-red-600' },
      { id: 'suppliers', icon: Users, label: t(($) => $.ap.kpi.suppliers), value: aging.data?.suppliers.length ?? 0, isLoading: aging.isLoading },
    ];
  }, [aging.data, aging.isLoading, fmt, t]);

  if (!can('finance.ap.view')) {
    return (
      <>
        <WorkspaceHeader breadcrumbs={[{ label: t(($) => $.breadcrumb.finance) }, { label: t(($) => $.ap.title) }]} title={t(($) => $.ap.title)} />
        <WorkspacePage><NoAccess /></WorkspacePage>
      </>
    );
  }

  return (
    <>
      <WorkspaceHeader
        breadcrumbs={[{ label: t(($) => $.breadcrumb.finance) }, { label: t(($) => $.ap.title) }]}
        title={t(($) => $.ap.title)}
        description={t(($) => $.ap.subtitle)}
        metrics={metrics}
      />
      <WorkspacePage>
        <Tabs defaultValue="aging">
          <TabsList>
            <TabsTrigger value="aging">{t(($) => $.ap.tab.aging)}</TabsTrigger>
            <TabsTrigger value="bills">{t(($) => $.ap.tab.bills)}</TabsTrigger>
            <TabsTrigger value="payments">{t(($) => $.ap.tab.payments)}</TabsTrigger>
          </TabsList>

          <TabsContent value="aging" className="mt-4">
            <AgingTab onDrill={openLedger} />
          </TabsContent>
          <TabsContent value="bills" className="mt-4">
            <BillsTab onOpenDetail={openBillDetail} />
          </TabsContent>
          <TabsContent value="payments" className="mt-4">
            <PaymentsTab onOpenDetail={openDetail} />
          </TabsContent>
        </Tabs>
      </WorkspacePage>

      <SupplierLedgerDrawer supplierId={ledgerSupplier} open={ledgerOpen} onOpenChange={setLedgerOpen} />
      <PaymentDetailDrawer paymentId={detailId} open={detailOpen} onOpenChange={setDetailOpen} />
      <BillDetailDrawer billId={billDetailId} open={billDetailOpen} onOpenChange={setBillDetailOpen} />
    </>
  );
}

// ── Aging (AP supplier balance view) ──────────────────────────────────────────

function AgingTab({ onDrill }: { onDrill: (supplierId: string) => void }) {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const aging = useApAging();

  const columns = useMemo<DataGridColumnDef<ApAgingSupplierRow>[]>(() => [
    { key: 'supplier_id', label: t(($) => $.ap.aging.supplier), pin: 'left', cell: (r) => <SupplierRef id={r.supplier_id} /> },
    ...AP_AGING_BUCKETS.map((b): DataGridColumnDef<ApAgingSupplierRow> => ({
      key: b, label: t(($) => $.ap.bucket[b]), align: 'end',
      cell: (r) => <span className="tabular-nums">{r[b] ? fmt.money(r[b]) : '—'}</span>,
    })),
    { key: 'total', label: t(($) => $.ap.aging.total), align: 'end', cell: (r) => <span className="tabular-nums font-medium">{fmt.money(r.total)}</span> },
  ], [t, fmt]);

  return (
    <div className="space-y-3">
      <p className="text-xs text-muted-foreground">{t(($) => $.ap.supplierNote)}</p>

      <UniversalDataGrid
        data={aging.data?.suppliers ?? []}
        columns={columns}
        rowId={(r) => r.supplier_id}
        loading={aging.isLoading}
        error={aging.isError}
        onRowClick={(r) => onDrill(r.supplier_id)}
        emptyState={<p className="py-10 text-center text-sm text-muted-foreground">{t(($) => $.empty)}</p>}
      />

      {aging.data && (
        <div className="flex flex-wrap items-center justify-end gap-5 rounded-lg border bg-muted/30 px-4 py-3 text-sm">
          {AP_AGING_BUCKETS.map((b) => (
            <span key={b}><span className="text-muted-foreground">{t(($) => $.ap.bucket[b])}: </span><span className="tabular-nums font-medium">{fmt.money(aging.data.totals[b])}</span></span>
          ))}
          <span><span className="text-muted-foreground">{t(($) => $.ap.aging.total)}: </span><span className="tabular-nums font-semibold">{fmt.money(aging.data.totals.total)}</span></span>
        </div>
      )}
    </div>
  );
}

// ── Bills (with the backend `outstanding` figure; row opens the detail drawer,
//    which hosts the explicit "Apply Supplier Advance" action) ────────────────

function BillsTab({ onOpenDetail }: { onOpenDetail: (id: string) => void }) {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const bills = useApBills();

  const columns = useMemo<DataGridColumnDef<ApBill>[]>(() => [
    { key: 'number', label: t(($) => $.ap.bill.number), pin: 'left', cell: (b) => <span className="font-medium">{b.number}</span> },
    { key: 'supplier_id', label: t(($) => $.ap.bill.supplier), cell: (b) => <SupplierRef id={b.supplier_id} /> },
    { key: 'document_type', label: t(($) => $.ap.bill.type), cell: (b) => t(($) => $.ap.docType[b.document_type]) },
    { key: 'bill_date', label: t(($) => $.ap.bill.billDate), cell: (b) => fmt.date(b.bill_date) },
    { key: 'due_date', label: t(($) => $.ap.bill.dueDate), cell: (b) => fmt.date(b.due_date) },
    { key: 'total', label: t(($) => $.ap.bill.total), align: 'end', cell: (b) => <span className="tabular-nums">{fmt.money(b.total)}</span> },
    { key: 'outstanding', label: t(($) => $.ap.bill.outstanding), align: 'end', cell: (b) => <span className="tabular-nums font-medium">{b.outstanding == null ? '—' : fmt.money(b.outstanding)}</span> },
    { key: 'status', label: t(($) => $.ap.bill.status), cell: (b) => <BillStatusBadge status={b.status} /> },
  ], [t, fmt]);

  return (
    <UniversalDataGrid
      data={bills.data ?? []}
      columns={columns}
      rowId={(b) => b.id}
      loading={bills.isLoading}
      error={bills.isError}
      onRowClick={(b) => onOpenDetail(b.id)}
      emptyState={<p className="py-10 text-center text-sm text-muted-foreground">{t(($) => $.ap.bill.empty)}</p>}
    />
  );
}

// ── Payments (maker/checker status shown; row opens the detail/mutation drawer) ─

function PaymentsTab({ onOpenDetail }: { onOpenDetail: (id: string) => void }) {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const payments = useApPayments();

  const columns = useMemo<DataGridColumnDef<ApPayment>[]>(() => [
    { key: 'number', label: t(($) => $.ap.payment.number), pin: 'left', cell: (p) => <span className="font-medium">{p.number}</span> },
    { key: 'supplier_id', label: t(($) => $.ap.payment.supplier), cell: (p) => <SupplierRef id={p.supplier_id} /> },
    { key: 'payment_date', label: t(($) => $.ap.payment.date), cell: (p) => fmt.date(p.payment_date) },
    { key: 'amount', label: t(($) => $.ap.payment.amount), align: 'end', cell: (p) => <span className="tabular-nums">{fmt.money(p.amount)}</span> },
    { key: 'unallocated', label: t(($) => $.ap.payment.unallocated), align: 'end', cell: (p) => <span className="tabular-nums font-medium">{p.unallocated == null ? '—' : fmt.money(p.unallocated)}</span> },
    { key: 'status', label: t(($) => $.ap.payment.status), cell: (p) => <PaymentStatusBadge status={p.status} /> },
  ], [t, fmt]);

  return (
    <UniversalDataGrid
      data={payments.data ?? []}
      columns={columns}
      rowId={(p) => p.id}
      loading={payments.isLoading}
      error={payments.isError}
      onRowClick={(p) => onOpenDetail(p.id)}
      emptyState={<p className="py-10 text-center text-sm text-muted-foreground">{t(($) => $.ap.payment.empty)}</p>}
    />
  );
}

function NoAccess() {
  const { t } = useTranslation('finance');
  return (
    <Card>
      <CardContent className="py-10 text-center text-sm text-muted-foreground">{t(($) => $.gl.statements.noAccess)}</CardContent>
    </Card>
  );
}

export default AccountsPayablePage;
