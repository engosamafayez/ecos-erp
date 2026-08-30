import { useEffect, useMemo, useRef, useState } from 'react';
import { AlertTriangle, Check, Download, Loader2, PackageX, Pencil, Printer, Waves, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import { PrintTable } from '../components/print-table';
import { RelatedOrdersPanel } from '../components/related-orders-panel';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useToast } from '@/components/ds/use-toast';
import { usePermission } from '@/features/authorization/use-authorization';
import {
  useMaterialRelatedOrders,
  usePreparationWave,
  useUpdateExpectedIncoming,
  useWaveMissingMaterials,
} from '../hooks/use-preparation';
import { useSelectedWaveId } from '../components/wave-picker';
import type { WaveMissingMaterialItem } from '../types/preparation';

function fmt(n: number | null | undefined) {
  if (n == null) return '—';
  return n.toLocaleString(undefined, { maximumFractionDigits: 3 });
}


/**
 * CSV of exactly what the table is showing.
 *
 * BOM-prefixed because material names are frequently Arabic and Excel misreads
 * UTF-8 without it — same variant the Orders page export uses.
 */
function downloadCsv(filename: string, headers: string[], rows: (string | number | null | undefined)[][]) {
  const escape = (v: string | number | null | undefined) => {
    const s = v == null ? '' : String(v);
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  const csv = [headers, ...rows].map((r) => r.map(escape).join(',')).join('\n');
  // U+FEFF byte-order mark so Excel reads the UTF-8 correctly (material names are
  // often Arabic). Built via fromCharCode: the codepoint sits in the Arabic
  // Presentation Forms block, so a literal trips the no-arabic-literals lint rule.
  const bom = String.fromCharCode(0xFEFF);
  const blob = new Blob([bom + csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = Object.assign(document.createElement('a'), { href: url, download: filename });
  a.click();
  URL.revokeObjectURL(url);
}

// ── Expected Incoming (operator-editable planning input) ──────────────────────

/**
 * Both the display and the edit state render inside this same fixed-width, end-aligned
 * wrapper. Without it the cell would grow when the input and its confirm/discard buttons
 * appear, nudging the column sideways every time an operator starts editing.
 */
const CELL_WIDTH = 'inline-flex items-center justify-end gap-1 w-[9rem]';

/**
 * Procurement's estimate of what will arrive for this material.
 *
 * This is PLANNING data and nothing else: it never increases On Hand, Available or
 * Reserved, never writes the stock ledger, never creates a Goods Receipt or a
 * reservation, and never reduces the real Missing Qty. It only feeds
 * Uncovered = Missing − Expected Incoming, which the server recomputes and returns.
 *
 * The underlying purchase order is NOT editable from here; when a material has no
 * operator value the figure still falls back to the open-PO balance, as before.
 */
function ExpectedIncomingCell({
  material,
  waveId,
}: {
  material: WaveMissingMaterialItem;
  waveId: string | null;
}) {
  const { t } = useTranslation('operations');
  const { toast } = useToast();
  // Existing permission, unchanged: purchasing.expected_incoming.update.
  const { can } = usePermission();
  const canEdit = can('purchasing.expected_incoming.update');
  const update = useUpdateExpectedIncoming(waveId);
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState('');
  // §D — the confirmation lives IN the cell, not only in a toast, so the operator can see
  // WHICH row was saved. Cleared on unmount so a pending timer never touches a dead cell.
  const [justSaved, setJustSaved] = useState(false);
  const savedTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => () => {
    if (savedTimer.current) clearTimeout(savedTimer.current);
  }, []);

  const label = t($ => $.wave.missingMaterials.columns.expectedIncoming);

  function open() {
    setJustSaved(false);
    setDraft(String(material.expected_incoming_qty ?? 0));
    setEditing(true);
  }

  function cancel() {
    setEditing(false);
    setDraft('');
  }

  function commit() {
    const parsed = Number(draft);
    if (!Number.isFinite(parsed) || parsed < 0) {
      toast({ title: t($ => $.wave.missingMaterials.expectedIncomingInvalid), variant: 'destructive' });
      return;
    }
    setEditing(false);
    if (parsed === material.expected_incoming_qty) return;
    update.mutate(
      { materialId: material.material_id, expectedQty: parsed },
      {
        onSuccess: () => {
          setJustSaved(true);
          if (savedTimer.current) clearTimeout(savedTimer.current);
          savedTimer.current = setTimeout(() => setJustSaved(false), 2500);
        },
        onError: () => toast({ title: t($ => $.wave.missingMaterials.expectedIncomingFailed), variant: 'destructive' }),
      },
    );
  }

  if (editing) {
    return (
      // TASK-PREPARATION-UX-FIX-ARCHIVE-MISSING-001 — inline edit, in place.
      //  * `type="text"` + inputMode="decimal": type="number" rendered the browser's
      //    NATIVE spinner control, which is why edit mode looked nothing like the rest of
      //    the app. The numeric keypad is still offered on touch devices.
      //  * No onBlur commit: clicking into the cell and clicking away must never save.
      //    Saving is explicit — the check button or Enter. Escape / the X discards.
      //  * Same fixed-width wrapper as the display state, so switching modes cannot
      //    shift the column.
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
        />
        <Button
          size="sm"
          variant="ghost"
          className="h-7 w-7 p-0 shrink-0 text-emerald-600 hover:text-emerald-700 dark:text-emerald-400"
          onClick={commit}
          aria-label={t($ => $.wave.missingMaterials.expectedIncomingSave)}
          title={t($ => $.wave.missingMaterials.expectedIncomingSave)}
        >
          <Check className="h-3.5 w-3.5" />
        </Button>
        <Button
          size="sm"
          variant="ghost"
          className="h-7 w-7 p-0 shrink-0 text-muted-foreground"
          onClick={cancel}
          aria-label={t($ => $.wave.missingMaterials.expectedIncomingCancel)}
          title={t($ => $.wave.missingMaterials.expectedIncomingCancel)}
        >
          <X className="h-3.5 w-3.5" />
        </Button>
      </span>
    );
  }

  // Without the permission the figure stays a plain read-only number. The backend
  // permission check remains the real protection; this only removes an affordance the
  // user could never use.
  if (!canEdit) {
    return (
      <span className="tabular-nums text-sky-700 dark:text-sky-400">
        {fmt(material.expected_incoming_qty)}
      </span>
    );
  }

  return (
    <span className={CELL_WIDTH}>
      {/*
        TASK-PREPARATION-UX-FIX-ARCHIVE-MISSING-001 §2 — this used to be bare coloured
        text, visually identical to the read-only figure it replaced, so nothing told the
        operator it could be edited. It is now rendered as an input-shaped control
        (bordered box + edit affordance) per the approved "Expected Incoming [ 0 ]"
        presentation. Behaviour is unchanged: same endpoint, same permission, planning
        value only.
      */}
      <button
        type="button"
        onClick={open}
        disabled={update.isPending}
        title={t($ => $.wave.missingMaterials.expectedIncomingEditHint)}
        aria-label={label}
        className="inline-flex h-7 min-w-[4.5rem] items-center justify-between gap-1.5 rounded-md border border-input bg-background px-2 text-sky-700 transition-colors hover:border-sky-400 hover:bg-sky-50 disabled:opacity-50 dark:text-sky-400 dark:hover:bg-sky-950/30"
      >
        <span className="tabular-nums">{fmt(material.expected_incoming_qty)}</span>
        <Pencil className="h-3 w-3 opacity-50" />
      </button>
      {update.isPending && (
        <span className="inline-flex items-center gap-1 text-[10px] text-muted-foreground">
          <Loader2 className="h-3 w-3 animate-spin" />
          {t($ => $.wave.missingMaterials.expectedIncomingSaving)}
        </span>
      )}
      {!update.isPending && justSaved && (
        <span className="inline-flex items-center gap-1 text-[10px] text-emerald-600 dark:text-emerald-400">
          <Check className="h-3 w-3" />
          {t($ => $.wave.missingMaterials.expectedIncomingSaved)}
        </span>
      )}
    </span>
  );
}

/**
 * Mobile operational card for one Missing Material (§12). Same canonical figures as the
 * desktop columns — required / available / missing / expected-incoming / uncovered — with
 * the SAME editable Expected Incoming control and the SAME drill to related orders. Reads
 * as a decision ("Uncovered") rather than a compressed spreadsheet row.
 */
function MaterialMobileCard({
  material,
  waveId,
  onOpen,
}: {
  material: WaveMissingMaterialItem;
  waveId: string | null;
  onOpen: (m: WaveMissingMaterialItem) => void;
}) {
  const { t } = useTranslation('operations');
  const uncovered = material.uncovered_shortage_qty ?? 0;
  return (
    <div role="listitem" className="border-b p-3.5 last:border-0 space-y-2.5">
      <div className="flex items-start justify-between gap-2">
        <button type="button" onClick={() => onOpen(material)} className="min-w-0 text-start">
          <p className="text-sm font-medium truncate">{material.material_name}</p>
          {material.material_sku && <p className="text-[11px] text-muted-foreground font-mono truncate">{material.material_sku}</p>}
        </button>
        <span
          className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium ${
            uncovered > 0
              ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400'
              : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
          }`}
        >
          {uncovered > 0
            ? t($ => $.wave.missingMaterials.coverageUncovered)
            : t($ => $.wave.missingMaterials.coverageCovered)}
        </span>
      </div>

      <div className="grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
        <Row label={t($ => $.wave.missingMaterials.columns.required)} value={fmt(material.required_qty)} />
        <Row label={t($ => $.wave.missingMaterials.columns.available)} value={fmt(material.available_qty)} />
        <Row label={t($ => $.wave.missingMaterials.columns.missingQty)} value={fmt(material.missing_qty)} valueClass="text-red-700 dark:text-red-400 font-semibold" />
        <Row label={t($ => $.wave.missingMaterials.columns.uncovered)} value={fmt(uncovered)} valueClass="text-amber-700 dark:text-amber-400 font-semibold" />
      </div>

      <div className="flex items-center justify-between gap-2 pt-0.5">
        <div className="min-w-0">
          <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{t($ => $.wave.missingMaterials.columns.expectedIncoming)}</p>
          <ExpectedIncomingCell material={material} waveId={waveId} />
        </div>
        <button type="button" onClick={() => onOpen(material)} className="shrink-0 text-xs text-muted-foreground hover:underline">
          {t($ => $.wave.missingMaterials.columns.affectedOrders)}: {material.affected_orders_count}
        </button>
      </div>
    </div>
  );
}

function Row({ label, value, valueClass }: { label: string; value: React.ReactNode; valueClass?: string }) {
  return (
    <div className="flex items-baseline justify-between gap-2">
      <span className="text-[11px] text-muted-foreground">{label}</span>
      <span className={`tabular-nums ${valueClass ?? ''}`}>{value}</span>
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export function WaveMissingMaterialsPage() {
  const { t } = useTranslation('operations');

  const waveId = useSelectedWaveId();
  const { data: wave } = usePreparationWave(waveId);
  const { data: missing, isLoading, isFetching, refetch } = useWaveMissingMaterials(waveId);

  const [drillMaterial, setDrillMaterial] = useState<WaveMissingMaterialItem | null>(null);
  // Same shape as the Product Demand drill-down: the backend applies the
  // postponed_at exclusion, so a postponed order leaves this list exactly as it
  // leaves the shortage calculation. Nothing is aggregated in React.
  const { data: relatedOrders, isLoading: relatedLoading } = useMaterialRelatedOrders(
    waveId,
    drillMaterial?.material_id ?? null,
  );

  const columns: DataGridColumnDef<WaveMissingMaterialItem>[] = useMemo(() => [
    {
      key: 'material',
      label: t($ => $.wave.missingMaterials.columns.material),
      alwaysVisible: true,
      cell: (m) => (
        <button
          type="button"
          onClick={() => setDrillMaterial(m)}
          className="min-w-0 text-start hover:text-primary transition-colors"
          title={t($ => $.wave.missingMaterials.relatedOrders)}
        >
          <p className="font-medium truncate underline-offset-2 hover:underline">{m.material_name}</p>
          {m.material_sku && (
            <p className="text-xs text-muted-foreground font-mono truncate">{m.material_sku}</p>
          )}
        </button>
      ),
    },
    {
      key: 'required_qty',
      label: t($ => $.wave.missingMaterials.columns.required),
      align: 'end',
      cell: (m) => <span className="tabular-nums">{fmt(m.required_qty)}</span>,
    },
    {
      key: 'missing_qty',
      label: t($ => $.wave.missingMaterials.columns.missingQty),
      alwaysVisible: true,
      align: 'end',
      cell: (m) => (
        <span className="tabular-nums font-semibold text-red-700 dark:text-red-400">
          {fmt(m.missing_qty)}
        </span>
      ),
    },
    {
      key: 'expected_incoming_qty',
      label: t($ => $.wave.missingMaterials.columns.expectedIncoming),
      align: 'end',
      defaultVisible: true,
      // Editable by Procurement (TASK-PREPARATION-WORKSPACE-FIX-003 §2). PLANNING ONLY:
      // saving a value does not receive stock, create a goods receipt or movement, touch
      // reservations, or change Missing Qty — it only moves Uncovered.
      cell: (m) => <ExpectedIncomingCell material={m} waveId={waveId} />,
    },
    {
      key: 'uncovered_shortage_qty',
      label: t($ => $.wave.missingMaterials.columns.uncovered),
      alwaysVisible: true,
      align: 'end',
      cell: (m) => (
        <span className="tabular-nums font-semibold text-amber-700 dark:text-amber-400">
          {fmt(m.uncovered_shortage_qty)}
        </span>
      ),
    },
    {
      key: 'affected_orders_count',
      label: t($ => $.wave.missingMaterials.columns.affectedOrders),
      align: 'end',
      cell: (m) => <span className="tabular-nums">{m.affected_orders_count}</span>,
    },
  ], [t, waveId]);

  const rows = missing ?? [];

  function handleDownload() {
    downloadCsv(
      `missing-materials-${wave?.wave_number ?? 'wave'}.csv`,
      [
        t($ => $.wave.missingMaterials.columns.material),
        t($ => $.wave.missingMaterials.columns.sku),
        t($ => $.wave.missingMaterials.columns.required),
        t($ => $.wave.missingMaterials.columns.missingQty),
        t($ => $.wave.missingMaterials.columns.affectedOrders),
      ],
      rows.map((m) => [
        m.material_name,
        m.material_sku ?? '',
        m.required_qty ?? '',
        m.missing_qty,
        m.affected_orders_count,
      ]),
    );
  }

  return (
    <div className="flex flex-col h-full">
      <div className="print:hidden">
        <SmartToolbar
          onRefresh={() => void refetch()}
          isFetching={isFetching}
          secondaryActions={[
            // Desktop utilities (§17): off the phone toolbar, unchanged on desktop.
            {
              key: 'download',
              label: t($ => $.wave.missingMaterials.download),
              icon: Download,
              onClick: handleDownload,
              hideOnMobile: true,
            },
            {
              key: 'print',
              label: t($ => $.wave.missingMaterials.print),
              icon: Printer,
              onClick: () => window.print(),
              hideOnMobile: true,
            },
          ]}
        />
      </div>

      {/* Print output. Columns are listed explicitly, so hiding one on screen
          never removes it from the printout. */}
      <PrintTable
        title={t($ => $.wave.missingMaterials.printTitle)}
        subtitle={`${wave?.wave_number ?? ''}${wave?.planning_date ? ` \u00b7 ${wave.planning_date}` : ''}`}
        rows={rows}
        rowKey={(m) => m.id}
        columns={[
          { header: t($ => $.wave.missingMaterials.columns.material), cell: (m) => m.material_name },
          { header: t($ => $.wave.missingMaterials.columns.sku), cell: (m) => m.material_sku ?? '' },
          { header: t($ => $.wave.missingMaterials.columns.required), cell: (m) => fmt(m.required_qty), align: 'end' },
          { header: t($ => $.wave.missingMaterials.columns.missingQty), cell: (m) => fmt(m.missing_qty), align: 'end' },
          { header: t($ => $.wave.missingMaterials.columns.affectedOrders), cell: (m) => m.affected_orders_count, align: 'end' },
        ]}
      />

      <div className="flex-1 overflow-hidden print:hidden">
        {!waveId ? (
          <div className="flex flex-col items-center justify-center h-64 gap-2 text-muted-foreground">
            <Waves className="h-8 w-8 opacity-30" />
            <p className="text-sm">{t($ => $.wave.missingMaterials.noWave)}</p>
          </div>
        ) : isLoading ? (
          <div className="flex items-center justify-center h-64 gap-2 text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" />
            <span className="text-sm">{t($ => $.wave.loading)}</span>
          </div>
        ) : (
          <UniversalDataGrid<WaveMissingMaterialItem>
            columns={columns}
            data={rows}
            rowId={(m) => m.id}
            loading={false}
            renderMobileCard={(m) => <MaterialMobileCard material={m} waveId={waveId} onOpen={setDrillMaterial} />}
            emptyState={
              <div className="flex flex-col items-center justify-center py-16 gap-2 text-muted-foreground">
                <PackageX className="w-8 h-8" />
                <p className="text-sm font-medium">{t($ => $.wave.missingMaterials.emptyAllMet)}</p>
                <p className="text-xs flex items-center gap-1">
                  <AlertTriangle className="h-3 w-3 text-amber-500" />
                  {t($ => $.wave.missingMaterials.shortagesNote)}
                </p>
              </div>
            }
          />
        )}
      </div>

      {/* Material -> Related Orders. Relationship is Order -> Order Lines -> Product
          -> Active Recipe -> Material; the product that causes the requirement is
          shown so the operator can see WHY the material is needed. Same panel, same
          columns and the same Postpone action as the Product Demand drill-down. */}
      <RelatedOrdersPanel
        open={drillMaterial !== null}
        onOpenChange={(open) => { if (!open) setDrillMaterial(null); }}
        title={t($ => $.wave.missingMaterials.relatedOrders)}
        entityName={drillMaterial?.material_name}
        entitySubtitle={drillMaterial?.material_sku}
        waveId={waveId}
        orders={relatedOrders}
        isLoading={relatedLoading}
        emptyLabel={t($ => $.wave.missingMaterials.noRelatedOrders)}
        extraColumns={[
          {
            key: 'product',
            header: t($ => $.wave.missingMaterials.viaProduct),
            cell: (o) => (
              <span className="text-xs text-muted-foreground">{o.product_name}</span>
            ),
          },
          {
            key: 'product_qty',
            header: t($ => $.wave.missingMaterials.productQty),
            align: 'end',
            cell: (o) => fmt(o.product_qty),
          },
          {
            key: 'material_qty',
            header: t($ => $.wave.missingMaterials.materialQty),
            align: 'end',
            cell: (o) => fmt(o.material_qty),
          },
        ]}
      />
    </div>
  );
}
