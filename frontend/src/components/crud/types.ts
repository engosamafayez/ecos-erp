import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';

import type { CardFieldRole } from '@/components/mobile/types';

export type SortDirection = 'asc' | 'desc';

export type SortState = {
  field: string;
  direction: SortDirection;
};

export type ColumnAlignment = 'left' | 'center' | 'right';

/**
 * Generic table column definition. `cell` renders a row's value; `key` doubles
 * as the sort field when `sortable` is set.
 */
export type ColumnDef<T> = {
  key: string;
  header: ReactNode;
  cell: (row: T) => ReactNode;
  sortable?: boolean;
  align?: ColumnAlignment;
  headerClassName?: string;
  cellClassName?: string;
  /**
   * Placement hint for the automatic mobile fallback card (§9/§10). Optional
   * and opt-in: with no hint the column becomes a label/value field (or the
   * title if it is the first column). Ignored when `renderMobileCard` is given.
   */
  cardRole?: CardFieldRole;
};

export type PaginationMeta = {
  page: number;
  perPage: number;
  total: number;
  lastPage: number;
};

export type BreadcrumbItem = {
  label: string;
  to?: string;
};

export type StatusVariant = 'active' | 'inactive' | 'pending' | 'archived';

/**
 * Generic row/menu action. Supports View, Edit, Delete and any future custom
 * actions a module needs.
 */
export type ActionMenuItem = {
  key: string;
  label: string;
  icon?: LucideIcon;
  onSelect: () => void;
  variant?: 'default' | 'destructive';
  disabled?: boolean;
};
