import { StatusBadge } from '@/components/crud';
import type { PurchaseOrderStatus } from '@/features/purchase-orders/types/purchase-order';

const STATUS_MAP: Record<PurchaseOrderStatus, { variant: 'pending' | 'active' | 'archived'; label: string }> = {
  draft: { variant: 'pending', label: 'Draft' },
  approved: { variant: 'active', label: 'Approved' },
  cancelled: { variant: 'archived', label: 'Cancelled' },
};

export function PoStatusBadge({ status }: { status: PurchaseOrderStatus }) {
  const { variant, label } = STATUS_MAP[status];
  return <StatusBadge status={variant} label={label} />;
}
