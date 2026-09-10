import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import type { OpeningInventoryLine, ResetDomain } from '@/features/golive/types/golive';
import {
  useActivateGoLive,
  useEstablishOpeningInventory,
  useGoLiveExecuteReset,
  useGoLivePreview,
  useGoLiveStatus,
} from '@/features/golive/hooks/use-golive';

const DOMAINS: ResetDomain[] = ['commerce', 'operations', 'inventory', 'finance'];
const inputClass = 'border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs';
const cardClass = 'flex flex-col gap-3 rounded-lg border p-4';

/**
 * TASK-...-026 §14 — Go-Live Preparation. A single functional page covering the wizard's core
 * steps (status/readiness, domain selection, preview, confirmed reset, opening inventory,
 * activation) rather than eight separately-polished screens — see the Task 026 report's UI-scope
 * disclosure. Every action here calls the real backend endpoints; nothing here can mark the
 * company Live except the explicit "Activate Go-Live" button (§14: earlier steps never do).
 */
export function GoLivePreparationPage() {
  const { t } = useTranslation('golive');
  const { data: status, isLoading: statusLoading } = useGoLiveStatus();
  const preview = useGoLivePreview();
  const executeReset = useGoLiveExecuteReset();
  const openingInventory = useEstablishOpeningInventory();
  const activate = useActivateGoLive();

  const [selectedDomains, setSelectedDomains] = useState<ResetDomain[]>([]);
  const [confirmationPhrase, setConfirmationPhrase] = useState('');
  const [reason, setReason] = useState('');
  const [inventoryLines, setInventoryLines] = useState<OpeningInventoryLine[]>([
    { warehouse_id: '', product_id: '', quantity: 0, unit_cost: 0 },
  ]);


  const isLive = status?.lifecycle_state === 'live';

  // Static per-domain t() calls resolved via a plain lookup afterward — safer than indexing the
  // typed translation path dynamically (TASK-...-025 found this pattern unconfirmed-supported and
  // replaced it the same way; applied here from the start rather than re-learning it).
  const domainLabels: Record<ResetDomain, string> = {
    commerce: t($ => $.domains.commerce),
    operations: t($ => $.domains.operations),
    inventory: t($ => $.domains.inventory),
    finance: t($ => $.domains.finance),
  };

  const toggleDomain = (domain: ResetDomain) => {
    setSelectedDomains((curr) => (curr.includes(domain) ? curr.filter((d) => d !== domain) : [...curr, domain]));
  };

  const handlePreview = () => {
    preview.mutate({ domains: selectedDomains });
  };

  const handleExecute = () => {
    if (!preview.data?.is_safe) return;
    // Generated fresh per click, in the event handler only — never during render (Date.now() is
    // impure). React Query's mutations don't auto-retry by default here, so there is no
    // automatic-duplicate-submission case that would need the same key reused across attempts;
    // the button is also disabled while a request is in flight (see `disabled` below), which is
    // what actually prevents an accidental double-submission with two different keys.
    executeReset.mutate({
      domains: selectedDomains,
      confirmation_phrase: confirmationPhrase,
      idempotency_key: `golive-reset-${Date.now()}`,
      reason: reason || undefined,
    });
  };

  const handleAddInventoryLine = () => {
    setInventoryLines((curr) => [...curr, { warehouse_id: '', product_id: '', quantity: 0, unit_cost: 0 }]);
  };

  const handleInventoryLineChange = (index: number, patch: Partial<OpeningInventoryLine>) => {
    setInventoryLines((curr) => curr.map((line, i) => (i === index ? { ...line, ...patch } : line)));
  };

  const handleEstablishInventory = () => {
    openingInventory.mutate(inventoryLines.filter((l) => l.warehouse_id && l.product_id && l.quantity > 0));
  };

  if (statusLoading) {
    return <div className="p-6 text-sm">{t($ => $.loading)}</div>;
  }

  return (
    <div className="flex flex-col gap-6 p-6" dir="auto">
      {/* Step 1: Pre-Live readiness — the current lifecycle state itself. */}
      <header className="flex items-center justify-between">
        <h1 className="text-xl font-semibold">{t($ => $.title)}</h1>
        <span
          className={`rounded-full px-3 py-1 text-sm font-medium ${
            isLive ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'
          }`}
        >
          {isLive ? t($ => $.stateLive) : t($ => $.statePreLive)}
        </span>
      </header>

      {isLive ? (
        <div className={cardClass}>
          <p className="text-sm">{t($ => $.liveLockNotice)}</p>
        </div>
      ) : (
        <>
          {/* Step 3: preserved master data — purely informational, always true, never a live
              query: no reset service in this lane ever references any of these tables (see the
              Task 026 report's Master Data Preservation section). */}
          <section className={cardClass}>
            <h2 className="font-medium">{t($ => $.preservedDataTitle)}</h2>
            <p className="text-sm text-muted-foreground">{t($ => $.preservedDataDescription)}</p>
          </section>

          {/* Step 4: domain selection */}
          <section className={cardClass}>
            <h2 className="font-medium">{t($ => $.selectDomainsTitle)}</h2>
            <div className="flex flex-col gap-2">
              {DOMAINS.map((domain) => (
                <label key={domain} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="border-input size-4 rounded"
                    checked={selectedDomains.includes(domain)}
                    onChange={() => toggleDomain(domain)}
                  />
                  {domainLabels[domain]}
                </label>
              ))}
            </div>
            <Button onClick={handlePreview} disabled={selectedDomains.length === 0 || preview.isPending}>
              {t($ => $.previewButton)}
            </Button>
          </section>

          {/* Step 5: dependency preview (read-only). Step 2 (Woo Cutover) is shown inside this
              same block once a preview has run — its data (Task 025-R1's Orders Sync state) is
              fetched together with the dependency counts by the same backend call rather than a
              separate one; still a distinct, clearly-labeled step below, not merged into it. */}
          {preview.data && (
            <section className={cardClass}>
              <h2 className="font-medium">{t($ => $.previewTitle)}</h2>

              {preview.data.blockers.length > 0 && (
                <div className="rounded-md bg-rose-50 p-3 text-sm text-rose-700">
                  {preview.data.blockers.map((b) => (
                    <p key={b}>{b}</p>
                  ))}
                </div>
              )}

              {Object.entries(preview.data.counts).map(([domain, tables]) => (
                <div key={domain} className="flex flex-col gap-1">
                  <span className="text-sm font-medium">{domainLabels[domain as ResetDomain]}</span>
                  <div className="grid grid-cols-2 gap-1 text-xs text-muted-foreground sm:grid-cols-3">
                    {Object.entries(tables).map(([table, count]) => (
                      <span key={table}>
                        {table}: {count}
                      </span>
                    ))}
                  </div>
                </div>
              ))}

              <div className="flex flex-col gap-1 border-t pt-3">
                <span className="text-sm font-medium">{t($ => $.wooCutoverTitle)}</span>
                {preview.data.woo_cutover.length === 0 ? (
                  <span className="text-xs text-muted-foreground">{t($ => $.noChannels)}</span>
                ) : (
                  preview.data.woo_cutover.map((c) => (
                    <div key={c.channel_id} className="text-xs text-muted-foreground">
                      {c.name} — {c.health_status} — {c.sync_orders ? t($ => $.ordersSyncEnabled) : t($ => $.ordersSyncPaused)}
                      {c.orders_sync_activated_at === null ? ` — ${t($ => $.cutoverUnresolved)}` : ''}
                    </div>
                  ))
                )}
              </div>

              {/* Step 6: strong confirmation + execute */}
              {preview.data.is_safe && (
                <div className="flex flex-col gap-2 border-t pt-3">
                  <p className="text-sm font-medium text-rose-700">{t($ => $.irreversibleWarning)}</p>
                  <label className="text-sm">{t($ => $.confirmationLabel)}</label>
                  <input
                    className={inputClass}
                    value={confirmationPhrase}
                    onChange={(e) => setConfirmationPhrase(e.target.value)}
                    placeholder={t($ => $.confirmationPlaceholder)}
                  />
                  <input
                    className={inputClass}
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    placeholder={t($ => $.reasonPlaceholder)}
                  />
                  <Button
                    variant="destructive"
                    onClick={handleExecute}
                    disabled={executeReset.isPending || confirmationPhrase.length === 0}
                  >
                    {t($ => $.executeButton)}
                  </Button>
                </div>
              )}
            </section>
          )}

          {executeReset.data && (
            <section className={cardClass}>
              <h2 className="font-medium">{t($ => $.executionResultTitle)}</h2>
              <p className="text-sm">{t($ => $.statusLabel)}: {executeReset.data.status}</p>
              {executeReset.data.failure_message && (
                <p className="text-sm text-rose-700">{executeReset.data.failure_message}</p>
              )}
            </section>
          )}

          {/* Step 7: opening-state readiness (Inventory). Supplier/Customer opening balances are
              reached from their own existing Supplier/Customer detail screens (the canonical
              Finance opening-balance authorities this task reuses/mirrors), not duplicated here. */}
          <section className={cardClass}>
            <h2 className="font-medium">{t($ => $.openingInventoryTitle)}</h2>
            {inventoryLines.map((line, i) => (
              <div key={i} className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <input
                  className={inputClass}
                  placeholder={t($ => $.warehouseIdPlaceholder)}
                  value={line.warehouse_id}
                  onChange={(e) => handleInventoryLineChange(i, { warehouse_id: e.target.value })}
                />
                <input
                  className={inputClass}
                  placeholder={t($ => $.productIdPlaceholder)}
                  value={line.product_id}
                  onChange={(e) => handleInventoryLineChange(i, { product_id: e.target.value })}
                />
                <input
                  type="number"
                  className={inputClass}
                  placeholder={t($ => $.quantityPlaceholder)}
                  value={line.quantity || ''}
                  onChange={(e) => handleInventoryLineChange(i, { quantity: Number(e.target.value) })}
                />
                <input
                  type="number"
                  className={inputClass}
                  placeholder={t($ => $.unitCostPlaceholder)}
                  value={line.unit_cost || ''}
                  onChange={(e) => handleInventoryLineChange(i, { unit_cost: Number(e.target.value) })}
                />
              </div>
            ))}
            <div className="flex gap-2">
              <Button variant="outline" onClick={handleAddInventoryLine}>{t($ => $.addLine)}</Button>
              <Button onClick={handleEstablishInventory} disabled={openingInventory.isPending}>
                {t($ => $.establishInventoryButton)}
              </Button>
            </div>
          </section>

          {/* Step 8: Go-Live readiness / activation. The two bullets are descriptive — the backend
              (ActivateGoLiveAction) is the sole authority that actually enforces them; this page
              never duplicates that check, only states it so the operator knows what's about to be
              verified before they click. The Cash/Bank line below is NOT descriptive: it is the
              real `status.cash_bank_opening_blocked` fact from the backend (TASK-...-026-R1 Gate
              4), so unlike the other two it also disables the button directly here rather than
              only surfacing as a rejected request after the click. */}
          <section className={cardClass}>
            <h2 className="font-medium">{t($ => $.activateTitle)}</h2>
            <ul className="list-disc ps-5 text-sm text-muted-foreground">
              <li>{t($ => $.readinessNoUnfinishedReset)}</li>
              <li>{t($ => $.readinessWooCutoverResolved)}</li>
            </ul>
            {status?.cash_bank_opening_blocked && (
              <div className="rounded-md bg-rose-50 p-3 text-sm text-rose-700">
                <p className="font-medium">{t($ => $.cashBankOpeningBlockedTitle)}</p>
                <p>{status.cash_bank_opening_message}</p>
              </div>
            )}
            <p className="text-sm text-muted-foreground">{t($ => $.activateDescription)}</p>
            <Button
              variant="destructive"
              onClick={() => activate.mutate()}
              disabled={activate.isPending || Boolean(status?.cash_bank_opening_blocked)}
            >
              {t($ => $.activateButton)}
            </Button>
            {activate.isError && (
              <p className="text-sm text-rose-700">{(activate.error as Error)?.message}</p>
            )}
          </section>
        </>
      )}
    </div>
  );
}
