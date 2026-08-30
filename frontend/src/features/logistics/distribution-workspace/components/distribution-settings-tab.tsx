import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Info } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

import { useUpdateGroup } from '../hooks/use-distribution-workspace';
import type { SlotSummary } from '../types';

/**
 * Settings — Distribution Group capacity policy, editable.
 *
 * ONE CAPACITY AXIS: order count. `capacity_stops`, `capacity_weight_kg` and
 * `capacity_volume_m3` exist on the Group row and are deliberately NOT offered
 * here, because nothing in the system enforces them — surfacing them would invite
 * an operator to set a limit that silently does nothing. Vehicle capacity is a
 * different question and stays where it already is, at the assignment stage.
 *
 * REMAINING IS READ, NEVER COMPUTED HERE. `remaining_orders` arrives derived from
 * the server, from the same aggregate the write-path guard uses to enforce the
 * limit. Subtracting the two numbers locally would be a second definition of
 * headroom, and it could then disagree with the refusal the operator just got.
 */

/** A limit is optional; an empty box means "no maximum", which is not zero. */
function parseLimit(raw: string): number | null {
  const trimmed = raw.trim();
  if (trimmed === '') return null;

  const value = Number(trimmed);
  return Number.isInteger(value) && value >= 1 ? value : Number.NaN;
}

function GroupCapacityRow({
  group,
  windowId,
}: {
  group: SlotSummary;
  windowId: string;
}) {
  const { t } = useTranslation('logistics');
  const update = useUpdateGroup();

  const stored = group.capacity_orders === null ? '' : String(group.capacity_orders);

  // Seeded once per mount. The row is KEYED on the stored value by its parent, so
  // when the server's number changes — another operator edited this group — the row
  // remounts with a fresh draft instead of showing a stale number as though it were
  // saved. That is the React-recommended reset, and it avoids a setState-in-effect
  // cascade on every refetch.
  const [draft, setDraft] = useState(stored);
  const [error, setError] = useState<string | null>(null);

  const parsed = parseLimit(draft);
  const invalid = Number.isNaN(parsed);
  const dirty = draft.trim() !== stored;
  // Client-side only as immediate feedback; the server re-checks under a row lock
  // and its refusal is the authority.
  const belowCurrent = !invalid && parsed !== null && parsed < group.orders_count;

  async function save() {
    setError(null);

    try {
      await update.mutateAsync({
        windowId,
        slotId: group.slot_id,
        payload: { capacity_orders: parsed },
      });
    } catch (e) {
      const message = (e as { response?: { data?: { message?: string } } })?.response?.data
        ?.message;
      setError(message ?? t(($) => $.distributionWorkspace.settings.saveFailed));
    }
  }

  return (
    <TableRow data-testid={`capacity-row-${group.code}`}>
      <TableCell className="font-medium">
        {group.code}
        {group.name ? <span className="text-muted-foreground"> — {group.name}</span> : null}
      </TableCell>

      <TableCell className="text-muted-foreground">{group.zones_count}</TableCell>

      <TableCell>
        <span className="font-medium">{group.orders_count}</span>
        {group.is_over_capacity ? (
          <Badge variant="destructive" className="ms-2">
            {t(($) => $.distributionWorkspace.settings.overCapacity, {
              count: group.overflow_orders,
            })}
          </Badge>
        ) : null}
      </TableCell>

      <TableCell>
        <div className="flex items-center gap-2">
          <Input
            type="number"
            min={1}
            step={1}
            inputMode="numeric"
            className="h-9 w-28"
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            placeholder={t(($) => $.distributionWorkspace.settings.unlimited)}
            aria-label={t(($) => $.distributionWorkspace.settings.maxOrdersLabel)}
            data-testid={`capacity-input-${group.code}`}
          />
          <Button
            size="sm"
            variant="outline"
            disabled={!dirty || invalid || belowCurrent || update.isPending}
            onClick={save}
            data-testid={`capacity-save-${group.code}`}
          >
            {update.isPending
              ? t(($) => $.distributionWorkspace.settings.saving)
              : t(($) => $.distributionWorkspace.settings.save)}
          </Button>
        </div>

        {belowCurrent ? (
          <p className="mt-1 text-xs text-destructive">
            {t(($) => $.distributionWorkspace.settings.belowCurrent, {
              count: group.orders_count,
            })}
          </p>
        ) : null}

        {error ? <p className="mt-1 text-xs text-destructive">{error}</p> : null}
      </TableCell>

      <TableCell>
        {group.remaining_orders === null ? (
          <span className="text-muted-foreground">
            {t(($) => $.distributionWorkspace.settings.notSet)}
          </span>
        ) : (
          <span className="font-medium">{group.remaining_orders}</span>
        )}
      </TableCell>
    </TableRow>
  );
}

export function DistributionSettingsTab({
  windowId,
  groups,
}: {
  windowId: string | undefined;
  groups: SlotSummary[];
}) {
  const { t } = useTranslation('logistics');

  return (
    <div className="space-y-4">
      <Card className="p-6" data-testid="distribution-settings">
        <h3 className="font-medium">{t(($) => $.distributionWorkspace.settings.title)}</h3>

        <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
          {t(($) => $.distributionWorkspace.settings.capacityExplainer)}
        </p>

        <div className="mt-4 flex items-start gap-2 rounded-lg border bg-muted/40 p-3">
          <Info className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
          <p className="text-sm text-muted-foreground">
            {t(($) => $.distributionWorkspace.settings.capacityNote)}
          </p>
        </div>

        <p className="mt-4 max-w-2xl text-sm text-muted-foreground">
          {t(($) => $.distributionWorkspace.settings.whereToEdit)}
        </p>
      </Card>

      <Card className="p-6">
        <h3 className="font-medium">{t(($) => $.distributionWorkspace.settings.groupsTitle)}</h3>
        <p className="mt-1 text-sm text-muted-foreground">
          {t(($) => $.distributionWorkspace.settings.groupsHint)}
        </p>

        {groups.length === 0 || !windowId ? (
          <p className="mt-4 text-sm text-muted-foreground" data-testid="capacity-empty">
            {t(($) => $.distributionWorkspace.settings.noGroups)}
          </p>
        ) : (
          <div className="mt-4 overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t(($) => $.distributionWorkspace.settings.colGroup)}</TableHead>
                  <TableHead>{t(($) => $.distributionWorkspace.settings.colZones)}</TableHead>
                  <TableHead>{t(($) => $.distributionWorkspace.settings.colOrders)}</TableHead>
                  <TableHead>{t(($) => $.distributionWorkspace.settings.colMax)}</TableHead>
                  <TableHead>{t(($) => $.distributionWorkspace.settings.colRemaining)}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {groups.map((group) => (
                  // The key carries the stored maximum on purpose: when the server's
                  // value changes the row remounts with a fresh draft, which is how
                  // the input stays in step without a setState-in-effect.
                  <GroupCapacityRow
                    key={`${group.slot_id}:${group.capacity_orders ?? 'none'}`}
                    group={group}
                    windowId={windowId}
                  />
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </Card>
    </div>
  );
}
