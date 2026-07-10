import { useParams, useNavigate, Link } from 'react-router-dom';
import {
  ArrowLeft,
  CheckCircle2,
  ChevronRight,
  Loader2,
  Package,
  PackageCheck,
  ShoppingCart,
  Snowflake,
  TriangleAlert,
  Waves,
} from 'lucide-react';

import { Badge }    from '@/components/ui/badge';
import { Button }   from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import {
  usePreparationSession,
  useSessionOrders,
  useSessionProducts,
} from '../hooks/use-preparation';
import type { SessionStatus } from '../types/preparation';
import { ROUTES } from '@/router/routes';

const STATUS_BADGE: Record<SessionStatus, { label: string; className: string }> = {
  draft:       { label: 'Scheduled',        className: 'bg-sky-100 text-sky-700' },
  planning:    { label: 'Planning',          className: 'bg-blue-100 text-blue-700' },
  in_progress: { label: 'Preparing',         className: 'bg-purple-100 text-purple-700' },
  paused:      { label: 'Paused',            className: 'bg-amber-100 text-amber-700' },
  frozen:      { label: 'Frozen',            className: 'bg-cyan-100 text-cyan-700' },
  completed:   { label: 'Ready for Loading', className: 'bg-green-100 text-green-700' },
  approved:    { label: 'Approved',          className: 'bg-emerald-100 text-emerald-700' },
  closed:      { label: 'Closed',            className: 'bg-slate-100 text-slate-700' },
  cancelled:   { label: 'Cancelled',         className: 'bg-red-100 text-red-700' },
};

export function PreparationSessionDetailPage() {
  const { id }   = useParams<{ id: string }>();
  const navigate = useNavigate();

  const { data: session,  isLoading: sessionLoading } = usePreparationSession(id ?? null);
  const { data: orders,   isLoading: ordersLoading  } = useSessionOrders(id ?? null, { per_page: 50 });
  const { data: products }                             = useSessionProducts(id ?? null);

  if (sessionLoading) {
    return (
      <div className="flex items-center justify-center h-64 gap-2 text-muted-foreground">
        <Loader2 className="h-4 w-4 animate-spin" />
        <span className="text-sm">Opening preparation workspace…</span>
      </div>
    );
  }

  if (!session) {
    return (
      <div className="flex flex-col items-center justify-center h-64 gap-3">
        <p className="text-sm text-muted-foreground">Preparation workspace not found.</p>
        <Button variant="outline" size="sm" onClick={() => navigate(-1)}>
          Go Back
        </Button>
      </div>
    );
  }

  const badge     = STATUS_BADGE[session.status];
  const prepPct   = session.completion_pct ?? 0;
  const remaining = Math.max(0, session.total_units_required - session.total_units_prepared);

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="px-6 pt-4 pb-4 border-b border-border/60">
        {/* Breadcrumb */}
        <nav className="flex items-center gap-1 text-xs text-muted-foreground mb-3">
          <Link to={ROUTES.preparationToday} className="hover:text-foreground transition-colors">
            Operations
          </Link>
          <ChevronRight className="h-3 w-3 shrink-0" />
          <Link to={ROUTES.preparationToday} className="hover:text-foreground transition-colors">
            Today's Preparation
          </Link>
          <ChevronRight className="h-3 w-3 shrink-0" />
          <span className="text-foreground font-medium">Preparation Workspace</span>
        </nav>

        {/* Title row */}
        <div className="flex items-center gap-3">
          <Button
            variant="ghost"
            size="icon"
            onClick={() => navigate(ROUTES.preparationToday)}
            className="h-8 w-8 shrink-0"
          >
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2 flex-wrap">
              <h1 className="text-lg font-semibold">Preparation Workspace</h1>
              <Badge className={`text-xs font-medium ${badge.className}`}>{badge.label}</Badge>
              {session.auto_created && (
                <span className="text-xs text-sky-600 font-medium">Auto</span>
              )}
            </div>
            <p className="text-xs text-muted-foreground mt-0.5">
              {session.session_number}
              {' · '}
              {new Date(session.planning_date).toLocaleDateString(undefined, {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
              })}
            </p>
          </div>
        </div>
      </div>

      {/* Content */}
      <div className="flex-1 overflow-auto p-6 space-y-6">

        {/* KPI Row */}
        <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
          <KpiCard icon={<ShoppingCart  className="h-4 w-4" />} label="Orders"        value={session.orders_count} />
          <KpiCard icon={<Package       className="h-4 w-4" />} label="Products"      value={session.products_count} />
          <KpiCard icon={<CheckCircle2  className="h-4 w-4" />} label="Prepared"      value={session.total_units_prepared} />
          <KpiCard icon={<TriangleAlert className="h-4 w-4" />} label="Remaining"     value={remaining} />
          <KpiCard icon={<Waves         className="h-4 w-4" />} label="Waves"         value={session.waves_count} />
        </div>

        {/* Preparation Progress */}
        <div>
          <div className="flex items-center justify-between mb-2">
            <span className="text-sm font-medium text-muted-foreground">Preparation Progress</span>
            <span className="text-sm text-muted-foreground tabular-nums">{prepPct.toFixed(0)}%</span>
          </div>
          <Progress value={prepPct} className="h-2" />
        </div>

        {/* Freeze status banner — shown when frozen */}
        {session.status === 'frozen' && (
          <div className="flex items-start gap-3 rounded-lg bg-cyan-50 border border-cyan-200 px-4 py-3">
            <Snowflake className="h-4 w-4 text-cyan-600 shrink-0 mt-0.5" />
            <div>
              <p className="text-sm font-medium text-cyan-800">Preparation Frozen</p>
              <p className="text-xs text-cyan-600 mt-0.5">
                New orders will automatically move to the next preparation cycle.
              </p>
            </div>
          </div>
        )}

        {/* Ready for loading banner */}
        {(session.status === 'completed' || session.status === 'approved') && (
          <div className="flex items-start gap-3 rounded-lg bg-green-50 border border-green-200 px-4 py-3">
            <PackageCheck className="h-4 w-4 text-green-600 shrink-0 mt-0.5" />
            <div>
              <p className="text-sm font-medium text-green-800">Ready for Loading</p>
              <p className="text-xs text-green-600 mt-0.5">
                {session.total_units_prepared} units prepared and ready for Loading &amp; Allocation.
              </p>
            </div>
          </div>
        )}

        {/* Product Queue */}
        {products && products.length > 0 && (
          <section>
            <h2 className="text-sm font-semibold mb-3 flex items-center gap-2">
              <Package className="h-4 w-4 text-muted-foreground" />
              Product Queue
              <Badge variant="outline" className="text-xs">{products.length}</Badge>
            </h2>
            <div className="rounded-lg border border-border/60 overflow-hidden">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border/60 bg-muted/40">
                    <th className="px-4 py-2.5 text-left font-medium text-muted-foreground">Product</th>
                    <th className="px-4 py-2.5 text-left font-medium text-muted-foreground">SKU</th>
                    <th className="px-4 py-2.5 text-right font-medium text-muted-foreground">Qty Needed</th>
                    <th className="px-4 py-2.5 text-right font-medium text-muted-foreground">Orders</th>
                  </tr>
                </thead>
                <tbody>
                  {products.map((p) => (
                    <tr key={p.product_id} className="border-b border-border/40 last:border-0 hover:bg-muted/20">
                      <td className="px-4 py-2.5 font-medium">{p.product_name}</td>
                      <td className="px-4 py-2.5 text-muted-foreground font-mono text-xs">{p.sku}</td>
                      <td className="px-4 py-2.5 text-right tabular-nums">
                        {p.total_quantity_needed}
                        {p.unit && <span className="ml-1 text-muted-foreground">{p.unit}</span>}
                      </td>
                      <td className="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{p.orders_count}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}

        {/* Attached Orders */}
        <section>
          <h2 className="text-sm font-semibold mb-3 flex items-center gap-2">
            <ShoppingCart className="h-4 w-4 text-muted-foreground" />
            Attached Orders
            {orders && (
              <Badge variant="outline" className="text-xs">{orders.data.length}</Badge>
            )}
          </h2>
          {ordersLoading ? (
            <div className="flex items-center gap-2 text-muted-foreground text-sm py-4">
              <Loader2 className="h-3.5 w-3.5 animate-spin" />
              Loading orders…
            </div>
          ) : !orders || orders.data.length === 0 ? (
            <div className="text-sm text-muted-foreground py-4">
              No orders attached to this preparation.
            </div>
          ) : (
            <div className="rounded-lg border border-border/60 overflow-hidden">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border/60 bg-muted/40">
                    <th className="px-4 py-2.5 text-left font-medium text-muted-foreground">Order #</th>
                    <th className="px-4 py-2.5 text-left font-medium text-muted-foreground">Customer</th>
                    <th className="px-4 py-2.5 text-left font-medium text-muted-foreground">Area</th>
                    <th className="px-4 py-2.5 text-left font-medium text-muted-foreground">Source</th>
                    <th className="px-4 py-2.5 text-left font-medium text-muted-foreground">Attached</th>
                  </tr>
                </thead>
                <tbody>
                  {orders.data.map((o) => (
                    <tr key={o.id} className="border-b border-border/40 last:border-0 hover:bg-muted/20">
                      <td className="px-4 py-2.5 font-mono text-xs font-medium">{o.order_number}</td>
                      <td className="px-4 py-2.5">{o.customer_name ?? <span className="text-muted-foreground">—</span>}</td>
                      <td className="px-4 py-2.5 text-muted-foreground">
                        {[o.area, o.governorate].filter(Boolean).join(', ') || '—'}
                      </td>
                      <td className="px-4 py-2.5">
                        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium ${
                          o.attachment_source === 'auto'
                            ? 'bg-sky-100 text-sky-700'
                            : o.attachment_source === 'manual_supervisor'
                            ? 'bg-violet-100 text-violet-700'
                            : 'bg-gray-100 text-gray-600'
                        }`}>
                          {o.attachment_source === 'auto' ? 'Auto' : o.attachment_source === 'manual_supervisor' ? 'Manual' : 'System'}
                        </span>
                      </td>
                      <td className="px-4 py-2.5 text-muted-foreground text-xs">
                        {new Date(o.attached_at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>
    </div>
  );
}

function KpiCard({ icon, label, value }: { icon: React.ReactNode; label: string; value: number }) {
  return (
    <div className="rounded-lg border border-border/60 bg-card p-3 flex items-center gap-3">
      <span className="text-muted-foreground shrink-0">{icon}</span>
      <div>
        <div className="text-xl font-bold tabular-nums leading-none">{value}</div>
        <div className="text-[11px] text-muted-foreground mt-0.5">{label}</div>
      </div>
    </div>
  );
}
