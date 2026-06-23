import type { FulfillmentStatus } from '@/features/fulfillments/types/fulfillment';

type Config = { label: string; className: string };

const STATUS_CONFIG: Record<FulfillmentStatus, Config> = {
  pending: { label: 'Pending', className: 'bg-yellow-100 text-yellow-800' },
  fulfilled: { label: 'Fulfilled', className: 'bg-emerald-100 text-emerald-800' },
  cancelled: { label: 'Cancelled', className: 'bg-rose-100 text-rose-800' },
};

export function FulfillmentStatusBadge({ status }: { status: FulfillmentStatus }) {
  const config = STATUS_CONFIG[status] ?? STATUS_CONFIG.pending;
  return (
    <span
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${config.className}`}
    >
      {config.label}
    </span>
  );
}
