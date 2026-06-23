/**
 * Categories feature types.
 */
export type CategoryParent = {
  id: string;
  code: string;
  name: string;
};

export type Category = {
  id: string;
  parent_id: string | null;
  parent?: CategoryParent | null;
  code: string;
  name: string;
  description: string | null;
  level: number;
  sort_order: number;
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
};

export type CategoryPayload = {
  parent_id?: string;
  code: string;
  name: string;
  description?: string;
  sort_order: number;
  is_active: boolean;
};

export type CategorySortField =
  | 'code'
  | 'name'
  | 'level'
  | 'sort_order'
  | 'is_active'
  | 'created_at';
export type SortDirection = 'asc' | 'desc';
export type CategoryStatusFilter = 'all' | 'active' | 'inactive';

export type CategoriesQuery = {
  search?: string;
  parent_id?: string;
  level?: number;
  page?: number;
  per_page?: number;
  sort_by?: CategorySortField;
  sort_dir?: SortDirection;
  status?: CategoryStatusFilter;
};

export type PaginationMeta = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type CategoriesResult = {
  items: Category[];
  meta: PaginationMeta;
};
