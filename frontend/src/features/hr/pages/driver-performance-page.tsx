import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router-dom';

import { ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/crud';
import type { StatusVariant } from '@/components/crud/types';
import { Card, CardContent } from '@/components/ui/card';
import { useDriverPerformanceQuery } from '@/features/hr/hooks/use-compensation';
import type { DriverIdentityStatus } from '@/features/hr/types/compensation';
import { ROUTES } from '@/router/routes';

const DRIVER_IDENTITY_TONE: Record<DriverIdentityStatus, StatusVariant> = {
  matched: 'active',
  unmatched: 'pending',
  ambiguous: 'pending',
  cross_company: 'inactive',
};

const isoDate = (d: Date) => d.toISOString().slice(0, 10);
const defaultTo = () => isoDate(new Date());
const defaultFrom = () => {
  const d = new Date();
  d.setDate(d.getDate() - 29);
  return isoDate(d);
};

/**
 * FIN-01 Slice 4 — Driver Performance (Pattern C). Every figure here is
 * copied verbatim from Logistics's own driver-facing read services; a fact
 * with no canonical authority renders as "not available", never a zero.
 */
export function DriverPerformancePage() {
  const { t } = useTranslation('hr');
  const { driverId = '' } = useParams();
  const [from, setFrom] = useState(defaultFrom());
  const [to, setTo] = useState(defaultTo());

  const { data, isLoading, isError, refetch } = useDriverPerformanceQuery(driverId, from, to);

  if (isLoading) return <LoadingState />;
  if (isError || !data) return <ErrorState onRetry={() => void refetch()} />;

  const { driver, identity, delivery, settlement, shortages } = data;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`${driver.name} — ${t(($) => $.performance.drivers.detailTitle)}`}
        subtitle={`${from} – ${to}`}
        breadcrumbs={[
          { label: t(($) => $.performance.breadcrumbWorkforce), to: ROUTES.hr },
          { label: t(($) => $.performance.breadcrumb), to: ROUTES.hrPerformance },
          { label: driver.name },
        ]}
        actions={
          <div className="flex items-center gap-2">
            <input
              type="date"
              value={from}
              max={to}
              onChange={(e) => setFrom(e.target.value)}
              className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
            />
            <span className="text-muted-foreground text-sm">–</span>
            <input
              type="date"
              value={to}
              min={from}
              onChange={(e) => setTo(e.target.value)}
              className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
            />
          </div>
        }
      />

      <Card>
        <CardContent className="flex flex-wrap items-center gap-3 pt-6">
          <span className="text-muted-foreground text-sm">{t(($) => $.performance.drivers.identityLabel)}</span>
          <StatusBadge
            status={DRIVER_IDENTITY_TONE[identity.status]}
            label={t(($) => $.performance.drivers.identity[identity.status])}
          />
          {identity.employee_name ? (
            <span className="text-sm font-medium">{identity.employee_name}</span>
          ) : (
            <span className="text-muted-foreground text-sm">{t(($) => $.performance.drivers.noEmployeeLink)}</span>
          )}
        </CardContent>
      </Card>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">{t(($) => $.performance.drivers.stats.received)}</div>
            <div className="text-2xl font-bold">{delivery.received}</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">{t(($) => $.performance.drivers.stats.delivered)}</div>
            <div className="text-2xl font-bold text-emerald-600">{delivery.delivered}</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">{t(($) => $.performance.drivers.stats.deliveryRate)}</div>
            <div className="text-2xl font-bold">{delivery.delivery_rate}%</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">{t(($) => $.performance.drivers.stats.undelivered)}</div>
            <div className="text-2xl font-bold text-red-600">
              {delivery.failed + delivery.returned + delivery.skipped}
            </div>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardContent className="flex flex-col gap-3 pt-6">
            <h2 className="font-semibold">{t(($) => $.performance.drivers.outcomes.title)}</h2>
            <ul className="flex flex-col gap-2 text-sm">
              <li className="flex justify-between">
                <span>{t(($) => $.performance.drivers.outcomes.partial)}</span>
                <span className="tabular-nums">{delivery.partial}</span>
              </li>
              <li className="flex justify-between">
                <span>{t(($) => $.performance.drivers.outcomes.failed)}</span>
                <span className="tabular-nums">{delivery.failed}</span>
              </li>
              <li className="flex justify-between">
                <span>{t(($) => $.performance.drivers.outcomes.returned)}</span>
                <span className="tabular-nums">{delivery.returned}</span>
              </li>
              <li className="flex justify-between">
                <span>{t(($) => $.performance.drivers.outcomes.skipped)}</span>
                <span className="tabular-nums">{delivery.skipped}</span>
              </li>
              <li className="flex justify-between">
                <span>{t(($) => $.performance.drivers.outcomes.pending)}</span>
                <span className="tabular-nums">{delivery.pending}</span>
              </li>
            </ul>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="flex flex-col gap-3 pt-6">
            <h2 className="font-semibold">{t(($) => $.performance.drivers.settlement.title)}</h2>
            <ul className="flex flex-col gap-2 text-sm">
              <li className="flex justify-between">
                <span>{t(($) => $.performance.drivers.settlement.status)}</span>
                <span className="font-medium">{settlement.status}</span>
              </li>
              <li className="flex justify-between">
                <span>{t(($) => $.performance.drivers.settlement.cashExpected)}</span>
                <span className="tabular-nums">{settlement.cash_expected.toFixed(2)}</span>
              </li>
              <li className="flex justify-between">
                <span>{t(($) => $.performance.drivers.settlement.cashSubmitted)}</span>
                <span className="tabular-nums">
                  {settlement.cash_submitted === null
                    ? t(($) => $.performance.drivers.notAvailable)
                    : settlement.cash_submitted.toFixed(2)}
                </span>
              </li>
              <li className="flex justify-between">
                <span>{t(($) => $.performance.drivers.settlement.difference)}</span>
                <span className="tabular-nums">
                  {settlement.difference === null
                    ? t(($) => $.performance.drivers.notAvailable)
                    : settlement.difference.toFixed(2)}
                </span>
              </li>
              <li className="flex justify-between">
                <span>{t(($) => $.performance.drivers.settlement.shortages)}</span>
                <span className="tabular-nums">
                  {shortages.count}
                  {!shortages.value_available && (
                    <span className="text-muted-foreground ms-1 text-xs">
                      ({t(($) => $.performance.drivers.notAvailable)})
                    </span>
                  )}
                </span>
              </li>
            </ul>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
