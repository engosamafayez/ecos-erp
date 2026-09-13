import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Info } from 'lucide-react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { ROUTES } from '@/router/routes';

import { useSettlementSummary } from '../hooks/use-control-tower';

function Panel({ title, action, children }: { title: string; action?: React.ReactNode; children: React.ReactNode }) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <div className="mb-3 flex items-center justify-between gap-2">
        <h3 className="text-sm font-medium">{title}</h3>
        {action}
      </div>
      {children}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="flex items-center justify-between text-xs">
      <span className="text-muted-foreground">{label}</span>
      <span className="tabular-nums">{value}</span>
    </div>
  );
}

/**
 * Settlement — canonical TripSettlement status counts plus the existing
 * collection_difference_pending signal. Section 10/11: a pending collection
 * difference is shown as "pending / not final", never a negative or fake
 * final number; settlement and physical-return state are shown side by side
 * without coupling either.
 */
export function SettlementPanel() {
  const { t } = useTranslation('logistics');
  const navigate = useNavigate();
  const { data, isLoading, isError } = useSettlementSummary();

  if (isLoading) {
    return <Skeleton className="h-56 w-full" />;
  }

  if (isError || !data) {
    return (
      <Alert variant="destructive">
        <AlertDescription>{t($ => $.operations.controlTower.loadFailed)}</AlertDescription>
      </Alert>
    );
  }

  return (
    <div className="space-y-4">
      <div className="grid gap-4 md:grid-cols-2">
        <Panel
          title={t($ => $.operations.controlTower.settlement.title)}
          action={
            <Button
              size="sm"
              variant="ghost"
              className="h-7 text-xs"
              onClick={() => navigate(ROUTES.logisticsDriverSettlement)}
            >
              {t($ => $.operations.controlTower.settlement.openDriverSettlement)}
            </Button>
          }
        >
          <div className="space-y-1.5">
            <Stat label={t($ => $.operations.controlTower.settlement.draft)} value={data.draft} />
            <Stat label={t($ => $.operations.controlTower.settlement.submitted)} value={data.submitted} />
            <Stat label={t($ => $.operations.controlTower.settlement.reconciled)} value={data.reconciled} />
            {data.disputed > 0 && (
              <Stat label={t($ => $.operations.controlTower.settlement.disputed)} value={data.disputed} />
            )}
            <Stat label={t($ => $.operations.controlTower.settlement.finalized)} value={data.finalized} />
          </div>
        </Panel>

        <Panel title={t($ => $.operations.controlTower.settlement.collectionDifferencePending)}>
          <div className="flex flex-col items-center justify-center gap-2 py-4">
            <span className="text-3xl font-semibold tabular-nums">{data.collection_difference_pending}</span>
            <span className="text-center text-xs text-muted-foreground">
              {t($ => $.operations.controlTower.settlement.collectionDifferencePendingHint)}
            </span>
          </div>
        </Panel>
      </div>

      <div className="flex items-start gap-1.5 rounded-md bg-muted/50 p-2 text-xs text-muted-foreground">
        <Info className="mt-0.5 size-3.5 shrink-0" />
        <span>{t($ => $.operations.controlTower.settlement.physicalReturnIndependent)}</span>
      </div>
    </div>
  );
}
