import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { UniversalDataGrid, type DataGridColumnDef } from '@/components/data-grid';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePermission } from '@/features/authorization';
import { useFormatter } from '@/hooks/use-formatter';
import { cn } from '@/lib/utils';

import { Field, NoAccess, Panel } from './finance-panels';
import { useDriverLedger } from '../hooks/use-finance-driver-ledger';
import type { DriverLedgerEntry } from '../types/finance-driver-ledger';

/**
 * `advance`/`shortage` increase what the driver owes (positive amount, flagged for attention);
 * `expense`/`settlement` decrease it (negative amount, favorable). Reuses this app's existing
 * red/emerald convention for signed figures (finance-executive-page.tsx profit,
 * financial-statements-page.tsx balanced/unbalanced pill) rather than inventing a new one —
 * neither ap-badges.tsx nor the trial balance page actually color debit/credit columns
 * (both render them as plain uncolored tabular-nums), so there is no literal precedent to
 * copy; this is the closest real sign-coloring convention in the codebase.
 */
function signClass(value: number): string | undefined {
  if (value > 0) return 'text-red-600';
  if (value < 0) return 'text-emerald-600';
  return undefined;
}

/**
 * Driver Ledger tab (Costing & Profitability). Strictly read-only — ledger
 * entries are created only by backend-side automatic postings, so there is no
 * create/edit action here. `driverId` is an opaque reference (Finance has no
 * driver directory/picker): the id is typed/pasted in and the ledger is
 * fetched on demand, mirroring CashBankingPage's reconciliation-by-id lookup
 * and useSupplierLedger's lazy-query pattern exactly.
 */
export function DriverLedgerTab() {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const { can } = usePermission();

  const [inputId, setInputId] = useState('');
  const [driverId, setDriverId] = useState<string | null>(null);

  const ledger = useDriverLedger(driverId);

  if (!can('finance.driver.view')) {
    return <NoAccess />;
  }

  const columns: DataGridColumnDef<DriverLedgerEntry>[] = [
    { key: 'entry_date', label: t(($) => $.driverLedger.field.date), pin: 'left', cell: (e) => fmt.date(e.entry_date) },
    { key: 'entry_type', label: t(($) => $.driverLedger.field.type), cell: (e) => t(($) => $.driverLedger.entryType[e.entry_type]) },
    { key: 'description', label: t(($) => $.driverLedger.field.description), cell: (e) => <span className="text-muted-foreground">{e.description || '—'}</span> },
    {
      key: 'source', label: t(($) => $.driverLedger.field.source),
      cell: (e) =>
        e.source_type ? (
          <span className="font-mono text-xs" title={e.source_id ?? undefined}>
            {e.source_type}{e.source_id ? ` · ${e.source_id.slice(0, 8)}…` : ''}
          </span>
        ) : '—',
    },
    {
      key: 'amount', label: t(($) => $.driverLedger.field.amount), align: 'end',
      cell: (e) => <span className={cn('tabular-nums', signClass(e.amount))}>{fmt.money(e.amount)}</span>,
    },
    {
      key: 'running_balance', label: t(($) => $.driverLedger.field.runningBalance), align: 'end',
      cell: (e) => <span className={cn('tabular-nums font-medium', signClass(e.running_balance))}>{fmt.money(e.running_balance)}</span>,
    },
  ];

  return (
    <div className="space-y-4">
      <Panel title={t(($) => $.driverLedger.lookup.title)} hint={t(($) => $.driverLedger.lookup.hint)}>
        <div className="flex flex-wrap items-end gap-2">
          <Field id="driver-id" label={t(($) => $.driverLedger.field.driverId)}>
            <Input
              id="driver-id"
              value={inputId}
              dir="ltr"
              placeholder={t(($) => $.driverLedger.lookup.placeholder)}
              onChange={(e) => setInputId(e.target.value)}
              className="w-80"
            />
          </Field>
          <Button disabled={inputId.trim() === ''} onClick={() => setDriverId(inputId.trim())}>
            {t(($) => $.driverLedger.lookup.load)}
          </Button>
        </div>
      </Panel>

      {driverId != null && (
        <>
          {ledger.data && (
            <div className="flex flex-wrap items-center gap-6 rounded-lg border bg-muted/30 px-4 py-3 text-sm">
              <span>
                <span className="text-muted-foreground">{t(($) => $.driverLedger.balance)}: </span>
                <span className={cn('tabular-nums font-semibold', signClass(ledger.data.balance))}>
                  {fmt.money(ledger.data.balance)}
                </span>
              </span>
              <span className="text-xs text-muted-foreground">
                {ledger.data.balance > 0
                  ? t(($) => $.driverLedger.balanceMeaning.owesCompany)
                  : ledger.data.balance < 0
                    ? t(($) => $.driverLedger.balanceMeaning.owedByCompany)
                    : t(($) => $.driverLedger.balanceMeaning.settled)}
              </span>
            </div>
          )}

          <UniversalDataGrid
            data={ledger.data?.entries ?? []}
            columns={columns}
            rowId={(e) => e.id}
            loading={ledger.isLoading}
            error={ledger.isError}
            emptyState={<p className="py-10 text-center text-sm text-muted-foreground">{t(($) => $.driverLedger.empty)}</p>}
          />
        </>
      )}
    </div>
  );
}
