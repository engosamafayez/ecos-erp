import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { MovementType } from '@/features/stock-ledger/types/stock-movement';

type Config = {
  label: string;
  dot: string;
  variant: 'secondary' | 'outline';
};

const TYPE_CONFIG: Record<MovementType, Config> = {
  purchase_receipt: { label: 'Purchase Receipt', dot: 'bg-emerald-500', variant: 'secondary' },
  sales_issue: { label: 'Sales Issue', dot: 'bg-rose-500', variant: 'secondary' },
  adjustment_in: { label: 'Adjustment In', dot: 'bg-blue-500', variant: 'secondary' },
  adjustment_out: { label: 'Adjustment Out', dot: 'bg-orange-500', variant: 'secondary' },
  transfer_in: { label: 'Transfer In', dot: 'bg-violet-500', variant: 'secondary' },
  transfer_out: { label: 'Transfer Out', dot: 'bg-amber-500', variant: 'secondary' },
};

export function MovementTypeBadge({ type }: { type: MovementType }) {
  const config = TYPE_CONFIG[type];
  return (
    <Badge variant={config.variant} className="gap-1.5 whitespace-nowrap">
      <span className={cn('size-1.5 rounded-full', config.dot)} />
      {config.label}
    </Badge>
  );
}
