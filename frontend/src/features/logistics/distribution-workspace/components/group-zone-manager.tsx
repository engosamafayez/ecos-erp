import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, ArrowRightLeft, MapPin, Plus, X } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormatter } from '@/hooks/use-formatter';

import {
  useAddZoneToGroup,
  useGroupZoneBreakdown,
  useMoveZoneToGroup,
  useRemoveZoneFromGroup,
} from '../hooks/use-distribution-workspace';
import { ZoneImpactDialog, type ZoneAction } from './zone-impact-dialog';
import type { SlotSummary, ZoneImpactSummary, ZoneSummary } from '../types';

/**
 * Zone management inside one Distribution Group.
 *
 * THE THREE OPERATIONS AND THE ONE RULE THEY SHARE
 *
 *   Add     a Zone this warehouse has work in, and no group of this warehouse
 *           has claimed yet.
 *   Remove  the Zone leaves this group; its orders stop counting here. The
 *           orders themselves are not touched, and the Zone becomes unassigned
 *           for this warehouse — a first-class state, never hidden.
 *   Move    between two groups OF THE SAME WAREHOUSE, atomically.
 *
 * A ZONE IS GEOGRAPHY. It is not owned by a warehouse, so the same Zone can be
 * planned independently by two warehouses at once. Every list here is therefore
 * built from THIS warehouse's groups only: another warehouse's claim on the same
 * Zone is invisible and unaffected.
 *
 * Every mutation is validated again server-side. The filtering below exists to
 * keep the operator from asking for something impossible, not to enforce it.
 */
export function GroupZoneManager({
  windowId,
  group,
  allGroups,
  zones,
  canPlan,
}: {
  windowId: string;
  group: SlotSummary;
  /** Every group of THIS warehouse — the move destinations. */
  allGroups: SlotSummary[];
  /** Zone rollups for this warehouse and window. */
  zones: ZoneSummary[];
  canPlan: boolean;
}) {
  const { money } = useFormatter();

  const add = useAddZoneToGroup();
  const remove = useRemoveZoneFromGroup();
  const move = useMoveZoneToGroup();

  const [action, setAction] = useState<ZoneAction>('add');
  const [target, setTarget] = useState<ZoneImpactSummary | null>(null);
  const [destination, setDestination] = useState<SlotSummary | null>(null);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [pendingZoneId, setPendingZoneId] = useState<string>('');
  const [moveTo, setMoveTo] = useState<Record<number, string>>({});

  const zoneById = useMemo(() => {
    const map = new Map<number, ZoneSummary>();
    zones.forEach((z) => {
      if (z.zone_id !== null) map.set(z.zone_id, z);
    });
    return map;
  }, [zones]);

  /**
   * The zones this group holds, with their OWN Order stats — TASK-ECOS-
   * DISTRIBUTION-GROUP-DETAILS-CANONICAL-RECONCILIATION-009.
   *
   * Deliberately NOT derived from the window-wide `zones` prop above (that is
   * `zoneSummaries()` — a narrower eligibility predicate, no per-Slot filter,
   * and zones with zero currently-eligible Orders simply absent). This group's
   * own zone list must always match its header/card `zone_ids` exactly, and
   * its own Order stats must always sum to its own total — both guaranteed by
   * the server for this one call, not by cross-checking two window-wide reads
   * client-side. `zones`/`zoneById` stay in use below for `addable`/`claimed`,
   * which genuinely are window-wide questions ("what can still be added").
   */
  const zoneBreakdown = useGroupZoneBreakdown(windowId, group.slot_id);
  const memberZones = zoneBreakdown.data?.zones ?? [];
  const unclaimedOrders = zoneBreakdown.data?.unclaimed_zone_order_count ?? 0;

  /**
   * Addable zones: this warehouse has work there, and no group OF THIS WAREHOUSE
   * already holds it. Another warehouse holding it is irrelevant.
   */
  const claimed = useMemo(() => new Set(allGroups.flatMap((g) => g.zone_ids)), [allGroups]);
  const addable = useMemo(
    () => zones.filter((z) => z.zone_id !== null && !claimed.has(z.zone_id)),
    [zones, claimed],
  );

  const { t } = useTranslation('logistics');

  const otherGroups = useMemo(
    () => allGroups.filter((g) => g.slot_id !== group.slot_id),
    [allGroups, group.slot_id],
  );

  const mutation = action === 'add' ? add : action === 'remove' ? remove : move;
  const error =
    mutation.isError && mutation.error instanceof Error
      ? ((mutation.error as { response?: { data?: { message?: string } } }).response?.data
          ?.message ?? mutation.error.message)
      : null;

  function openDialog(next: ZoneAction, zone: ZoneImpactSummary, to?: SlotSummary) {
    add.reset();
    remove.reset();
    move.reset();
    setAction(next);
    setTarget(zone);
    setDestination(to ?? null);
    setDialogOpen(true);
  }

  function confirm() {
    if (!target?.zone_id) return;
    const zoneId = target.zone_id;

    const done = () => {
      setDialogOpen(false);
      setTarget(null);
      setDestination(null);
      setPendingZoneId('');
    };

    if (action === 'add') {
      add.mutate({ windowId, slotId: group.slot_id, zoneId }, { onSuccess: done });
    } else if (action === 'remove') {
      remove.mutate({ windowId, slotId: group.slot_id, zoneId }, { onSuccess: done });
    } else if (destination) {
      move.mutate(
        { windowId, fromSlotId: group.slot_id, toSlotId: destination.slot_id, zoneId },
        { onSuccess: done },
      );
    }
  }

  return (
    <div className="mt-3 border-t pt-3" data-testid={`group-zones-${group.code}`}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h4 className="text-sm font-semibold">
          {t(($) => $.distributionWorkspace.zoneManager.title)}
        </h4>

        {canPlan && addable.length > 0 ? (
          <div className="flex items-center gap-2">
            <Select value={pendingZoneId} onValueChange={setPendingZoneId}>
              <SelectTrigger className="h-8 w-52" data-testid={`add-zone-select-${group.code}`}>
                <SelectValue
                  placeholder={t(($) => $.distributionWorkspace.zoneManager.addPlaceholder)}
                />
              </SelectTrigger>
              <SelectContent>
                {addable.map((z) => (
                  <SelectItem key={z.zone_id} value={String(z.zone_id)}>
                    {t(($) => $.distributionWorkspace.zoneManager.addOption, {
                      name:
                        z.zone_name ??
                        t(($$) => $$.distributionWorkspace.zoneFallback, { id: z.zone_id }),
                      count: z.order_count,
                    })}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Button
              size="sm"
              variant="outline"
              disabled={!pendingZoneId}
              onClick={() => {
                const zone = zoneById.get(Number(pendingZoneId));
                if (zone) openDialog('add', zone);
              }}
              data-testid={`add-zone-${group.code}`}
            >
              <Plus className="me-1 size-3.5" aria-hidden />
              {t(($) => $.distributionWorkspace.zoneManager.add)}
            </Button>
          </div>
        ) : null}
      </div>

      {zoneBreakdown.isLoading ? (
        <Skeleton className="mt-2 h-16 w-full" data-testid={`group-zones-loading-${group.code}`} />
      ) : zoneBreakdown.isError ? (
        // A read failure is never rendered as "no zones yet" — that would be a
        // silent lie about a Group that may hold real Zones.
        <p className="mt-2 text-sm text-destructive" data-testid={`group-zones-error-${group.code}`}>
          {t(($) => $.distributionWorkspace.zoneManager.loadFailed)}
        </p>
      ) : (
        <>
          {memberZones.length === 0 ? (
            // An empty group is a legitimate state, not an error — it stays visible.
            <p className="mt-2 text-sm text-muted-foreground">
              {t(($) => $.distributionWorkspace.zoneManager.empty)}
            </p>
          ) : (
            <ul className="mt-2 space-y-2">
              {memberZones.map((zone) => (
                <li
                  key={zone.zone_id}
                  className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-2.5"
                  data-testid={`group-zone-row-${zone.zone_id}`}
                >
                  <div className="flex items-center gap-2">
                    <MapPin className="size-3.5 text-muted-foreground" aria-hidden />
                    <div>
                      <p className="text-sm font-medium">
                        {zone.zone_code ? `${zone.zone_code} — ` : ''}
                        {zone.zone_name ??
                          t(($) => $.distributionWorkspace.zoneFallback, { id: zone.zone_id })}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        {t(($) => $.distributionWorkspace.zoneManager.zoneStats, {
                          orders: zone.order_count,
                          products: zone.products_count,
                          value: money(zone.total_value),
                          paid: zone.paid_orders,
                          unpaid: zone.unpaid_orders,
                        })}
                      </p>
                    </div>
                  </div>

                  {canPlan ? (
                    <div className="flex items-center gap-2">
                      {otherGroups.length > 0 ? (
                        <>
                          <Select
                            value={moveTo[zone.zone_id] ?? ''}
                            onValueChange={(v) =>
                              setMoveTo((prev) => ({ ...prev, [zone.zone_id]: v }))
                            }
                          >
                            <SelectTrigger
                              className="h-8 w-44"
                              data-testid={`move-zone-select-${zone.zone_id}`}
                            >
                              <SelectValue
                                placeholder={t(
                                  ($) => $.distributionWorkspace.zoneManager.movePlaceholder,
                                )}
                              />
                            </SelectTrigger>
                            <SelectContent>
                              {otherGroups.map((g) => (
                                <SelectItem key={g.slot_id} value={g.slot_id}>
                                  {g.code}
                                  {g.name ? ` — ${g.name}` : ''}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                          <Button
                            size="sm"
                            variant="outline"
                            disabled={!moveTo[zone.zone_id]}
                            onClick={() => {
                              const to = otherGroups.find(
                                (g) => g.slot_id === moveTo[zone.zone_id],
                              );
                              if (to) openDialog('move', zone, to);
                            }}
                            data-testid={`move-zone-${zone.zone_id}`}
                          >
                            <ArrowRightLeft className="me-1 size-3.5" aria-hidden />
                            {t(($) => $.distributionWorkspace.zoneManager.move)}
                          </Button>
                        </>
                      ) : null}

                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => openDialog('remove', zone)}
                        data-testid={`remove-zone-${zone.zone_id}`}
                      >
                        <X className="me-1 size-3.5" aria-hidden />
                        {t(($) => $.distributionWorkspace.zoneManager.remove)}
                      </Button>
                    </div>
                  ) : (
                    <Badge variant="outline">
                      {t(($) => $.distributionWorkspace.zoneManager.planningClosed)}
                    </Badge>
                  )}
                </li>
              ))}
            </ul>
          )}

          {unclaimedOrders > 0 ? (
            // Orders that carry this Group's own membership but whose Zone is
            // not one of the Group's owned Zones — never hidden, never
            // force-fitted into one of the rows above (TASK-009 §7/§12).
            <p
              className="mt-2 flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-400"
              data-testid={`group-zones-unclaimed-${group.code}`}
            >
              <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden />
              {t(($) => $.distributionWorkspace.zoneManager.unclaimedOrders, {
                count: unclaimedOrders,
              })}
            </p>
          ) : null}
        </>
      )}

      <ZoneImpactDialog
        action={action}
        zone={target}
        group={group}
        destination={destination}
        open={dialogOpen}
        pending={mutation.isPending}
        error={error}
        onConfirm={confirm}
        onOpenChange={(open) => {
          setDialogOpen(open);
          if (!open) {
            setTarget(null);
            setDestination(null);
          }
        }}
      />
    </div>
  );
}
