import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { SyncStatus } from '@/features/product-mappings/types/product-mapping';

type Config = { label: string; dot: string };

const STATUS_CONFIG: Record<SyncStatus, Config> = {
  pending: { label: 'Pending', dot: 'bg-amber-500' },
  synced: { label: 'Synced', dot: 'bg-emerald-500' },
  error: { label: 'Error', dot: 'bg-rose-500' },
};

export function SyncStatusBadge({ status }: { status: SyncStatus }) {
  const config = STATUS_CONFIG[status] ?? { label: status, dot: 'bg-gray-400' };

  return (
    <Badge variant="secondary" className="gap-1.5 whitespace-nowrap">
      <span className={cn('size-1.5 rounded-full', config.dot)} />
      {config.label}
    </Badge>
  );
}
