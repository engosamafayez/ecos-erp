/**
 * Companies feature types.
 */
export type Company = {
  id: string;
  code: string;
  name: string;
  legal_name: string | null;
  tax_number: string | null;
  commercial_registration: string | null;
  email: string | null;
  phone: string | null;
  mobile: string | null;
  website: string | null;
  currency: string | null;
  timezone: string | null;
  country: string | null;
  city: string | null;
  address: string | null;
  postal_code: string | null;
  logo: string | null;
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
};

export type CompanyPayload = {
  code: string;
  name: string;
  legal_name?: string;
  tax_number?: string;
  commercial_registration?: string;
  email?: string;
  phone?: string;
  mobile?: string;
  website?: string;
  currency?: string;
  timezone?: string;
  country?: string;
  city?: string;
  address?: string;
  postal_code?: string;
  is_active: boolean;
};

export type CompanySortField = 'code' | 'name' | 'country' | 'is_active' | 'created_at';
export type SortDirection = 'asc' | 'desc';
export type CompanyStatusFilter = 'all' | 'active' | 'inactive';

export type CompaniesQuery = {
  search?: string;
  page?: number;
  per_page?: number;
  sort_by?: CompanySortField;
  sort_dir?: SortDirection;
  status?: CompanyStatusFilter;
};

export type PaginationMeta = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type CompaniesResult = {
  items: Company[];
  meta: PaginationMeta;
};
