import { useTranslation } from 'react-i18next';

import { EntityDrawer } from '@/components/crud';
import { Separator } from '@/components/ui/separator';
import type { AuditLogEntry } from '@/features/audit/types/audit-log';

type Props = {
  entry: AuditLogEntry | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

function JsonBlock({ value }: { value: Record<string, unknown> | null }) {
  const { t } = useTranslation('audit');

  if (value === null || Object.keys(value).length === 0) {
    return <p className="text-muted-foreground text-xs">{t($ => $.detail.none)}</p>;
  }

  return (
    <pre className="bg-muted/40 max-h-64 overflow-auto rounded-md border p-2 text-xs">
      {JSON.stringify(value, null, 2)}
    </pre>
  );
}

/**
 * Renders exactly what the central Audit trail already stored for this row — no field is
 * invented, redacted, or reconstructed here (CORE-02 Task 2 §4). A caller upstream
 * (App\Core\Audit\AuditService's callers) is the only place sensitive values are ever
 * excluded; this view is a faithful, honest read of whatever they chose to persist.
 */
export function AuditLogDetailDrawer({ entry, open, onOpenChange }: Props) {
  const { t } = useTranslation('audit');

  if (!entry) return null;

  return (
    <EntityDrawer open={open} onOpenChange={onOpenChange} title={t($ => $.detail.title)}>
      <div className="flex flex-col gap-4 text-sm">
        <dl className="grid gap-3">
          <div className="flex items-center justify-between">
            <dt className="text-muted-foreground">{t($ => $.detail.occurredAt)}</dt>
            <dd>{entry.occurred_at ? new Date(entry.occurred_at).toLocaleString() : '—'}</dd>
          </div>
          <Separator />
          <div className="flex items-center justify-between">
            <dt className="text-muted-foreground">{t($ => $.detail.actor)}</dt>
            <dd>{entry.actor ? `${entry.actor.name} (${entry.actor.email})` : t($ => $.systemActor)}</dd>
          </div>
          <Separator />
          <div className="flex items-center justify-between">
            <dt className="text-muted-foreground">{t($ => $.detail.action)}</dt>
            <dd className="font-mono text-xs">{entry.action}</dd>
          </div>
          <Separator />
          <div className="flex items-center justify-between">
            <dt className="text-muted-foreground">{t($ => $.detail.entityType)}</dt>
            <dd>{entry.entity_type}</dd>
          </div>
          <Separator />
          <div className="flex items-center justify-between">
            <dt className="text-muted-foreground">{t($ => $.detail.entityId)}</dt>
            <dd className="font-mono text-xs">{entry.entity_id}</dd>
          </div>
        </dl>

        <div className="flex flex-col gap-1.5">
          <span className="text-sm font-medium">{t($ => $.detail.oldValues)}</span>
          <JsonBlock value={entry.old_values} />
        </div>
        <div className="flex flex-col gap-1.5">
          <span className="text-sm font-medium">{t($ => $.detail.newValues)}</span>
          <JsonBlock value={entry.new_values} />
        </div>
        <div className="flex flex-col gap-1.5">
          <span className="text-sm font-medium">{t($ => $.detail.metadata)}</span>
          <JsonBlock value={entry.metadata} />
        </div>
      </div>
    </EntityDrawer>
  );
}
