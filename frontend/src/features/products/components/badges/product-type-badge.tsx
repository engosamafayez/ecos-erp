import { Package, Layers } from 'lucide-react';

import { cn } from '@/lib/utils';
import type { ProductType } from '@/features/products/types/product';

const CONFIG: Record<ProductType, { label: string; icon: typeof Package; className: string }> = {
  finished_good: {
    label: 'Finished Good',
    icon: Package,
    className:
      'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-800 dark:bg-violet-950/50 dark:text-violet-400',
  },
  raw_material: {
    label: 'Raw Material',
    icon: Layers,
    className:
      'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-800 dark:bg-orange-950/50 dark:text-orange-400',
  },
};

type ProductTypeBadgeProps = {
  type: ProductType;
  className?: string;
};

export function ProductTypeBadge({ type, className }: ProductTypeBadgeProps) {
  const cfg = CONFIG[type];
  const Icon = cfg.icon;

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[11px] font-medium',
        cfg.className,
        className,
      )}
    >
      <Icon className="size-3 shrink-0" />
      {cfg.label}
    </span>
  );
}
