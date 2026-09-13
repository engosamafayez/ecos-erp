import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Info } from 'lucide-react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Pagination } from '@/components/crud';
import { ROUTES } from '@/router/routes';

import { useCustodySummary, useExpectedReturns, useReturnsSummary } from '../hooks/use-control-tower';
import type { ExpectedReturnRow } from '../types/control-tower';

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

/** null (incomplete linkage) is NEVER rendered as 0 — a distinct pending label instead. */
function Qty({ value, pendingLabel }: { value: number | null; pendingLabel: string }) {
  if (value === null) {
    return <span className="text-muted-foreground italic">{pendingLabel}</span>;
  }
  return <span className="tabular-nums">{value}</span>;
}

export function CustodyReturnsPanel() {
  const { t } = useTranslation('logistics');
  const navigate = useNavigate();
  const { data: custody, isLoading: custodyLoading, isError: custodyError } = useCustodySummary();
  const { data: returns, isLoading: returnsLoading, isError: returnsError } = useReturnsSummary();
  const [page, setPage] = useState(1);
  const { data: expected, isLoading: expectedLoading } = useExpectedReturns(page, 25);

  const stateLabel = (row: ExpectedReturnRow): string => {
    switch (row.linkage_state) {
      case 'awaiting_reconciliation':
        return t($ => $.operations.controlTower.custody.stateAwaitingReconciliation);
      case 'reconciled_not_yet_received':
        return t($ => $.operations.controlTower.custody.stateReconciledNotReceived);
      case 'received':
        return t($ => $.operations.controlTower.custody.stateReceived);
    }
  };

  return (
    <div className="space-y-4">
      {(custodyLoading || returnsLoading) && <Skeleton className="h-40 w-full" />}

      {(custodyError || returnsError) && (
        <Alert variant="destructive">
          <AlertDescription>{t($ => $.operations.controlTower.loadFailed)}</AlertDescription>
        </Alert>
      )}

      {custody && returns && (
        <div className="grid gap-4 md:grid-cols-2">
          <Panel title={t($ => $.operations.controlTower.custody.title)}>
            <div className="space-y-1.5">
              <Stat label={t($ => $.operations.controlTower.custody.loaded)} value={custody.loaded} />
              <Stat label={t($ => $.operations.controlTower.custody.delivered)} value={custody.delivered} />
              <Stat label={t($ => $.operations.controlTower.custody.remaining)} value={custody.remaining_with_driver_vehicle} />
              <Stat label={t($ => $.operations.controlTower.custody.returnedByDriver)} value={custody.returned_by_driver} />
              <Stat label={t($ => $.operations.controlTower.custody.receivedAccepted)} value={custody.received_by_warehouse_accepted} />
              <Stat label={t($ => $.operations.controlTower.custody.receivedDamaged)} value={custody.received_by_warehouse_damaged} />
              <Stat label={t($ => $.operations.controlTower.custody.awaitingReceipt)} value={custody.awaiting_warehouse_receipt_lines} />
              {custody.trip_return_discrepancy_qty > 0 && (
                <Stat label={t($ => $.operations.controlTower.custody.tripReturnDiscrepancy)} value={custody.trip_return_discrepancy_qty} />
              )}
            </div>
          </Panel>

          <Panel
            title={t($ => $.operations.controlTower.custody.returnsTitle)}
            action={
              <Button
                size="sm"
                variant="ghost"
                className="h-7 text-xs"
                onClick={() => navigate(ROUTES.shippingReturnsSettlement)}
              >
                {t($ => $.operations.controlTower.custody.openReturnsSettlement)}
              </Button>
            }
          >
            <div className="space-y-1.5">
              <Stat label={t($ => $.operations.controlTower.custody.physicalReturnConfirmed)} value={returns.physical_return_confirmed} />
              <Stat label={t($ => $.operations.controlTower.custody.physicalReturnAwaiting)} value={returns.physical_return_awaiting_confirmation} />
              <Stat label={t($ => $.operations.controlTower.custody.warehouseReceiptCompleted)} value={returns.warehouse_receipt_completed_lines} />
              <Stat label={t($ => $.operations.controlTower.custody.warehouseReceiptAwaiting)} value={returns.warehouse_receipt_awaiting_lines} />
              {returns.driver_liable_discrepancies > 0 && (
                <Stat label={t($ => $.operations.controlTower.custody.driverLiable)} value={returns.driver_liable_discrepancies} />
              )}
            </div>
            <div className="mt-3 flex items-start gap-1.5 rounded-md bg-muted/50 p-2 text-xs text-muted-foreground">
              <Info className="mt-0.5 size-3.5 shrink-0" />
              <span>{t($ => $.operations.controlTower.custody.returnedNote)}</span>
            </div>
          </Panel>
        </div>
      )}

      <Panel title={t($ => $.operations.controlTower.custody.expectedReturnsTitle)}>
        {expectedLoading ? (
          <Skeleton className="h-40 w-full" />
        ) : !expected || expected.data.length === 0 ? (
          <p className="py-8 text-center text-sm text-muted-foreground">
            {t($ => $.operations.controlTower.custody.empty)}
          </p>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-start text-xs uppercase tracking-wide text-muted-foreground">
                    <th className="h-9 pe-3 font-medium">{t($ => $.operations.controlTower.custody.colTrip)}</th>
                    <th className="h-9 px-3 font-medium">{t($ => $.operations.controlTower.custody.colDriver)}</th>
                    <th className="h-9 px-3 font-medium">{t($ => $.operations.controlTower.custody.colVehicle)}</th>
                    <th className="h-9 px-3 font-medium">{t($ => $.operations.controlTower.custody.colProduct)}</th>
                    <th className="h-9 px-3 text-end font-medium">{t($ => $.operations.controlTower.custody.colExpected)}</th>
                    <th className="h-9 px-3 text-end font-medium">{t($ => $.operations.controlTower.custody.colAccepted)}</th>
                    <th className="h-9 px-3 text-end font-medium">{t($ => $.operations.controlTower.custody.colDamaged)}</th>
                    <th className="h-9 px-3 font-medium">{t($ => $.operations.controlTower.custody.colState)}</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {expected.data.map((row) => (
                    <tr key={row.vehicle_inventory_item_id} className="hover:bg-muted/40">
                      <td className="py-2.5 pe-3">
                        {row.trip ? (
                          <button
                            type="button"
                            className="text-start font-medium text-primary hover:underline"
                            onClick={() => navigate(ROUTES.logisticsTrips)}
                          >
                            {row.trip.trip_number}
                          </button>
                        ) : (
                          <span className="text-muted-foreground">{t($ => $.common.na)}</span>
                        )}
                      </td>
                      <td className="px-3 py-2.5">{row.driver?.full_name ?? t($ => $.common.na)}</td>
                      <td className="px-3 py-2.5">{row.vehicle?.plate_number ?? t($ => $.common.na)}</td>
                      <td className="px-3 py-2.5">{row.product_name}</td>
                      <td className="px-3 py-2.5 text-end tabular-nums">{row.expected_qty}</td>
                      <td className="px-3 py-2.5 text-end">
                        <Qty value={row.accepted_qty} pendingLabel={t($ => $.operations.controlTower.custody.stateAwaitingReconciliation)} />
                      </td>
                      <td className="px-3 py-2.5 text-end">
                        <Qty value={row.damaged_qty} pendingLabel="—" />
                      </td>
                      <td className="px-3 py-2.5 text-xs text-muted-foreground">{stateLabel(row)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination
              meta={{
                page: expected.meta.current_page,
                perPage: expected.meta.per_page,
                total: expected.meta.total,
                lastPage: expected.meta.last_page,
              }}
              onPageChange={setPage}
            />
          </>
        )}
      </Panel>
    </div>
  );
}
