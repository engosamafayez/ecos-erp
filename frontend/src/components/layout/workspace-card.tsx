import type { ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowRight, Plus } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export type WorkspaceCardProps = {
  icon: LucideIcon;
  title: string;
  description: string;
  count?: number;
  countLabel?: string;
  href: string;
  newLabel?: string;
  onNew?: () => void;
  isLoading?: boolean;
  accent?: string;
  extra?: ReactNode;
};

export function WorkspaceCard({
  icon: Icon,
  title,
  description,
  count,
  countLabel,
  href,
  newLabel = 'New',
  onNew,
  isLoading = false,
  extra,
}: WorkspaceCardProps) {
  const navigate = useNavigate();

  return (
    <Card
      className={cn(
        'group cursor-pointer border transition-all duration-150',
        'hover:border-primary/40 hover:shadow-md',
      )}
      onClick={() => navigate(href)}
    >
      <CardContent className="flex h-full flex-col gap-4 p-6">
        {/* Header row */}
        <div className="flex items-start gap-3">
          <div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary transition-colors group-hover:bg-primary/15">
            <Icon className="size-5" />
          </div>
          <div className="flex-1 min-w-0">
            <h3 className="truncate text-base font-semibold leading-tight">{title}</h3>
            <p className="text-muted-foreground mt-0.5 text-xs leading-snug">{description}</p>
          </div>
        </div>

        {/* Count */}
        <div className="flex items-baseline gap-1.5">
          {isLoading ? (
            <div className="h-8 w-10 animate-pulse rounded bg-muted" />
          ) : (
            <span className="text-3xl font-bold tabular-nums leading-none">
              {count ?? 0}
            </span>
          )}
          {countLabel && (
            <span className="text-muted-foreground text-sm">{countLabel}</span>
          )}
        </div>

        {extra}

        {/* Quick actions */}
        <div
          className="mt-auto flex items-center gap-1 border-t pt-3"
          onClick={(e) => e.stopPropagation()}
        >
          {onNew && (
            <Button
              variant="ghost"
              size="sm"
              className="h-7 gap-1 px-2 text-xs"
              onClick={(e) => {
                e.stopPropagation();
                onNew();
              }}
            >
              <Plus className="size-3" />
              {newLabel}
            </Button>
          )}
          <Button
            variant="ghost"
            size="sm"
            className="ml-auto h-7 gap-1 px-2 text-xs"
            onClick={(e) => {
              e.stopPropagation();
              navigate(href);
            }}
          >
            View All
            <ArrowRight className="size-3" />
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
