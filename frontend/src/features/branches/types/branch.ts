/**
 * Branches feature types.
 */
export type BranchCompany = {
  id: string;
  code: string;
  name: string;
};

export type Branch = {
  id: string;
  company_id: string;
  company?: BranchCompany;
  code: string;
  name: string;
  phone: string | null;
  email: string | null;
  manager_name: string | null;
  address: string | null;
  city: string | null;
  country: string | null;
  is_head_office: boolean;
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
};

export type BranchPayload = {
  company_id: string;
  code: string;
  name: string;
  phone?: string;
  email?: string;
  manager_name?: string;
  address?: string;
  city?: string;
  country?: string;
  is_head_office: boolean;
  is_active: boolean;
};

export type BranchSortField =
  | 'code'
  | 'name'
  | 'city'
  | 'is_head_office'
  | 'is_active'
  | 'created_at';
export type SortDirection = 'asc' | 'desc';
export type BranchStatusFilter = 'all' | 'active' | 'inactive';

export type BranchesQuery = {
  search?: string;
  company_id?: string;
  page?: number;
  per_page?: number;
  sort_by?: BranchSortField;
  sort_dir?: SortDirection;
  status?: BranchStatusFilter;
};

export type PaginationMeta = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type BranchesResult = {
  items: Branch[];
  meta: PaginationMeta;
};
