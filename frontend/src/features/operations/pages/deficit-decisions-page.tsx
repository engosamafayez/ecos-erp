import { useCallback, useMemo, useState } from 'react';
import { AlertTriangle, CheckCircle2, Clock, Loader2, PackageX, RotateCcw, Waves } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import {
  useContinueDespiteShortage,
  useDeficitDecisions,
  usePostponeWaveOrder,
  useReturnOrderToPreparation,
} from '../hooks/use-preparation';
import { useSelectedWaveId } from '../components/wave-picker';
import { useToast } from '@/components/ds/use-toast';
import type {
  DeficitDecisionOrder,
  DeficitMaterial,
  DeficitPostponedOrder,
} from '../types/preparation';

function fmt(n: number | null | undefined) {
  if (n == null) return '—';
  return n.toLocaleString(undefined, { maximumFractionDigits: 3 });
}

function fmtMoney(n: number | null | undefined) {
  if (n == null) return '—';
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fmtDateTime(v: string | null | undefined) {
  if (!v) return '—';
  const d = new Date(v);
  return isNaN(d.getTime()) ? '—' : d.toLocaleString(undefined, {
    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  });
}

// ── Uncovered material summary ────────────────────────────────────────────────

/**
 * The materials that put orders in this queue. Quantities are NEVER summed across
 * materials — units differ — so the totals row aggregates counts only and every quantity
 * stays on its own material row.
 */
function MaterialSummary({ materials }: { materials: DeficitMaterial[] }) {
  const { t } = useTranslation('operations');

  return (
    <div className="rounded-lg border border-border/60 bg-card">
      <div className="flex items-center gap-2 border-b border-border/60 px-4 py-2.5">
        <PackageX className="h-4 w-4 text-amber-600" />
        <h2 className="text-sm font-semibold">{t($ => $.wave.deficitDecisions.summary.title)}</h2>
        <Badge variant="outline" className="text-[10px]">
          {t($ => $.wave.deficitDecisions.summary.materialsCount, { count: materials.length })}
        </Badge>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-xs">
          <thead className="text-muted-foreground border-b border-border/60">
            <tr>
              <th className="px-4 py-2 text-start font-medium">{t($ => $.wave.deficitDecisions.summary.material)}</th>
              <th className="px-3 py-2 text-end font-medium">{t($ => $.wave.deficitDecisions.summary.required)}</th>
              <th className="px-3 py-2 text-end font-medium">{t($ => $.wave.deficitDecisions.summary.available)}</th>
              <th className="px-3 py-2 text-end font-medium">{t($ => $.wave.deficitDecisions.summary.missing)}</th>
              <th className="px-3 py-2 text-end font-medium">{t($ => $.wave.deficitDecisions.summary.expected)}</th>
              <th className="px-3 py-2 text-end font-medium">{t($ => $.wave.deficitDecisions.summary.uncovered)}</th>
              <th className="px-3 py-2 text-end font-medium">{t($ => $.wave.deficitDecisions.summary.affectedOrders)}</th>
            </tr>
          </thead>
          <tbody>
            {materials.map((m) => (
              <tr key={m.material_id} className="border-b border-border/40 last:border-0">
                <td className="px-4 py-2">
                  <span className="font-medium">{m.material_name}</span>
                  {m.material_sku && (
                    <span className="text-muted-foreground ms-2 font-mono text-[10px]">{m.material_sku}</span>
                  )}
                </td>
                <td className="px-3 py-2 text-end tabular-nums">{fmt(m.required_qty)}</td>
                <td className="px-3 py-2 text-end tabular-nums">{fmt(m.available_qty)}</td>
                <td className="px-3 py-2 text-end tabular-nums font-semibold text-red-700 dark:text-red-400">{fmt(m.missing_qty)}</td>
                <td className="px-3 py-2 text-end tabular-nums text-sky-700 dark:text-sky-400">{fmt(m.expected_incoming_qty)}</td>
                <td className="px-3 py-2 text-end tabular-nums font-semibold text-amber-700 dark:text-amber-400">{fmt(m.uncovered_qty)}</td>
                <td className="px-3 py-2 text-end tabular-nums">{m.affected_orders_count}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export function DeficitDecisionsPage() {
  const { t } = useTranslation('operations');
  // Payment-method labels are NOT redefined here. The Orders namespace already owns the
  // canonical five-value catalogue, so this reuses it instead of adding another divergent map.
  // The key is dynamic, so the selector form cannot type it — same `tAny` cast the rest of
  // this feature uses for runtime-keyed lookups.
  const { t: tOrders } = useTranslation('orders');
  const tOrdersAny = tOrders as (key: string, opts?: Record<string, unknown>) => string;
  const paymentMethodLabel = useCallback(
    (slug: string | null) => (slug
      ? tOrdersAny(`workspace.paymentMethodLabels.${slug}`, { defaultValue: slug.replace(/_/g, ' ') })
      : '—'),
    [tOrdersAny],
  );
  const { toast } = useToast();

  const waveId = useSelectedWaveId();
  const { data, isLoading, isFetching, refetch } = useDeficitDecisions(waveId);
  const continueDecision = useContinueDespiteShortage();
  const postpone = usePostponeWaveOrder();
  const returnToPrep = useReturnOrderToPreparation();

  const [selected, setSelected] = useState<DeficitDecisionOrder | null>(null);
  const [materialFilter, setMaterialFilter] = useState<string>('all');

  const materials = data?.materials ?? [];
  const postponedOrders: DeficitPostponedOrder[] = data?.postponed_orders ?? [];

  const doReturn = (o: DeficitPostponedOrder) => {
    if (!waveId) return;
    returnToPrep.mutate(
      { waveId, orderId: o.order_id },
      {
        onSuccess: () => toast({ title: t($ => $.wave.deficitDecisions.returned) }),
        onError: () => toast({ title: t($ => $.wave.deficitDecisions.actionFailed), variant: 'destructive' }),
      },
    );
  };

  const orders = useMemo(() => {
    const all = data?.orders ?? [];
    return materialFilter === 'all'
      ? all
      : all.filter((o) => o.affected_materials.some((m) => m.material_id === materialFilter));
  }, [data?.orders, materialFilter]);

  /**
   * Continue is recorded PER PRODUCT by the existing endpoint, while this queue is per
   * order. An order is continued by recording the existing decision on each of its
   * affected products — no new endpoint, no new status, nothing deleted.
   */
  const doContinue = (row: DeficitDecisionOrder) => {
    if (!waveId) return;
    Promise.all(
      row.affected_products.map((p) =>
        continueDecision.mutateAsync({ waveId, productId: p.product_id }),
      ),
    )
      .then(() => {
        setSelected(null);
        toast({ title: t($ => $.wave.deficitDecisions.continued) });
      })
      .catch(() => toast({ title: t($ => $.wave.deficitDecisions.actionFailed), variant: 'destructive' }));
  };

  const doPostpone = (row: DeficitDecisionOrder) => {
    if (!waveId) return;
    postpone.mutate(
      { waveId, orderId: row.order_id },
      {
        onSuccess: () => { setSelected(null); toast({ title: t($ => $.wave.deficitDecisions.postponed) }); },
        onError: () => toast({ title: t($ => $.wave.deficitDecisions.actionFailed), variant: 'destructive' }),
      },
    );
  };

  const columns: DataGridColumnDef<DeficitDecisionOrder>[] = useMemo(() => [
    {
      key: 'order',
      label: t($ => $.wave.deficitDecisions.columns.order),
      alwaysVisible: true,
      cell: (r) => (
        <button type="button" onClick={() => setSelected(r)} className="text-start hover:text-primary transition-colors">
          <p className="font-medium underline-offset-2 hover:underline">{r.order_number}</p>
          <p className="text-muted-foreground text-xs">{r.customer_name ?? '—'}</p>
        </button>
      ),
    },
    {
      key: 'order_value',
      label: t($ => $.wave.deficitDecisions.columns.orderValue),
      align: 'end',
      cell: (r) => <span className="tabular-nums">{fmtMoney(r.order_value)}</span>,
    },
    {
      key: 'payment_method',
      label: t($ => $.wave.deficitDecisions.columns.paymentMethod),
      cell: (r) => <span className="text-xs">{paymentMethodLabel(r.payment_method)}</span>,
    },
    {
      key: 'products',
      label: t($ => $.wave.deficitDecisions.columns.products),
      cell: (r) => (
        <button type="button" onClick={() => setSelected(r)} className="text-xs text-muted-foreground hover:text-primary">
          {t($ => $.wave.deficitDecisions.columns.productsCount, { count: r.products_count })}
        </button>
      ),
    },
    {
      key: 'affected_products',
      label: t($ => $.wave.deficitDecisions.columns.affectedProducts),
      alwaysVisible: true,
      cell: (r) => (
        <div className="min-w-0">
          <p className="truncate text-xs font-medium">
            {r.affected_products.map((p) => p.product_name).join(' + ')}
          </p>
          <p className="text-muted-foreground text-[10px]">
            {t($ => $.wave.deficitDecisions.columns.affectedLines, { count: r.affected_lines_count })}
          </p>
        </div>
      ),
    },
    {
      key: 'shortage_impact_qty',
      label: t($ => $.wave.deficitDecisions.columns.shortageImpact),
      alwaysVisible: true,
      align: 'end',
      cell: (r) => (
        <span className="tabular-nums font-semibold text-amber-700 dark:text-amber-400">
          {fmt(r.shortage_impact_qty)}
        </span>
      ),
    },
    {
      key: 'status',
      label: t($ => $.wave.deficitDecisions.columns.orderState),
      cell: (r) => <span className="text-muted-foreground text-xs">{r.status}</span>,
    },
    {
      key: 'entry_at',
      label: t($ => $.wave.deficitDecisions.columns.entryAt),
      cell: (r) => <span className="text-muted-foreground text-xs">{fmtDateTime(r.entry_at)}</span>,
    },
    {
      key: 'last_updated_at',
      label: t($ => $.wave.deficitDecisions.columns.lastUpdated),
      cell: (r) => <span className="text-muted-foreground text-xs">{fmtDateTime(r.last_updated_at)}</span>,
    },
    {
      key: 'decision',
      label: t($ => $.wave.deficitDecisions.columns.decision),
      alwaysVisible: true,
      cell: (r) => (r.shortage_decision === 'continue' ? (
        <Badge variant="outline" className="gap-1 text-[10px]">
          <CheckCircle2 className="h-3 w-3" />
          {t($ => $.wave.deficitDecisions.decidedContinue)}
        </Badge>
      ) : (
        <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => setSelected(r)}>
          {t($ => $.wave.deficitDecisions.decide)}
        </Button>
      )),
    },
  ], [t, paymentMethodLabel]);

  if (!waveId) {
    return (
      <div className="flex flex-col items-center justify-center h-64 gap-3 text-muted-foreground">
        <Waves className="h-10 w-10 opacity-30" />
        <p className="text-sm font-medium">{t($ => $.wave.deficitDecisions.noWave)}</p>
      </div>
    );
  }

  const busy = continueDecision.isPending || postpone.isPending;

  return (
    <div className="flex h-full flex-col gap-4 overflow-auto p-4">
      <div>
        <h1 className="text-base font-semibold">{t($ => $.wave.deficitDecisions.title)}</h1>
        <p className="text-muted-foreground text-xs">{t($ => $.wave.deficitDecisions.subtitle)}</p>
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center h-40 gap-2 text-muted-foreground">
          <Loader2 className="h-4 w-4 animate-spin" />
          <span className="text-sm">{t($ => $.wave.loading)}</span>
        </div>
      ) : materials.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-10 gap-2 text-muted-foreground">
          <CheckCircle2 className="h-8 w-8 text-emerald-500" />
          <p className="text-sm font-medium">{t($ => $.wave.deficitDecisions.empty)}</p>
        </div>
      ) : (
        <>
          <MaterialSummary materials={materials} />

          {/* Filter by material — the summary above is the legend for this control. */}
          <div className="flex flex-wrap items-center gap-1.5">
            <span className="text-muted-foreground text-xs">
              {t($ => $.wave.deficitDecisions.filterByMaterial)}
            </span>
            <Button
              size="sm"
              variant={materialFilter === 'all' ? 'default' : 'outline'}
              className="h-7 text-xs"
              onClick={() => setMaterialFilter('all')}
            >
              {t($ => $.wave.deficitDecisions.allMaterials)}
            </Button>
            {materials.map((m) => (
              <Button
                key={m.material_id}
                size="sm"
                variant={materialFilter === m.material_id ? 'default' : 'outline'}
                className="h-7 text-xs"
                onClick={() => setMaterialFilter(m.material_id)}
              >
                {m.material_name}
              </Button>
            ))}
          </div>

          <SmartToolbar
            onRefresh={() => void refetch()}
            isFetching={isFetching}
            refreshLabel={t($ => $.wave.deficitDecisions.refresh)}
          />

          {orders.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-12 gap-2 text-muted-foreground">
              <AlertTriangle className="h-7 w-7 text-amber-500" />
              <p className="text-sm font-medium">{t($ => $.wave.deficitDecisions.noAffectedOrders)}</p>
            </div>
          ) : (
            <UniversalDataGrid<DeficitDecisionOrder>
              columns={columns}
              data={orders}
              rowId={(r) => r.order_id}
              loading={false}
              renderMobileCard={(r) => (
                // Decision-oriented card (§13): PROBLEM (affected products) → IMPACT
                // (shortage qty) → REQUIRED DECISION. The action reuses the SAME dialog and
                // the SAME canonical continue/postpone endpoints as the desktop row.
                <div role="listitem" className="border-b p-3.5 last:border-0 space-y-2">
                  <div className="flex items-start justify-between gap-2">
                    <button type="button" onClick={() => setSelected(r)} className="min-w-0 text-start">
                      <p className="text-sm font-medium truncate">{r.order_number}</p>
                      <p className="text-xs text-muted-foreground truncate">{r.customer_name ?? '—'}</p>
                    </button>
                    <span className="shrink-0 text-sm tabular-nums">{fmtMoney(r.order_value)}</span>
                  </div>

                  <div className="rounded-md bg-amber-50/60 dark:bg-amber-950/20 px-2.5 py-1.5">
                    <p className="text-[10px] uppercase tracking-wide text-amber-700 dark:text-amber-400">
                      {t($ => $.wave.deficitDecisions.columns.affectedProducts)}
                    </p>
                    <p className="text-xs font-medium truncate">
                      {r.affected_products.map((p) => p.product_name).join(' + ')}
                    </p>
                    <p className="text-[10px] text-muted-foreground">
                      {t($ => $.wave.deficitDecisions.columns.affectedLines, { count: r.affected_lines_count })}
                      {' · '}
                      {paymentMethodLabel(r.payment_method)}
                    </p>
                  </div>

                  <div className="flex items-center justify-between gap-2">
                    <span className="text-[11px] text-muted-foreground">
                      {t($ => $.wave.deficitDecisions.columns.shortageImpact)}
                    </span>
                    <span className="tabular-nums font-semibold text-amber-700 dark:text-amber-400">
                      {fmt(r.shortage_impact_qty)}
                    </span>
                  </div>

                  <div className="flex justify-end pt-0.5">
                    {r.shortage_decision === 'continue' ? (
                      <Badge variant="outline" className="gap-1 text-[10px]">
                        <CheckCircle2 className="h-3 w-3" />
                        {t($ => $.wave.deficitDecisions.decidedContinue)}
                      </Badge>
                    ) : (
                      <Button size="sm" variant="outline" className="h-8 text-xs" onClick={() => setSelected(r)}>
                        {t($ => $.wave.deficitDecisions.decide)}
                      </Button>
                    )}
                  </div>
                </div>
              )}
            />
          )}
        </>
      )}

      {/* ── Postponed orders — the Return surface ─────────────────────────────────
          A postponed order carries no demand and no uncovered figure, so it is NOT part of
          the decision queue above. It is listed separately purely so a return can be offered
          once the material that caused the postponement is available again. `can_return` is
          decided by the backend, so no button is ever shown that the write path would refuse.
      */}
      {postponedOrders.length > 0 && (
        <div className="rounded-lg border border-border/60 bg-card">
          <div className="flex items-center gap-2 border-b border-border/60 px-4 py-2.5">
            <Clock className="h-4 w-4 text-muted-foreground" />
            <h2 className="text-sm font-semibold">{t($ => $.wave.deficitDecisions.postponedSection.title)}</h2>
            <Badge variant="outline" className="text-[10px]">{postponedOrders.length}</Badge>
          </div>
          <div className="flex flex-col divide-y divide-border/40">
            {postponedOrders.map((o) => (
              <div key={o.order_id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5">
                <div className="min-w-0">
                  <p className="text-sm font-medium">{o.order_number}</p>
                  <p className="text-muted-foreground text-xs">
                    {o.customer_name ?? '—'} · {fmtMoney(o.order_value)} · {paymentMethodLabel(o.payment_method)}
                  </p>
                </div>
                {o.can_return ? (
                  <Button
                    size="sm"
                    className="h-7 text-xs"
                    disabled={returnToPrep.isPending}
                    onClick={() => doReturn(o)}
                  >
                    {returnToPrep.isPending
                      ? <Loader2 className="me-1 h-3.5 w-3.5 animate-spin" />
                      : <RotateCcw className="me-1 h-3.5 w-3.5" />}
                    {t($ => $.wave.deficitDecisions.returnToPreparation)}
                  </Button>
                ) : (
                  <span className="text-muted-foreground max-w-md text-end text-xs">
                    {o.return_blocked_reason ?? t($ => $.wave.deficitDecisions.returnNotAvailable)}
                  </span>
                )}
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ── Decision dialog — the existing surface, extended with the shortage detail ── */}
      <Dialog open={selected !== null} onOpenChange={(open) => { if (!open) setSelected(null); }}>
        <DialogContent className="max-w-2xl">
          {selected && (
            <>
              <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                  {selected.order_number}
                  <span className="text-muted-foreground text-sm font-normal">{selected.customer_name ?? '—'}</span>
                </DialogTitle>
                <DialogDescription>{t($ => $.wave.deficitDecisions.decisionPrompt)}</DialogDescription>
              </DialogHeader>

              <div className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <Row label={t($ => $.wave.deficitDecisions.columns.orderValue)} value={fmtMoney(selected.order_value)} />
                <Row label={t($ => $.wave.deficitDecisions.columns.paymentMethod)} value={paymentMethodLabel(selected.payment_method)} />
                <Row label={t($ => $.wave.deficitDecisions.columns.orderState)} value={selected.status} />
                <Row label={t($ => $.wave.deficitDecisions.columns.entryAt)} value={fmtDateTime(selected.entry_at)} />
                <Row label={t($ => $.wave.deficitDecisions.columns.lastUpdated)} value={fmtDateTime(selected.last_updated_at)} />
                <Row label={t($ => $.wave.deficitDecisions.columns.shortageImpact)} value={fmt(selected.shortage_impact_qty)} />
              </div>

              <div>
                <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  {t($ => $.wave.deficitDecisions.orderProducts)}
                </p>
                <div className="flex flex-col gap-1">
                  {selected.products.map((p) => {
                    const affected = selected.affected_products.some((a) => a.product_id === p.product_id);
                    return (
                      <div key={`${p.product_id}-${p.quantity}`} className="flex items-center justify-between text-xs">
                        <span className={affected ? 'font-medium text-amber-700 dark:text-amber-400' : ''}>
                          {p.product_name}
                          {affected && (
                            <Badge variant="outline" className="ms-2 text-[10px]">
                              {t($ => $.wave.deficitDecisions.affectedBadge)}
                            </Badge>
                          )}
                        </span>
                        <span className="tabular-nums text-muted-foreground">{fmt(p.quantity)}</span>
                      </div>
                    );
                  })}
                </div>
              </div>

              <div>
                <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  {t($ => $.wave.deficitDecisions.shortageDetail)}
                </p>
                <div className="flex flex-col gap-1">
                  {selected.affected_materials.map((m) => (
                    <div key={m.material_id} className="flex items-center justify-between text-xs">
                      <span>{m.material_name}</span>
                      <span className="tabular-nums text-amber-700 dark:text-amber-400">
                        {t($ => $.wave.deficitDecisions.impactOnOrder)}: {fmt(m.impact_qty)}
                      </span>
                    </div>
                  ))}
                </div>
              </div>

              <div className="flex flex-wrap justify-end gap-2 pt-2">
                <Button variant="outline" disabled={busy} onClick={() => doPostpone(selected)}>
                  <Clock className="me-1 h-3.5 w-3.5" />
                  {t($ => $.wave.deficitDecisions.postponeOrder)}
                </Button>
                <Button disabled={busy} onClick={() => doContinue(selected)}>
                  {busy ? <Loader2 className="me-1 h-3.5 w-3.5 animate-spin" /> : <CheckCircle2 className="me-1 h-3.5 w-3.5" />}
                  {t($ => $.wave.deficitDecisions.continueDespiteShortage)}
                </Button>
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <span className="text-muted-foreground text-xs">{label}</span>
      <span className="text-xs font-medium">{value}</span>
    </div>
  );
}
