import type { LucideIcon } from 'lucide-react';
import { ArrowUpRight } from 'lucide-react';

import { EmptyState } from '@/components/crud';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

export type GapCardAction = {
  label: string;
  onClick: () => void;
};

/**
 * Honesty-first placeholder for a tab that has no real backend aggregate to
 * show (TASK-ECOS-SHIPPING-OS-REDESIGN-001 — Returns & Settlement).
 *
 * This is deliberately NOT a broken/empty screen: it names the specific gap
 * (why nothing is shown here) and hands the user a real, working deep link to
 * the existing workspace where the underlying record already lives and is
 * fully functional. Never fabricates a number and never re-derives a
 * shortage/liability/discrepancy figure — see the per-tab callers for the
 * exact backend reasoning.
 */
export function GapCard({
  icon,
  title,
  description,
  note,
  actions,
}: {
  icon: LucideIcon;
  title: string;
  description: string;
  note?: string;
  actions: GapCardAction[];
}) {
  return (
    <Card>
      <CardContent className="flex flex-col items-center gap-4 py-10">
        <EmptyState
          icon={icon}
          title={title}
          description={description}
          action={
            <div className="flex flex-wrap items-center justify-center gap-2">
              {actions.map((action, index) => (
                <Button
                  key={action.label}
                  size="sm"
                  variant={index === 0 ? 'default' : 'outline'}
                  onClick={action.onClick}
                >
                  {action.label}
                  <ArrowUpRight className="ms-1.5 size-3.5" />
                </Button>
              ))}
            </div>
          }
        />
        {note ? (
          <p className="max-w-md border-t pt-4 text-center text-xs text-muted-foreground">{note}</p>
        ) : null}
      </CardContent>
    </Card>
  );
}
