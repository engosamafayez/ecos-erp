import {
  AlertTriangle,
  CheckCircle2,
  Factory,
  Package,
  RotateCcw,
  ShoppingCart,
  Truck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

type EventType = 'order' | 'stock' | 'production' | 'purchase' | 'shipment' | 'return' | 'alert';

type Event = {
  id: string;
  type: EventType;
  title: string;
  subtitle: string;
  time: string;
};

const TYPE_CONFIG: Record<EventType, { icon: LucideIcon; iconCls: string; bgCls: string }> = {
  order:      { icon: ShoppingCart,  iconCls: 'text-indigo-500',  bgCls: 'bg-indigo-500/10' },
  stock:      { icon: Package,       iconCls: 'text-amber-500',   bgCls: 'bg-amber-500/10' },
  production: { icon: Factory,       iconCls: 'text-violet-500',  bgCls: 'bg-violet-500/10' },
  purchase:   { icon: CheckCircle2,  iconCls: 'text-emerald-500', bgCls: 'bg-emerald-500/10' },
  shipment:   { icon: Truck,         iconCls: 'text-cyan-500',    bgCls: 'bg-cyan-500/10' },
  return:     { icon: RotateCcw,     iconCls: 'text-red-500',     bgCls: 'bg-red-500/10' },
  alert:      { icon: AlertTriangle, iconCls: 'text-amber-500',   bgCls: 'bg-amber-500/10' },
};

const EVENTS: Event[] = [
  { id: '1', type: 'order',      title: 'New Order #1001',            subtitle: 'Ahmed Ali · WooCommerce',    time: '2m ago' },
  { id: '2', type: 'stock',      title: 'Low Stock: SKU-GH-001',      subtitle: 'Warehouse A · Qty: 3',       time: '8m ago' },
  { id: '3', type: 'shipment',   title: 'Trip TRP-44 Delivered',      subtitle: 'Driver: Mohamed Hassan',     time: '15m ago' },
  { id: '4', type: 'production', title: 'Wave WV-089 Complete',       subtitle: 'Prep OS · 120 units',        time: '22m ago' },
  { id: '5', type: 'purchase',   title: 'PO-012 Received',            subtitle: 'Supplier: Al-Nour Trading',  time: '1h ago' },
  { id: '6', type: 'return',     title: 'Return RMA-221 Filed',       subtitle: 'Order #998 · Damaged item',  time: '2h ago' },
  { id: '7', type: 'alert',      title: 'Inventory Count Variance',   subtitle: 'Warehouse B · 3 SKUs',       time: '3h ago' },
];

export function ActivityFeed() {
  return (
    <Card className="flex flex-col" style={{ minHeight: 0 }}>
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between">
          <div>
            <CardTitle className="text-base">Activity Feed</CardTitle>
            <p className="text-muted-foreground text-xs mt-0.5">Recent business events</p>
          </div>
          <Button variant="ghost" size="sm" className="h-7 px-2 text-xs">
            View all
          </Button>
        </div>
      </CardHeader>
      <CardContent className="p-0 overflow-auto flex-1">
        <div className="divide-y">
          {EVENTS.map((ev) => {
            const cfg = TYPE_CONFIG[ev.type];
            const Icon = cfg.icon;
            return (
              <div
                key={ev.id}
                className="flex items-start gap-3 px-4 py-3 hover:bg-muted/40 transition-colors cursor-pointer"
              >
                <div
                  className={cn(
                    'mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full',
                    cfg.bgCls,
                  )}
                >
                  <Icon className={cn('h-3.5 w-3.5', cfg.iconCls)} />
                </div>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{ev.title}</p>
                  <p className="truncate text-xs text-muted-foreground">{ev.subtitle}</p>
                </div>
                <span className="shrink-0 text-[11px] text-muted-foreground">{ev.time}</span>
              </div>
            );
          })}
        </div>
      </CardContent>
    </Card>
  );
}
