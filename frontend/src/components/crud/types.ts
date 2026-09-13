import type { LucideIcon } from 'lucide-react';

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
