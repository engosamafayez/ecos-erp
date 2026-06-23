import { StatusBadge } from '@/components/crud';
import type { GoodsReceiptStatus } from '@/features/goods-receipts/types/goods-receipt';

const STATUS_MAP: Record<GoodsReceiptStatus, { variant: 'pending' | 'active'; label: string }> = {
  draft: { variant: 'pending', label: 'Draft' },
  posted: { variant: 'active', label: 'Posted' },
};

export function GrStatusBadge({ status }: { status: GoodsReceiptStatus }) {
  const { variant, label } = STATUS_MAP[status];
  return <StatusBadge status={variant} label={label} />;
}
