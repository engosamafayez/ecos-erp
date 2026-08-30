import type { ReactNode } from 'react';

import { MobileDataCard, type MobileDataCardField } from './mobile-data-card';
import type { AutoCardColumn } from './types';

export type AutoDataCardProps<T> = {
  row: T;
  columns: AutoCardColumn<T>[];
  /** Row actions rendered in the card footer (e.g. an ActionMenu). */
  actions?: ReactNode;
  onOpen?: () => void;
  openLabel?: string;
  selected?: boolean;
  onSelect?: (checked: boolean) => void;
  selectLabel?: string;
  focused?: boolean;
};

/**
 * AutoDataCard — the conservative automatic mobile fallback.
 *
 * Turns a row + its column definitions into a `MobileDataCard` so a list with
 * no bespoke `renderMobileCard` still shows something legible on a phone
 * instead of an empty box. It is deliberately un-clever:
 *
 *   - It renders the columns' EXISTING cells (`render`) — no value is
 *     recomputed and no formatter authority is created here.
 *   - It uses the columns' EXISTING labels — no label is fabricated.
 *   - It infers nothing about business meaning. Placement follows optional,
 *     opt-in `cardRole` hints; with no hints it uses a safe default (first
 *     column = title, the rest = fields) and hides no data to save space.
 *
 * Pages that need business-specific card content keep providing an explicit
 * `renderMobileCard` — that remains the correct extension point.
 */
export function AutoDataCard<T>({
  row,
  columns,
  actions,
  onOpen,
  openLabel,
  selected,
  onSelect,
  selectLabel,
  focused,
}: AutoDataCardProps<T>) {
  const visible = columns.filter((column) => column.cardRole !== 'hidden');

  const titleCol = visible.find((column) => column.cardRole === 'title') ?? visible[0];
  const subtitleCol = visible.find((column) => column.cardRole === 'subtitle');
  const statusCol = visible.find((column) => column.cardRole === 'status');

  const fieldCols = visible.filter(
    (column) => column !== titleCol && column !== subtitleCol && column !== statusCol,
  );

  const fields: MobileDataCardField[] = fieldCols.map((column) => ({
    label: column.label,
    value: column.render(row),
    align: column.align === 'end' ? 'end' : 'start',
  }));

  return (
    <MobileDataCard
      title={titleCol ? titleCol.render(row) : null}
      subtitle={subtitleCol ? subtitleCol.render(row) : undefined}
      status={statusCol ? statusCol.render(row) : undefined}
      fields={fields}
      actions={actions}
      onOpen={onOpen}
      openLabel={openLabel}
      selected={selected}
      onSelect={onSelect}
      selectLabel={selectLabel}
      focused={focused}
    />
  );
}
