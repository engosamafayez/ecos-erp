import { Globe } from 'lucide-react';

import { cn } from '@/lib/utils';

type ChannelBadgeProps = {
  name: string;
  className?: string;
};

export function ChannelBadge({ name, className }: ChannelBadgeProps) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-md border border-indigo-200 bg-indigo-50 px-1.5 py-0.5 text-[11px] font-medium text-indigo-700',
        'dark:border-indigo-800 dark:bg-indigo-950/50 dark:text-indigo-400',
        className,
      )}
    >
      <Globe className="size-3 shrink-0" />
      {name}
    </span>
  );
}

type ChannelCellProps = {
  channels?: Array<{ id: string; name: string }>;
};

/** Renders up to 2 channel badges + overflow count. */
export function ChannelCell({ channels }: ChannelCellProps) {
  if (!channels || channels.length === 0) {
    return <span className="text-muted-foreground text-xs">—</span>;
  }

  const visible = channels.slice(0, 2);
  const rest = channels.length - visible.length;

  return (
    <div className="flex flex-wrap items-center gap-1">
      {visible.map((ch) => (
        <ChannelBadge key={ch.id} name={ch.name} />
      ))}
      {rest > 0 ? (
        <span className="text-[11px] text-muted-foreground">+{rest}</span>
      ) : null}
    </div>
  );
}
