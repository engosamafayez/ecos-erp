import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Info, Pencil, Plus, Trash2, Wand2 } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

import { useDistributionZones } from '@/features/logistics/distribution-zones/hooks/use-distribution-zones';
import { useDrivers } from '@/features/logistics/drivers/hooks/use-drivers';

import {
  useApplyGroupTemplate,
  useArchiveGroupTemplate,
  useGroupTemplates,
  useSaveGroupTemplate,
} from '../hooks/use-distribution-workspace';
import type { GroupTemplate } from '../types';

/**
 * Templates — reusable Distribution Group CONFIGURATION.
 *
 * A template is a name, a set of zones and a maximum order count. That is all it
 * can be: there is no orders field, no vehicle, no driver, no trip, no loading
 * state and no prepared quantity, because the table has no column for any of them.
 * Applying one creates a NEW group with those settings and copies nothing else —
 * orders arrive in the new group the same way they arrive in every group, because
 * its zones are attached to it.
 *
 * EDITABLE BEFORE CREATION. Apply opens a form pre-filled from the template so the
 * operator can change the name, the zones and the limit before the group exists.
 * The template supplies defaults, not decisions.
 *
 * The warehouse is NOT a template field. A group's owner is always chosen
 * explicitly, so it comes from the workspace's current warehouse selection and the
 * server re-verifies it against the tenant.
 */

/** Empty box means "no limit", which is not the same as a limit of zero. */
function parseLimit(raw: string): number | null {
  const trimmed = raw.trim();
  if (trimmed === '') return null;

  const value = Number(trimmed);
  return Number.isInteger(value) && value >= 1 ? value : Number.NaN;
}

function errorMessage(e: unknown, fallback: string): string {
  return (
    (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? fallback
  );
}

type ZoneOption = { id: number; label: string };

/**
 * Zone selector, split by whether the Zone is already spoken for.
 *
 * A Zone belongs to at most ONE Template per company, so the picker's job is not to
 * hide the taken ones — it is to say WHO has them. Hiding them would leave the operator
 * unable to explain why a Zone they can see on the Zones tab is absent here.
 *
 * Ownership is the SERVER'S map, arriving with the template list from the same method
 * that enforces the rule on save, so a Zone shown as free cannot be refused as taken.
 */
function ZonePicker({
  zones,
  selected,
  onToggle,
  ownership,
  currentTemplateId,
}: {
  zones: ZoneOption[];
  selected: number[];
  onToggle: (id: number) => void;
  /**
   * zone id -> owning template name, excluding this template.
   *
   * Optional because APPLYING a template creates a Group, and Group zone selection is
   * not constrained by which Template owns a Zone — exclusivity is a Template-
   * configuration rule. That call site passes nothing and behaves exactly as before.
   */
  ownership?: Map<number, string>;
  currentTemplateId?: string;
}) {
  const { t } = useTranslation('logistics');

  if (zones.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        {t(($) => $.distributionWorkspace.templates.noZones)}
      </p>
    );
  }

  const owned = ownership ?? new Map<number, string>();
  const free = zones.filter((z) => !owned.has(z.id));
  const taken = zones.filter((z) => owned.has(z.id));

  function row(z: ZoneOption, owner: string | undefined) {
    return (
      <label
        key={z.id}
        className="flex cursor-pointer items-start gap-2 rounded px-1.5 py-1 text-sm hover:bg-muted"
      >
        <Checkbox
          checked={selected.includes(z.id)}
          onCheckedChange={() => onToggle(z.id)}
          data-testid={`template-zone-${z.id}`}
        />
        <span className="flex flex-col">
          <span>{z.label}</span>
          {/* PART 6 — the operator must see WHERE it is used, not just that it is. */}
          {owner === undefined ? null : (
            <span className="text-xs text-amber-700 dark:text-amber-400" data-testid={`template-zone-owner-${z.id}`}>
              {t(($) => $.distributionWorkspace.templates.usedIn, { template: owner })}
            </span>
          )}
        </span>
      </label>
    );
  }

  return (
    <div className="max-h-56 space-y-2 overflow-y-auto rounded-md border p-2">
      <div>
        <p className="px-1.5 text-xs font-medium text-muted-foreground">
          {t(($) => $.distributionWorkspace.templates.availableZones)}
        </p>
        {free.length === 0 ? (
          <p className="px-1.5 py-1 text-xs text-muted-foreground">
            {t(($) => $.distributionWorkspace.templates.noneAvailable)}
          </p>
        ) : (
          free.map((z) => row(z, undefined))
        )}
      </div>

      {taken.length > 0 ? (
        <div className="border-t pt-2">
          <p className="px-1.5 text-xs font-medium text-muted-foreground">
            {t(($) => $.distributionWorkspace.templates.assignedZones)}
          </p>
          {taken.map((z) => row(z, owned.get(z.id)))}
        </div>
      ) : null}

      {currentTemplateId === undefined ? null : (
        <p className="px-1.5 pt-1 text-xs text-muted-foreground">
          {t(($) => $.distributionWorkspace.templates.ownZonesNote)}
        </p>
      )}
    </div>
  );
}

type DriverOption = { id: number; name: string; code: string; mobile: string };

/**
 * RECOMMENDED DRIVERS — an operator-chosen multi-select of SUGGESTIONS.
 *
 * ┌─ WHAT THIS IS, AND IS NOT ───────────────────────────────────────────────┐
 * │ The operator ticks the Drivers they want suggested for Groups made from    │
 * │ this template. It is metadata only: applying a template never copies these  │
 * │ into the Group, and the Group's Driver stays open.                        │
 * │                                                                          │
 * │ There is NO ranking, NO score and NO "best match" — the list is the        │
 * │ tenant's own eligible Drivers, plainly, and the operator decides. Order is  │
 * │ by name for findability, not as a quality signal.                        │
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * Drivers are the canonical `logistics_drivers` read (tenant-scoped by the server),
 * searchable by name, code and mobile — the fields already in that read model.
 */
function DriverPicker({
  drivers,
  selected,
  onToggle,
}: {
  drivers: DriverOption[];
  selected: number[];
  onToggle: (id: number) => void;
}) {
  const { t } = useTranslation('logistics');
  const [search, setSearch] = useState('');

  const selectedDrivers = drivers.filter((d) => selected.includes(d.id));

  const q = search.trim().toLowerCase();
  const filtered =
    q === ''
      ? drivers
      : drivers.filter((d) =>
          [d.name, d.code, d.mobile].some((v) => v.toLowerCase().includes(q)),
        );

  return (
    <div className="rounded-md border p-2" data-testid="template-recommended-drivers">
      <p className="text-xs font-medium">
        {t(($) => $.distributionWorkspace.templates.recommendedDrivers)}
      </p>
      <p className="mt-0.5 text-xs text-muted-foreground">
        {t(($) => $.distributionWorkspace.templates.recommendationNote)}
      </p>

      {drivers.length === 0 ? (
        <p className="mt-2 text-xs text-muted-foreground" data-testid="template-no-eligible-drivers">
          {t(($) => $.distributionWorkspace.templates.noEligibleDrivers)}
        </p>
      ) : (
        <>
          {/* Selected, shown clearly and individually removable. */}
          {selectedDrivers.length > 0 ? (
            <div className="mt-2 flex flex-wrap gap-1.5" data-testid="template-selected-drivers">
              {selectedDrivers.map((d) => (
                <button
                  key={d.id}
                  type="button"
                  onClick={() => onToggle(d.id)}
                  className="inline-flex items-center gap-1 rounded-full border bg-muted px-2 py-0.5 text-xs hover:bg-muted/70"
                  data-testid={`template-driver-chip-${d.id}`}
                >
                  <span>{d.name}</span>
                  <span aria-hidden>×</span>
                </button>
              ))}
            </div>
          ) : (
            <p className="mt-2 text-xs text-muted-foreground">
              {t(($) => $.distributionWorkspace.templates.noRecommendedDrivers)}
            </p>
          )}

          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t(($) => $.distributionWorkspace.templates.driverSearchPlaceholder)}
            className="mt-2 h-8 text-xs"
            data-testid="template-driver-search"
          />

          <div className="mt-1.5 max-h-40 space-y-0.5 overflow-y-auto">
            {filtered.length === 0 ? (
              <p className="px-1.5 py-1 text-xs text-muted-foreground">
                {t(($) => $.distributionWorkspace.templates.noDriverMatches)}
              </p>
            ) : (
              filtered.map((d) => (
                <label
                  key={d.id}
                  className="flex cursor-pointer items-start gap-2 rounded px-1.5 py-1 text-sm hover:bg-muted"
                >
                  <Checkbox
                    checked={selected.includes(d.id)}
                    onCheckedChange={() => onToggle(d.id)}
                    data-testid={`template-driver-${d.id}`}
                  />
                  <span className="flex flex-col">
                    <span>{d.name}</span>
                    <span className="text-xs text-muted-foreground">
                      {[d.code, d.mobile].filter(Boolean).join(' · ')}
                    </span>
                  </span>
                </label>
              ))
            )}
          </div>
        </>
      )}
    </div>
  );
}

function TemplateForm({
  template,
  zones,
  drivers,
  ownership,
  onDone,
  onCancel,
}: {
  /** undefined = creating. */
  template: GroupTemplate | undefined;
  zones: ZoneOption[];
  /** The tenant's eligible Drivers, for the Recommended Drivers multi-select. */
  drivers: DriverOption[];
  /** zone id -> owning template name, already excluding this template. */
  ownership: Map<number, string>;
  onDone: () => void;
  onCancel: () => void;
}) {
  const { t } = useTranslation('logistics');
  const save = useSaveGroupTemplate();
  // Set only by the confirmation below, and reset whenever the selection changes.
  const [moveConfirmed, setMoveConfirmed] = useState(false);

  const [name, setName] = useState(template?.name ?? '');
  const [limit, setLimit] = useState(
    template?.capacity_orders === null || template?.capacity_orders === undefined
      ? ''
      : String(template.capacity_orders),
  );
  const [zoneIds, setZoneIds] = useState<number[]>(template?.zone_ids ?? []);
  // Recommended Drivers — loaded from the template when editing; suggestions only.
  const [driverIds, setDriverIds] = useState<number[]>(template?.driver_ids ?? []);
  const [error, setError] = useState<string | null>(null);

  const parsed = parseLimit(limit);

  // Selected Zones that another Template owns. These are what a Move would take.
  const toMove = zoneIds
    .filter((id) => ownership.has(id))
    .map((id) => ({
      id,
      label: zones.find((z) => z.id === id)?.label ?? `#${id}`,
      from: ownership.get(id) ?? '',
    }));

  // PART 16 — saving is blocked until the operator either confirms the Move or drops
  // the contested Zones. The server refuses it anyway; this explains it first.
  const invalid =
    Number.isNaN(parsed) || name.trim() === '' || (toMove.length > 0 && !moveConfirmed);

  function toggleZone(id: number) {
    // Any change to the selection invalidates a previous confirmation — otherwise
    // ticking a second owned Zone would ride in on the first one's approval.
    setMoveConfirmed(false);
    setZoneIds((current) =>
      current.includes(id) ? current.filter((z) => z !== id) : [...current, id],
    );
  }

  function toggleDriver(id: number) {
    setDriverIds((current) =>
      current.includes(id) ? current.filter((d) => d !== id) : [...current, id],
    );
  }

  async function submit() {
    setError(null);

    try {
      await save.mutateAsync({
        templateId: template?.id,
        payload: {
          name: name.trim(),
          capacity_orders: parsed,
          zone_ids: zoneIds,
          // Recommended Drivers — suggestions only; the server stores the exact ids
          // and never applies them to a Group.
          driver_ids: driverIds,
          // Sent ONLY when there is something to move and the operator said yes.
          ...(toMove.length > 0 && moveConfirmed ? { move_zones: true } : {}),
        },
      });
      onDone();
    } catch (e) {
      setError(
        errorMessage(e, t(($) => $.distributionWorkspace.templates.saveFailed)),
      );
    }
  }

  return (
    <Card className="p-4" data-testid="template-form">
      <h4 className="font-medium">
        {template
          ? t(($) => $.distributionWorkspace.templates.editTitle)
          : t(($) => $.distributionWorkspace.templates.newTitle)}
      </h4>

      <div className="mt-3 grid gap-3 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="template-name">
            {t(($) => $.distributionWorkspace.templates.nameLabel)}
          </Label>
          <Input
            id="template-name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder={t(($) => $.distributionWorkspace.templates.namePlaceholder)}
            data-testid="template-name-input"
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="template-limit">
            {t(($) => $.distributionWorkspace.templates.maxOrdersLabel)}
          </Label>
          <Input
            id="template-limit"
            type="number"
            min={1}
            step={1}
            inputMode="numeric"
            value={limit}
            onChange={(e) => setLimit(e.target.value)}
            placeholder={t(($) => $.distributionWorkspace.templates.notSet)}
            data-testid="template-limit-input"
          />
          <p className="text-xs text-muted-foreground">
            {t(($) => $.distributionWorkspace.templates.maxOrdersHint)}
          </p>
        </div>
      </div>

      <div className="mt-3 space-y-1.5">
        <Label>{t(($) => $.distributionWorkspace.templates.zonesLabel)}</Label>
        <ZonePicker
          zones={zones}
          selected={zoneIds}
          onToggle={toggleZone}
          ownership={ownership}
          currentTemplateId={template?.id}
        />
        <p className="text-xs text-muted-foreground">
          {t(($) => $.distributionWorkspace.templates.zonesHint)}
        </p>

        {/*
          PART 7 — an explicit Move, never a silent steal. The dialog names the Zone,
          the Template losing it and the Template gaining it, and the operator has to
          tick it before Save is enabled.

          PART 12 — this moves CONFIGURATION only. No Order, Group, Trip, Driver,
          Vehicle or Loading row is touched, and Groups already created from either
          Template keep their own snapshot.
        */}
        {toMove.length > 0 ? (
          <div
            className="mt-2 rounded-md border border-amber-500/50 bg-amber-50/50 p-2 dark:bg-amber-950/20"
            data-testid="template-move-confirm"
          >
            <p className="text-xs font-medium text-amber-700 dark:text-amber-400">
              {t(($) => $.distributionWorkspace.templates.moveTitle, { count: toMove.length })}
            </p>
            <ul className="mt-1 space-y-0.5">
              {toMove.map((zone) => (
                <li key={zone.id} className="text-xs text-muted-foreground">
                  {t(($) => $.distributionWorkspace.templates.moveLine, {
                    zone: zone.label,
                    from: zone.from,
                    to: name.trim() === ''
                      ? t(($) => $.distributionWorkspace.templates.thisTemplate)
                      : name.trim(),
                  })}
                </li>
              ))}
            </ul>
            <p className="mt-1 text-xs text-muted-foreground">
              {t(($) => $.distributionWorkspace.templates.moveWarning)}
            </p>
            <label className="mt-1.5 flex cursor-pointer items-center gap-2 text-xs">
              <Checkbox
                checked={moveConfirmed}
                onCheckedChange={() => setMoveConfirmed((v) => !v)}
                data-testid="template-move-approve"
              />
              <span>{t(($) => $.distributionWorkspace.templates.moveApprove)}</span>
            </label>
          </div>
        ) : null}

        {/* Suggestions only — operator-chosen, stored as ids, never an assignment. */}
        <div className="mt-2">
          <DriverPicker drivers={drivers} selected={driverIds} onToggle={toggleDriver} />
        </div>
      </div>

      {error ? <p className="mt-2 text-sm text-destructive">{error}</p> : null}

      <div className="mt-4 flex gap-2">
        <Button onClick={submit} disabled={invalid || save.isPending} data-testid="template-save">
          {template
            ? t(($) => $.distributionWorkspace.templates.save)
            : t(($) => $.distributionWorkspace.templates.create)}
        </Button>
        <Button variant="ghost" onClick={onCancel}>
          {t(($) => $.distributionWorkspace.templates.cancel)}
        </Button>
      </div>
    </Card>
  );
}

function ApplyForm({
  template,
  zones,
  windowId,
  warehouseId,
  onDone,
  onCancel,
}: {
  template: GroupTemplate;
  zones: ZoneOption[];
  windowId: string | undefined;
  warehouseId: string | null;
  onDone: () => void;
  onCancel: () => void;
}) {
  const { t } = useTranslation('logistics');
  const apply = useApplyGroupTemplate();

  const [code, setCode] = useState('');
  const [name, setName] = useState(template.name);
  const [limit, setLimit] = useState(
    template.capacity_orders === null ? '' : String(template.capacity_orders),
  );
  const [zoneIds, setZoneIds] = useState<number[]>(template.zone_ids);
  const [error, setError] = useState<string | null>(null);

  const parsed = parseLimit(limit);
  const invalid = Number.isNaN(parsed) || code.trim() === '';

  if (!warehouseId) {
    return (
      <Card className="p-4">
        <p className="text-sm text-amber-700">
          {t(($) => $.distributionWorkspace.templates.applyNoWarehouse)}
        </p>
      </Card>
    );
  }

  if (!windowId) {
    return (
      <Card className="p-4">
        <p className="text-sm text-amber-700">
          {t(($) => $.distributionWorkspace.templates.applyNoWindow)}
        </p>
      </Card>
    );
  }

  // Captured AFTER the two guards above. `submit` is a hoisted declaration, so
  // TypeScript cannot carry the narrowing from those early returns into it —
  // these consts are what make the non-null contract explicit rather than
  // asserted away with `!`.
  const targetWindowId: string = windowId;
  const targetWarehouseId: string = warehouseId;

  async function submit() {
    setError(null);

    try {
      await apply.mutateAsync({
        windowId: targetWindowId,
        templateId: template.id,
        payload: {
          warehouse_id: targetWarehouseId,
          code: code.trim(),
          name: name.trim() === '' ? null : name.trim(),
          capacity_orders: parsed,
          zone_ids: zoneIds,
        },
      });
      onDone();
    } catch (e) {
      setError(errorMessage(e, t(($) => $.distributionWorkspace.templates.applyFailed)));
    }
  }

  return (
    <Card className="p-4" data-testid="template-apply-form">
      <h4 className="font-medium">
        {t(($) => $.distributionWorkspace.templates.applyTitle, { name: template.name })}
      </h4>
      <p className="mt-1 text-sm text-muted-foreground">
        {t(($) => $.distributionWorkspace.templates.applyHint)}
      </p>

      <div className="mt-3 grid gap-3 sm:grid-cols-3">
        <div className="space-y-1.5">
          <Label htmlFor="apply-code">
            {t(($) => $.distributionWorkspace.templates.applyCodeLabel)}
          </Label>
          <Input
            id="apply-code"
            value={code}
            onChange={(e) => setCode(e.target.value)}
            placeholder={t(($) => $.distributionWorkspace.templates.applyCodePlaceholder)}
            data-testid="apply-code-input"
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="apply-name">
            {t(($) => $.distributionWorkspace.templates.nameLabel)}
          </Label>
          <Input id="apply-name" value={name} onChange={(e) => setName(e.target.value)} />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="apply-limit">
            {t(($) => $.distributionWorkspace.templates.maxOrdersLabel)}
          </Label>
          <Input
            id="apply-limit"
            type="number"
            min={1}
            step={1}
            inputMode="numeric"
            value={limit}
            onChange={(e) => setLimit(e.target.value)}
            placeholder={t(($) => $.distributionWorkspace.templates.notSet)}
            data-testid="apply-limit-input"
          />
        </div>
      </div>

      <div className="mt-3 space-y-1.5">
        <Label>{t(($) => $.distributionWorkspace.templates.zonesLabel)}</Label>
        <ZonePicker
          zones={zones}
          selected={zoneIds}
          onToggle={(id) =>
            setZoneIds((prev) =>
              prev.includes(id) ? prev.filter((z) => z !== id) : [...prev, id],
            )
          }
        />
      </div>

      {error ? <p className="mt-2 text-sm text-destructive">{error}</p> : null}

      <div className="mt-4 flex gap-2">
        <Button onClick={submit} disabled={invalid || apply.isPending} data-testid="apply-submit">
          {t(($) => $.distributionWorkspace.templates.applyCreate)}
        </Button>
        <Button variant="ghost" onClick={onCancel}>
          {t(($) => $.distributionWorkspace.templates.cancel)}
        </Button>
      </div>
    </Card>
  );
}

export function DistributionTemplatesTab({
  windowId,
  warehouseId,
  active,
}: {
  windowId: string | undefined;
  warehouseId: string | null;
  active: boolean;
}) {
  const { t } = useTranslation('logistics');

  const { data: templateData, isLoading } = useGroupTemplates(active);
  const templates = templateData?.templates;

  /**
   * zone id -> owning template name, from the SERVER. Built once here and narrowed per
   * form, so the picker, the Move dialog and the table all read the same fact.
   */
  const ownershipByZone = new Map<number, { templateId: string; templateName: string }>(
    (templateData?.ownership ?? []).map((row) => [
      row.zone_id,
      { templateId: row.template_id, templateName: row.template_name },
    ]),
  );

  /** The same map with the template being edited removed — its own Zones are not conflicts. */
  function ownershipExcluding(templateId: string | undefined): Map<number, string> {
    const out = new Map<number, string>();

    ownershipByZone.forEach((owner, zoneId) => {
      if (owner.templateId !== templateId) {
        out.set(zoneId, owner.templateName);
      }
    });

    return out;
  }
  const archive = useArchiveGroupTemplate();

  // Zone options are the CANONICAL configured zones (`distribution_zones`), read
  // through the existing zone-management query — NOT the current window's
  // eligible-order rollup. A template is reusable company configuration, so it
  // must be able to reference every active zone regardless of whether that zone
  // happens to hold an eligible order in the window open right now. Sourcing the
  // picker from the window rollup was the bug: once a window's orders became
  // `ready_for_dispatch` the rollup emptied and the picker offered nothing.
  const { data: zonesResult } = useDistributionZones({ status: 'active', per_page: 100 });

  // The tenant's eligible Drivers for the Recommended Drivers picker — the canonical
  // `logistics_drivers` read, tenant-scoped by the server. Default status excludes
  // archived, matching what the server accepts as a recommendation.
  const { data: driversResult } = useDrivers({ per_page: 100 });

  const [mode, setMode] = useState<
    { kind: 'idle' } | { kind: 'edit'; template?: GroupTemplate } | { kind: 'apply'; template: GroupTemplate }
  >({ kind: 'idle' });
  const [error, setError] = useState<string | null>(null);

  // Active zones, newest label preference English → Arabic → code, matching the
  // Zones management screen. The backend validates each id against the same
  // `distribution_zones` table, so nothing offered here can be refused for not
  // existing.
  const zoneOptions: ZoneOption[] = (zonesResult?.data ?? []).map((z) => ({
    id: z.id,
    label: z.name_en ?? z.name_ar ?? z.code,
  }));

  const driverOptions: DriverOption[] = (driversResult?.data ?? []).map((d) => ({
    id: d.id,
    name: d.full_name,
    code: d.driver_code,
    mobile: d.mobile ?? '',
  }));

  /** Recommended driver names for the list cell: "Ahmed · Mohamed +2". */
  function driverNames(ids: number[]): { shown: string; extra: number } {
    const names = ids.map(
      (id) => driverOptions.find((d) => d.id === id)?.name ?? `#${id}`,
    );
    const shown = names.slice(0, 3);
    return { shown: shown.join(' · '), extra: Math.max(0, names.length - shown.length) };
  }

  async function onArchive(template: GroupTemplate) {
    setError(null);

    if (!window.confirm(t(($) => $.distributionWorkspace.templates.archiveConfirm))) return;

    try {
      await archive.mutateAsync(template.id);
    } catch (e) {
      setError(errorMessage(e, t(($) => $.distributionWorkspace.templates.archiveFailed)));
    }
  }

  return (
    <div className="space-y-4" data-testid="distribution-templates">
      <Card className="p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h3 className="font-medium">{t(($) => $.distributionWorkspace.templates.title)}</h3>
            <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
              {t(($) => $.distributionWorkspace.templates.subtitle)}
            </p>
          </div>

          <Button
            size="sm"
            onClick={() => setMode({ kind: 'edit', template: undefined })}
            data-testid="template-new"
          >
            <Plus className="me-1.5 size-4" aria-hidden />
            {t(($) => $.distributionWorkspace.templates.newButton)}
          </Button>
        </div>

        <div className="mt-4 flex items-start gap-2 rounded-lg border bg-muted/40 p-3">
          <Info className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
          <p className="text-sm text-muted-foreground">
            {t(($) => $.distributionWorkspace.templates.configOnly)}
          </p>
        </div>

        {error ? <p className="mt-3 text-sm text-destructive">{error}</p> : null}

        {isLoading ? (
          <Skeleton className="mt-4 h-32 w-full" />
        ) : (templates ?? []).length === 0 ? (
          <p className="mt-4 text-sm text-muted-foreground" data-testid="templates-empty">
            {t(($) => $.distributionWorkspace.templates.empty)}
          </p>
        ) : (
          <div className="mt-4 overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t(($) => $.distributionWorkspace.templates.colName)}</TableHead>
                  <TableHead>{t(($) => $.distributionWorkspace.templates.colZones)}</TableHead>
                  <TableHead>{t(($) => $.distributionWorkspace.templates.colMax)}</TableHead>
                  <TableHead>{t(($) => $.distributionWorkspace.templates.colDrivers)}</TableHead>
                  <TableHead />
                </TableRow>
              </TableHeader>
              <TableBody>
                {(templates ?? []).map((tpl) => (
                  <TableRow key={tpl.id} data-testid={`template-row-${tpl.name}`}>
                    <TableCell className="font-medium">{tpl.name}</TableCell>

                    <TableCell className="text-muted-foreground">
                      <span>
                        {t(($) => $.distributionWorkspace.templates.zonesCount, {
                          count: tpl.zones_count,
                        })}
                      </span>
                      {/* The Zones themselves, named — a count alone cannot be checked. */}
                      {tpl.zone_ids.length > 0 ? (
                        <span
                          className="mt-0.5 block text-xs"
                          data-testid={`template-zone-names-${tpl.name}`}
                        >
                          {tpl.zone_ids
                            .map((id) => zoneOptions.find((z) => z.id === id)?.label ?? `#${id}`)
                            .join(' · ')}
                        </span>
                      ) : null}
                    </TableCell>

                    <TableCell>
                      {tpl.capacity_orders === null ? (
                        <Badge variant="outline">
                          {t(($) => $.distributionWorkspace.templates.notSet)}
                        </Badge>
                      ) : (
                        tpl.capacity_orders
                      )}
                    </TableCell>

                    {/*
                      Recommended Drivers — SUGGESTIONS only, never assignments. The
                      names of the operator's chosen drivers, with "+N" when many. Empty
                      reads as "none selected", not as a failed calculation.
                    */}
                    <TableCell className="text-muted-foreground">
                      <span className="text-xs" data-testid={`template-drivers-${tpl.name}`}>
                        {(tpl.driver_ids ?? []).length === 0 ? (
                          t(($) => $.distributionWorkspace.templates.noRecommendedDrivers)
                        ) : (
                          <>
                            {driverNames(tpl.driver_ids ?? []).shown}
                            {driverNames(tpl.driver_ids ?? []).extra > 0
                              ? ' ' +
                                t(($) => $.distributionWorkspace.templates.plusMore, {
                                  count: driverNames(tpl.driver_ids ?? []).extra,
                                })
                              : ''}
                          </>
                        )}
                      </span>
                    </TableCell>

                    <TableCell className="text-end">
                      <div className="flex justify-end gap-1">
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => setMode({ kind: 'apply', template: tpl })}
                          data-testid={`template-apply-${tpl.name}`}
                        >
                          <Wand2 className="me-1.5 size-3.5" aria-hidden />
                          {t(($) => $.distributionWorkspace.templates.apply)}
                        </Button>
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => setMode({ kind: 'edit', template: tpl })}
                          aria-label={t(($) => $.distributionWorkspace.templates.edit)}
                          data-testid={`template-edit-${tpl.name}`}
                        >
                          <Pencil className="size-3.5" aria-hidden />
                        </Button>
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => onArchive(tpl)}
                          aria-label={t(($) => $.distributionWorkspace.templates.archive)}
                          data-testid={`template-archive-${tpl.name}`}
                        >
                          <Trash2 className="size-3.5" aria-hidden />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </Card>

      {mode.kind === 'edit' ? (
        <TemplateForm
          ownership={ownershipExcluding(mode.kind === 'edit' ? mode.template?.id : undefined)}
          template={mode.template}
          zones={zoneOptions}
          drivers={driverOptions}
          onDone={() => setMode({ kind: 'idle' })}
          onCancel={() => setMode({ kind: 'idle' })}
        />
      ) : null}

      {mode.kind === 'apply' ? (
        <ApplyForm
          template={mode.template}
          zones={zoneOptions}
          windowId={windowId}
          warehouseId={warehouseId}
          onDone={() => setMode({ kind: 'idle' })}
          onCancel={() => setMode({ kind: 'idle' })}
        />
      ) : null}
    </div>
  );
}
