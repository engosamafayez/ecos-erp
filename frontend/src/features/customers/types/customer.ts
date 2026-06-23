export type Customer = {
  id: string;
  code: string;
  name: string;
  contact_person: string | null;
  email: string | null;
  phone: string | null;
  mobile: string | null;
  country: string | null;
  city: string | null;
  address: string | null;
  notes: string | null;
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
};

export type CustomerPayload = {
  code: string;
  name: string;
  contact_person?: string;
  email?: string;
  phone?: string;
  mobile?: string;
  country?: string;
  city?: string;
  address?: string;
  notes?: string;
  is_active: boolean;
};

export type CustomerStatusFilter = 'all' | 'active' | 'inactive';
export type CustomerSortField = 'code' | 'name' | 'country' | 'city' | 'is_active' | 'created_at';
export type SortDirection = 'asc' | 'desc';

export type CustomersQuery = {
  search?: string;
  status?: CustomerStatusFilter;
  country?: string;
  city?: string;
  page?: number;
  per_page?: number;
  sort_by?: CustomerSortField;
  sort_dir?: SortDirection;
};

export type PaginationMeta = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type CustomersResult = {
  items: Customer[];
  meta: PaginationMeta;
};
