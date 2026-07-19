import {
  AlertTriangle,
  CheckCircle2,
  ExternalLink,
  Factory,
  Package,
  RotateCcw,
  ShoppingCart,
  Truck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

// ── Types ──────────────────────────────────────────────────────────────────

type EventType = 'order' | 'stock' | 'production' | 'purchase' | 'shipment' | 'return' | 'alert';

interface ActivityEvent {
  id:       string;
  type:     EventType;
  title:    string;
  detail:   string;
  time:     string;
  value?:   string;
  urgent?:  boolean;
}

// ── Static demo data ───────────────────────────────────────────────────────
// Replace with a real API call when the endpoint is available.

const EVENTS: ActivityEvent[] = [
  { id: '1',  type: 'order',      title: 'Order #1001 placed',            detail: 'Ahmed Ali · WooCommerce',       time: '2m ago',  value: 'EGP 1,240' },
  { id: '2',  type: 'stock',      title: 'Low stock: SKU-GH-001',         detail: 'Warehouse Cairo · Qty 3 left',  time: '8m ago',  urgent: true },
  { id: '3',  type: 'shipment',   title: 'Trip TRP-44 delivered',         detail: 'Driver: Mohamed Hassan · Giza', time: '15m ago' },
  { id: '4',  type: 'production', title: 'Wave WV-089 completed',         detail: 'Prep OS · 120 units ready',     time: '22m ago' },
  { id: '5',  type: 'purchase',   title: 'PO-012 received',               detail: 'Supplier: Al-Nour Trading',     time: '1h ago',  value: 'EGP 18,500' },
  { id: '6',  type: 'return',     title: 'Return RMA-221 filed',          detail: 'Order #998 · Damaged item',     time: '2h ago',  urgent: true },
  { id: '7',  type: 'alert',      title: 'Inventory count variance',      detail: 'Warehouse B · 3 SKUs affected', time: '3h ago',  urgent: true },
  { id: '8',  type: 'order',      title: 'Order #1000 shipped',           detail: 'Sara Mohamed · Manual order',   time: '3h ago',  value: 'EGP 680' },
];

// ── Visual config ──────────────────────────────────────────────────────────

const EVENT_CONFIG: Record<EventType, {
  icon:   LucideIcon;
  dot:    string;
  iconCl: string;
}> = {
  order:      { icon: ShoppingCart,  dot: 'bg-indigo-500',  iconCl: 'text-indigo-500' },
  stock:      { icon: Package,       dot: 'bg-amber-500',   iconCl: 'text-amber-500' },
  production: { icon: Factory,       dot: 'bg-violet-500',  iconCl: 'text-violet-500' },
  purchase:   { icon: CheckCircle2,  dot: 'bg-emerald-500', iconCl: 'text-emerald-500' },
  shipment:   { icon: Truck,         dot: 'bg-cyan-500',    iconCl: 'text-cyan-500' },
  return:     { icon: RotateCcw,     dot: 'bg-rose-500',    iconCl: 'text-rose-500' },
  alert:      { icon: AlertTriangle, dot: 'bg-amber-400',   iconCl: 'text-amber-500' },
};

// ── Component ──────────────────────────────────────────────────────────────

export function DashboardActivityTimeline() {
  return (
    <div className="relative">
      {/* Vertical connector line */}
      <div className="absolute bottom-2 left-[11px] top-2 w-px bg-border/60" />

      <div className="space-y-0">
        {EVENTS.map((ev) => {
          const cfg = EVENT_CONFIG[ev.type];

          return (
            <div
              key={ev.id}
              className={cn(
                'group relative flex gap-4 py-2.5 ps-1',
                'cursor-pointer rounded-lg px-2 transition-colors hover:bg-muted/30',
              )}
            >
              {/* Timeline dot */}
              <div className="relative z-10 mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center">
                <div className={cn(
                  'h-2.5 w-2.5 rounded-full ring-2 ring-background',
                  cfg.dot,
                  ev.urgent && 'animate-pulse',
                )} />
              </div>

              {/* Content */}
              <div className="flex min-w-0 flex-1 items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex items-center gap-1.5">
                    <p className={cn(
                      'truncate text-sm font-medium',
                      ev.urgent && 'text-rose-600 dark:text-rose-400',
                    )}>
                      {ev.title}
                    </p>
                    {ev.urgent && <AlertTriangle className="h-3 w-3 shrink-0 text-rose-500" />}
                  </div>
                  <p className="truncate text-xs text-muted-foreground">{ev.detail}</p>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-0.5">
                  <span className="text-[11px] text-muted-foreground">{ev.time}</span>
                  {ev.value && (
                    <span className="text-[11px] font-semibold tabular-nums text-foreground/70">
                      {ev.value}
                    </span>
                  )}
                </div>
              </div>

              {/* External link on hover */}
              <ExternalLink className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground/0 transition-opacity group-hover:text-muted-foreground/40" />
            </div>
          );
        })}
      </div>
    </div>
  );
}
