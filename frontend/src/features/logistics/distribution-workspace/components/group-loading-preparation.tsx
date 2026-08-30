import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Check, Loader2, PackageSearch, Pencil, X } from 'lucide-react';

import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useToast } from '@/components/ds/use-toast';
import { cn } from '@/lib/utils';

import {
  useGroupRequiredProducts,
  useSetGroupPrepared,
} from '../hooks/use-distribution-workspace';
import type { GroupRequiredProduct, SlotSummary } from '../types';

/**
 * LP-1 — LOADING PREPARATION for ONE Distribution Group.
 *
 * ┌─ WHAT THIS SCREEN ANSWERS ───────────────────────────────────────────────┐
 * │ "Which products, and how many of each, does THIS Distribution Group need  │
 * │  right now, so the warehouse can start separating them before a Vehicle   │
 * │  and Driver are known?"                                                   │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * THREE RULES GOVERN EVERY NUMBER BELOW.
 *
 *   1. NOTHING IS CALCULATED HERE. Every quantity is the server's, from the
 *      canonical `productAggregation` read model — the same one the Group
 *      rollups come from. This component performs no summing, no filtering and
 *      no re-derivation, so a second quantity engine cannot come into existence
 *      in the client.
 *
 *   2. PREPARED IS THE GROUP'S OWN FACT — not Preparation's, borrowed.
 *      `wave_product_demand.prepared_qty` is per WAVE + PRODUCT and cannot be
 *      attributed to a Group (two Groups needing the same product would share
 *      one number with no rule for dividing it). So LP-2 does not divide it —
 *      it records a SEPARATE quantity that the Group owns, declared by the
 *      operator who separated the goods. The two are never summed or compared;
 *      `preparedNote` says so on screen.
 *
 *   3. IT RECORDS FLOOR WORK — IT DOES NOT MOVE STOCK. Setting Prepared
 *      reserves nothing, consumes nothing, creates no loading task and touches
 *      no Order status. Inventory was already reserved upstream, when
 *      Preparation moved the order to ready_for_dispatch.
 *
 *   4. REQUIRED STILL CHANGES UNDER IT. Required is live, so the moment the
 *      Group's membership changes it re-derives — while Prepared stays exactly
 *      as the operator left it. Nothing is auto-transferred between Groups and
 *      no historical allocation is invented.
 *
 * LIVENESS: the query lives under the workspace's single query-key root, so the
 * seven existing Group mutations already refresh it. No mutation was modified,
 * no polling was added and no second synchronisation mechanism exists.
 */
export function GroupLoadingPreparation({
  windowId,
  group,
  warehouseNames,
  open,
}: {
  windowId: string;
  group: SlotSummary;
  /** Display names only — OWNERSHIP is the id, which the backend enforces. */
  warehouseNames: Record<string, string>;
  /** Fetch only while the panel is actually open. */
  open: boolean;
}) {
  const { t } = useTranslation('logistics');

  const query = useGroupRequiredProducts(windowId, group.slot_id, group.warehouse_id, open);
  const products = query.data ?? [];

  const columns = useMemo<DataGridColumnDef<GroupRequiredProduct>[]>(
    () => [
      {
        key: 'product',
        label: t(($) => $.distributionWorkspace.loadingPreparation.columns.product),
        alwaysVisible: true,
        cell: (p) => <span className="font-medium">{p.product_name ?? '—'}</span>,
      },
      {
        key: 'sku',
        label: t(($) => $.distributionWorkspace.loadingPreparation.columns.sku),
        // "SKU where available" — a product without one shows a dash rather
        // than a fabricated code.
        cell: (p) => (
          <span className="text-xs text-muted-foreground" dir="ltr">
            {p.product_sku ?? '—'}
          </span>
        ),
      },
      {
        key: 'required',
        label: t(($) => $.distributionWorkspace.loadingPreparation.columns.required),
        align: 'end',
        cell: (p) => (
          <span className="font-semibold tabular-nums">{p.total_quantity.toLocaleString()}</span>
        ),
      },
      {
        key: 'prepared',
        label: t(($) => $.distributionWorkspace.loadingPreparation.columns.prepared),
        align: 'end',
        // The ONLY editable cell on this screen. Everything else is a projection.
        cell: (p) => (
          <PreparedCell
            product={p}
            windowId={windowId}
            slotId={group.slot_id}
            groupCode={group.code}
          />
        ),
      },
      {
        key: 'remaining',
        label: t(($) => $.distributionWorkspace.loadingPreparation.columns.remaining),
        align: 'end',
        // DERIVED BY THE SERVER — max(0, required − prepared). Not recomputed
        // here, so this column cannot disagree with the two beside it.
        cell: (p) => (
          <span className="flex flex-col items-end">
            <span
              className={cn(
                'font-semibold tabular-nums',
                p.remaining_qty === 0 && 'text-emerald-600 dark:text-emerald-400',
              )}
            >
              {p.remaining_qty.toLocaleString()}
            </span>
            {/* Remaining is floored at zero, so without this an over-prepared
                row would read "0 — nothing to do" while the pallet holds more
                than the Group needs. It appears only when Required has fallen
                under an already-prepared quantity. */}
            {p.over_prepared_qty > 0 ? (
              <span className="text-[10px] font-medium text-amber-600 dark:text-amber-400">
                {t(($) => $.distributionWorkspace.loadingPreparation.overPrepared, {
                  qty: p.over_prepared_qty.toLocaleString(),
                })}
              </span>
            ) : null}
          </span>
        ),
      },
      {
        key: 'unit',
        label: t(($) => $.distributionWorkspace.loadingPreparation.columns.unit),
        // The unit travels with the quantity from the same server row. A product
        // with no unit shows nothing, never a guessed one.
        cell: (p) => (
          <span className="text-xs text-muted-foreground">
            {p.unit_symbol ?? p.unit_code ?? '—'}
          </span>
        ),
      },
    ],
    [t, windowId, group.slot_id, group.code],
  );

  // The ONLY derived value on this screen, and it is a subtraction of two server
  // fields — not a second count. `orders_count` is the Group's canonical
  // read-model count (D-2), the same figure the Group card headline shows.
  const remainingCapacity =
    group.capacity_orders === null ? null : group.capacity_orders - group.orders_count;

  return (
    <div className="mt-3 border-t pt-3" data-testid={`group-loading-preparation-${group.code}`}>
      <div className="flex items-center gap-2">
        <PackageSearch className="size-4 text-muted-foreground" aria-hidden />
        <h4 className="text-sm font-semibold">
          {t(($) => $.distributionWorkspace.loadingPreparation.title)}
        </h4>
      </div>

      <p className="mt-1 text-xs text-muted-foreground">
        {t(($) => $.distributionWorkspace.loadingPreparation.caption)}
      </p>

      {/* ── Group context ──────────────────────────────────────────────────
          Every field is the server's own Group summary. Nothing is recomputed
          here, so this strip can never disagree with the card above it. */}
      <dl
        className="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-3 lg:grid-cols-5"
        data-testid={`group-loading-context-${group.code}`}
      >
        <ContextField
          label={t(($) => $.distributionWorkspace.groups.warehouse)}
          value={warehouseNames[group.warehouse_id] ?? group.warehouse_id}
        />
        <ContextField
          label={t(($) => $.distributionWorkspace.metrics.zones)}
          value={group.zone_names.length > 0 ? group.zone_names.join(' · ') : '—'}
        />
        <ContextField
          label={t(($) => $.distributionWorkspace.metrics.orders)}
          value={group.orders_count}
        />
        <ContextField
          label={t(($) => $.distributionWorkspace.loadingPreparation.maxOrders)}
          value={
            group.capacity_orders ??
            t(($) => $.distributionWorkspace.loadingPreparation.notLimited)
          }
        />
        <ContextField
          label={t(($) => $.distributionWorkspace.loadingPreparation.remainingCapacity)}
          value={
            remainingCapacity ??
            t(($) => $.distributionWorkspace.loadingPreparation.notLimited)
          }
        />
      </dl>

      <div className="mt-3">
        <UniversalDataGrid
          data={products}
          columns={columns}
          rowId={(p) => p.product_id}
          loading={query.isLoading}
          error={query.isError}
          emptyState={
            <div className="p-6 text-center text-sm text-muted-foreground">
              {/* A Group with no required products is a legitimate state — an
                  empty Group, or one whose orders all became ineligible. It is
                  reported as such, never filled with placeholder rows. */}
              {t(($) => $.distributionWorkspace.loadingPreparation.empty)}
            </div>
          }
        />
      </div>

      {query.isError ? (
        <p className="mt-2 text-sm text-destructive">
          {t(($) => $.distributionWorkspace.loadingPreparation.loadFailed)}
        </p>
      ) : null}

      {/* Said out loud, because a missing column is otherwise read as a missing
          number rather than a deliberate refusal to invent one. */}
      <p className="mt-3 text-xs text-muted-foreground">
        {t(($) => $.distributionWorkspace.loadingPreparation.preparedNote)}
      </p>
    </div>
  );
}

/** Same fixed width in both display and edit mode, so switching cannot shift the column. */
const CELL_WIDTH = 'inline-flex items-center justify-end gap-1 w-[9rem]';

/**
 * The one editable cell: how much of this Product this Group has prepared.
 *
 * Modelled on the platform's existing inline-numeric precedent
 * (`ExpectedIncomingCell`, wave-missing-materials-page.tsx) rather than a new
 * interaction: same `type="text"` + `inputMode="decimal"` (a native number spinner
 * looks nothing like the rest of the app), same explicit commit — the check button
 * or Enter, never onBlur, so clicking into a cell and away can never save — and the
 * same Escape/X discard.
 *
 * ONE PRODUCT AT A TIME. There is no "save all": each commit is its own atomic,
 * absolute-set request, so a failure on one row cannot half-apply a batch.
 *
 * FRONTEND VALIDATION IS UX ONLY. The `0 <= qty <= required` check below stops an
 * obviously bad keystroke; the authoritative rule is re-evaluated on the server
 * inside a transaction, under the Group's row lock, against LIVE Required — which
 * may already have moved under this stale copy. A 422 from that check is surfaced
 * verbatim rather than swallowed.
 */
function PreparedCell({
  product,
  windowId,
  slotId,
  groupCode,
}: {
  product: GroupRequiredProduct;
  windowId: string;
  slotId: string;
  groupCode: string;
}) {
  const { t } = useTranslation('logistics');
  const { toast } = useToast();
  const save = useSetGroupPrepared();

  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState('');
  const savedTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [justSaved, setJustSaved] = useState(false);

  useEffect(
    () => () => {
      if (savedTimer.current) clearTimeout(savedTimer.current);
    },
    [],
  );

  const label = t(($) => $.distributionWorkspace.loadingPreparation.columns.prepared);

  function open() {
    setJustSaved(false);
    setDraft(String(product.prepared_qty));
    setEditing(true);
  }

  function cancel() {
    setEditing(false);
    setDraft('');
  }

  function commit() {
    const parsed = Number(draft);

    if (!Number.isFinite(parsed) || parsed < 0 || parsed > product.total_quantity) {
      toast({
        title: t(($) => $.distributionWorkspace.loadingPreparation.invalid),
        variant: 'destructive',
      });
      return;
    }

    setEditing(false);

    // Absolute set: re-sending the value already stored is a no-op server-side, so
    // skipping it here is an optimisation, not the safety mechanism.
    if (parsed === product.prepared_qty) return;

    save.mutate(
      { windowId, slotId, productId: product.product_id, preparedQty: parsed },
      {
        onSuccess: () => {
          setJustSaved(true);
          if (savedTimer.current) clearTimeout(savedTimer.current);
          savedTimer.current = setTimeout(() => setJustSaved(false), 2500);
        },
        onError: (error: unknown) => {
          // The server's own message — "Prepared quantity (99) cannot exceed the
          // quantity this group requires (2)" — is far more useful than a generic
          // failure, so it is shown when present.
          const message =
            (error as { response?: { data?: { message?: string } } })?.response?.data?.message ??
            t(($) => $.distributionWorkspace.loadingPreparation.saveFailed);

          toast({ title: message, variant: 'destructive' });
        },
      },
    );
  }

  if (editing) {
    return (
      <span className={CELL_WIDTH}>
        <Input
          autoFocus
          type="text"
          inputMode="decimal"
          value={draft}
          aria-label={label}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') commit();
            if (e.key === 'Escape') cancel();
          }}
          className="h-7 w-[4.5rem] text-end tabular-nums"
          data-testid={`prepared-input-${groupCode}-${product.product_id}`}
        />
        <Button
          size="sm"
          variant="ghost"
          className="h-7 w-7 shrink-0 p-0 text-emerald-600 hover:text-emerald-700 dark:text-emerald-400"
          onClick={commit}
          aria-label={t(($) => $.distributionWorkspace.loadingPreparation.save)}
          title={t(($) => $.distributionWorkspace.loadingPreparation.save)}
          data-testid={`prepared-save-${groupCode}-${product.product_id}`}
        >
          <Check className="size-3.5" />
        </Button>
        <Button
          size="sm"
          variant="ghost"
          className="h-7 w-7 shrink-0 p-0 text-muted-foreground"
          onClick={cancel}
          aria-label={t(($) => $.distributionWorkspace.loadingPreparation.cancel)}
          title={t(($) => $.distributionWorkspace.loadingPreparation.cancel)}
        >
          <X className="size-3.5" />
        </Button>
      </span>
    );
  }

  return (
    <span className={CELL_WIDTH}>
      <button
        type="button"
        onClick={open}
        disabled={save.isPending}
        title={t(($) => $.distributionWorkspace.loadingPreparation.editHint)}
        aria-label={label}
        className={cn(
          'inline-flex h-7 min-w-[4.5rem] items-center justify-between gap-1.5 rounded-md border border-input bg-background px-2 transition-colors hover:border-primary/50 hover:bg-accent disabled:opacity-50',
          justSaved && 'border-emerald-500 text-emerald-700 dark:text-emerald-400',
        )}
        data-testid={`prepared-open-${groupCode}-${product.product_id}`}
      >
        <span className="font-semibold tabular-nums">
          {product.prepared_qty.toLocaleString()}
        </span>
        <Pencil className="size-3 opacity-50" aria-hidden />
      </button>
      {save.isPending ? (
        <Loader2 className="size-3 shrink-0 animate-spin text-muted-foreground" aria-hidden />
      ) : null}
    </span>
  );
}

function ContextField({ label, value }: { label: string; value: string | number }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="font-medium tabular-nums">{value}</dd>
    </div>
  );
}
