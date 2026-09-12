import { MapPin, Package, User } from 'lucide-react';
import { useFormatter } from '@/hooks/use-formatter';
import { ErrorState } from '@/components/crud/error-state';
import { QueueSection } from '@/components/queue';
import type { PoolOrder } from '../types/distribution-board';

interface OrdersPoolProps {
  orders: PoolOrder[];
  isLoading: boolean;
  selectedZoneName: string;
  /**
   * True when the zone-orders read failed. Previously unhandled — a failed
   * fetch fell through to `orders: []` and rendered silently as "All orders
   * assigned" (an empty dataset), rather than a real read failure needing a
   * retry (TASK-ECOS-V1.1-CORE-01-UI-03-LIST-TABLE-FILTER-WORK-QUEUE-047 —
   * the same read-error-must-never-render-as-empty invariant UI-01 already
   * established for the canonical shared states).
   */
  isError?: boolean;
  onRetry?: () => void;
  /** Called when user clicks an order — surfaces it for manual assignment. */
  onOrderClick?: (order: PoolOrder) => void;
}

function OrderCard({ order, onClick }: { order: PoolOrder; onClick?: () => void }) {
  const { money } = useFormatter();
  return (
    <button
      onClick={onClick}
      className="w-full text-start p-3 rounded-lg border bg-card hover:bg-muted/50 transition-colors group"
    >
      <div className="flex items-start justify-between gap-2 mb-1.5">
        <span className="text-xs font-mono font-medium text-primary">#{order.order_number}</span>
        <span className="text-xs font-semibold tabular-nums">
          {money(Number(order.grand_total))}
        </span>
      </div>
      <div className="flex items-center gap-1.5 text-xs text-muted-foreground mb-1">
        <User className="h-3 w-3 shrink-0" />
        <span className="truncate">{order.customer_name}</span>
      </div>
      <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
        <MapPin className="h-3 w-3 shrink-0" />
        <span className="truncate">{order.city_name}, {order.governorate_name}</span>
      </div>
    </button>
  );
}

export function OrdersPool({ orders, isLoading, selectedZoneName, isError = false, onRetry, onOrderClick }: OrdersPoolProps) {
  return (
    <QueueSection<PoolOrder>
      title="Unassigned Orders"
      icon={Package}
      count={orders.length}
      loading={isLoading}
      error={isError}
      errorState={<ErrorState onRetry={onRetry} />}
      items={orders}
      getItemKey={(order) => order.order_id}
      renderItem={(order) => <OrderCard order={order} onClick={() => onOrderClick?.(order)} />}
      emptyState={
        <div className="flex flex-col items-center justify-center py-10 text-center">
          <Package className="h-8 w-8 text-muted-foreground/40 mb-2" />
          <p className="text-sm text-muted-foreground">All orders assigned</p>
          <p className="text-xs text-muted-foreground/60 mt-1">{selectedZoneName}</p>
        </div>
      }
      className="border-r"
    />
  );
}
