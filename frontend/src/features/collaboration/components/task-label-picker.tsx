import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { toast } from '@/components/ds/use-toast';
import { cn } from '@/lib/utils';

import { useAttachTaskLabel, useCreateTaskLabel, useDetachTaskLabel, useTaskLabels } from '../hooks/use-tasks';
import type { Task, TaskLabelColor } from '../types';
import { TaskLabelBadge } from './task-label-badge';

const COLORS: TaskLabelColor[] = ['gray', 'red', 'orange', 'yellow', 'green', 'blue', 'purple'];

const SWATCH_CLASS: Record<TaskLabelColor, string> = {
  gray: 'bg-slate-400',
  red: 'bg-red-500',
  orange: 'bg-orange-500',
  yellow: 'bg-yellow-400',
  green: 'bg-green-500',
  blue: 'bg-blue-500',
  purple: 'bg-purple-500',
};

/** Attach/detach existing company labels, or create a new one inline — the one shared editing surface for a task's labels. */
export function TaskLabelPicker({ task }: { task: Task }) {
  const { t } = useTranslation('collaboration');
  const { data: labels = [] } = useTaskLabels();
  const attach = useAttachTaskLabel(task.id);
  const detach = useDetachTaskLabel(task.id);
  const createLabel = useCreateTaskLabel();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const [color, setColor] = useState<TaskLabelColor>('blue');
  const triggerRef = useRef<HTMLButtonElement>(null);
  // TASK-ECOS-SYSTEM-WIDE-SEARCHABLE-SELECT-FOCUS-REMEDIATION-006 §4/§6 — same
  // root cause and fix as EmployeeLookupField (see its own comment for the
  // full mechanism): this Popover is nested inside the Task Detail Sheet, and
  // its content otherwise portals to `document.body` — a DOM sibling of the
  // Sheet's own content, never a descendant — so the Sheet's own focus trap
  // yanked focus straight back out of the "Label Name" input on every
  // keystroke. Portal into the nearest ancestor dialog's content node instead
  // when one exists. Computed inside the effect below (an approved place to
  // read a ref's current value), never during render.
  const [portalContainer, setPortalContainer] = useState<HTMLElement | null>(null);
  useEffect(() => {
    if (open) {
      setPortalContainer(triggerRef.current?.closest<HTMLElement>('[role="dialog"]') ?? null);
    }
  }, [open]);

  const attachedIds = new Set((task.labels ?? []).map((l) => l.id));

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button ref={triggerRef} size="sm" variant="outline" className="h-7 gap-1 text-xs">
          <Plus className="size-3.5" />
          {t(($) => $.tasks.labels.add)}
        </Button>
      </PopoverTrigger>
      <PopoverContent container={portalContainer} align="start" className="w-64">
        <p className="mb-2 text-xs font-medium text-muted-foreground">{t(($) => $.tasks.labels.title)}</p>
        <div className="flex flex-col gap-1">
          {labels.map((label) => {
            const isAttached = attachedIds.has(label.id);
            return (
              <button
                key={label.id}
                type="button"
                onClick={() => {
                  const mutation = isAttached ? detach : attach;
                  mutation.mutate(label.id, { onError: () => toast.error(t(($) => $.errors.generic)) });
                }}
                className={cn(
                  'flex items-center justify-between rounded-md px-2 py-1.5 text-start hover:bg-accent',
                  isAttached && 'bg-accent/60',
                )}
              >
                <TaskLabelBadge label={label} />
                {isAttached ? <span className="text-xs">✓</span> : null}
              </button>
            );
          })}
          {labels.length === 0 ? <p className="px-2 py-1 text-xs text-muted-foreground">{t(($) => $.tasks.labels.none)}</p> : null}
        </div>

        <form
          className="mt-3 flex flex-col gap-2 border-t pt-3"
          onSubmit={(e) => {
            e.preventDefault();
            const trimmed = name.trim();
            if (!trimmed) return;
            createLabel.mutate(
              { name: trimmed, color },
              {
                onSuccess: (created) => {
                  setName('');
                  attach.mutate(created.id);
                },
                onError: () => toast.error(t(($) => $.errors.generic)),
              },
            );
          }}
        >
          <Input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder={t(($) => $.tasks.labels.namePlaceholder)}
            className="h-8 text-xs"
          />
          <div className="flex items-center gap-1.5">
            {COLORS.map((c) => (
              <button
                key={c}
                type="button"
                aria-label={t(($) => $.tasks.labels.colors[c])}
                aria-pressed={color === c}
                onClick={() => setColor(c)}
                className={cn(
                  'size-5 rounded-full ring-offset-1 transition-shadow',
                  SWATCH_CLASS[c],
                  color === c && 'ring-2 ring-ring',
                )}
              />
            ))}
          </div>
          <Button type="submit" size="sm" variant="outline" className="h-7 text-xs" disabled={!name.trim() || createLabel.isPending}>
            {t(($) => $.tasks.labels.create)}
          </Button>
        </form>
      </PopoverContent>
    </Popover>
  );
}
