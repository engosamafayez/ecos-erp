import type { ReactNode } from 'react';

import type { CardFieldRole } from '@/components/mobile/types';

// ── Legacy type (kept for backward compat with useColumnVisibility) ──────────
export type ColumnMeta = {
  key: string;
  label: string;
  alwaysVisible?: boolean;
  defaultVisible?: boolean;
  align?: 'start' | 'center' | 'end';
  width?: number;
  minWidth?: number;
};

export type ColumnVisibilityState = Record<string, boolean>;

// ── Universal DataGrid types ─────────────────────────────────────────────────

export type SortDirection = 'asc' | 'desc';

export type GridSortState = {
  field: string;
  direction: SortDirection;
};

/**
 * Unified column definition for UniversalDataGrid.
 * Combines column metadata (visibility, pinning) with the render function.
 * Assignable to ColumnMeta[] because it is a structural superset.
 */
export type DataGridColumnDef<T> = {
  key: string;
  /** Display name shown in the Column Manager dropdown. */
  label: string;
  /** Header cell content. Falls back to `label` when omitted. */
  header?: ReactNode;
  /** Renders the cell content for a given row. */
  cell: (row: T) => ReactNode;

  // ── Sort ──
  sortable?: boolean;

  // ── Alignment (LTR + RTL aware) ──
  align?: 'start' | 'center' | 'end';

  // ── Visibility ──
  /** Cannot be toggled off by the Column Manager (e.g. primary key, actions). */
  alwaysVisible?: boolean;
  /** Initial visibility when no stored preference exists. Defaults to true. */
  defaultVisible?: boolean;

  // ── Sticky pinning ──
  /** 'left' stacks from the left edge; 'right' anchors to the right edge. */
  pin?: 'left' | 'right';

  // ── Sizing (used for sticky offset computation and future resizing) ──
  width?: number;
  minWidth?: number;

  // ── Mobile auto-card (optional, additive) ──
  /**
   * Placement hint for the automatic mobile fallback card (§9/§10). Optional
   * and opt-in: with no hint the column becomes a label/value field (or the
   * title if it is the first column). Never infers business meaning — it only
   * positions this column's existing cell inside the card. Ignored when the
   * page supplies its own `renderMobileCard`.
   */
  cardRole?: CardFieldRole;

  // ── Skeleton ──
  /** Tailwind className for the skeleton shape shown during loading. */
  skeletonClassName?: string;

  // ── Extra classNames ──
  headerClassName?: string;
  cellClassName?: string;
};

/**
 * Public selection API consumed by UniversalDataGrid.
 * Returned by useRowSelection (which also adds clearSelection).
 */
export type GridSelectionAPI = {
  selectedIds: Set<string>;
  selectedCount: number;
  isSelected: (id: string) => boolean;
  allSelected: boolean;
  someSelected: boolean;
  selectRow: (id: string, checked: boolean) => void;
  selectAll: (checked: boolean) => void;
};

export type GridPaginationConfig = {
  meta: {
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
  };
  onPageChange: (page: number) => void;
};
