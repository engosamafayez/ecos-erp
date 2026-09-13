import { useTranslation } from 'react-i18next';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Skeleton } from '@/components/ui/skeleton';

import { useExternalCarrierSummary } from '../hooks/use-control-tower';

function Panel({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <h3 className="mb-3 text-sm font-medium">{title}</h3>
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
 * External Carrier — factual CarrierShipment visibility only (OPS-03 reuse).
 * No carrier payable cost, insurance, COD settlement or Daily Transfer Cost —
 * none has a verified source, so none is rendered; the deferred note says so
 * explicitly rather than silently omitting them.
 */
export function ExternalCarrierPanel() {
  const { t } = useTranslation('logistics');
  const { data, isLoading, isError } = useExternalCarrierSummary();

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

  if (data.total_shipments === 0) {
    return (
      <p className="py-8 text-center text-sm text-muted-foreground">
        {t($ => $.operations.controlTower.externalCarrier.empty)}
      </p>
    );
  }

  const statuses = Object.entries(data.by_raw_status);

  return (
    <div className="space-y-4">
      <div className="grid gap-4 md:grid-cols-2">
        <Panel title={t($ => $.operations.controlTower.externalCarrier.title)}>
          <div className="space-y-1.5">
            <Stat label={t($ => $.operations.controlTower.externalCarrier.totalShipments)} value={data.total_shipments} />
            <Stat label={t($ => $.operations.controlTower.externalCarrier.notYetTendered)} value={data.not_yet_tendered} />
            <Stat label={t($ => $.operations.controlTower.externalCarrier.tenderedAwaitingStatus)} value={data.tendered_awaiting_status} />
          </div>
        </Panel>

        <Panel title={t($ => $.operations.controlTower.externalCarrier.byStatusTitle)}>
          {statuses.length === 0 ? (
            <p className="text-xs text-muted-foreground">{t($ => $.operations.controlTower.unavailable)}</p>
          ) : (
            <div className="space-y-1.5">
              {statuses.map(([status, count]) => (
                <Stat key={status} label={status} value={count} />
              ))}
            </div>
          )}
        </Panel>
      </div>

      <p className="text-xs text-muted-foreground">
        {t($ => $.operations.controlTower.externalCarrier.deferredNote)}
      </p>
    </div>
  );
}
