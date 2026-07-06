export type BrandCompany = {
  id: string;
  code: string;
  name: string;
};

export type Brand = {
  id: string;
  company_id: string;
  company?: BrandCompany;
  code: string;
  name: string;
  slug: string;
  logo: string | null;
  description: string | null;
  is_active: boolean;
  default_target_margin: number | null;
  default_markup: number | null;
  default_discount_pct: number | null;
  channels_count: number;
  active_channels_count: number;
  products_count: number;
  created_at: string | null;
  updated_at: string | null;
};

export type BrandPayload = {
  company_id: string;
  name: string;
  code?: string;
  slug?: string;
  logo?: string;
  description?: string;
  is_active: boolean;
  default_target_margin?: number | null;
  default_markup?: number | null;
  default_discount_pct?: number | null;
};

export type BrandSortField = 'code' | 'name' | 'slug' | 'is_active' | 'created_at' | 'updated_at';
export type SortDirection = 'asc' | 'desc';
export type BrandStatusFilter = 'all' | 'active' | 'inactive';

export type BrandsQuery = {
  search?: string;
  company_id?: string;
  status?: BrandStatusFilter;
  page?: number;
  per_page?: number;
  sort_by?: BrandSortField;
  sort_dir?: SortDirection;
};

export type PaginationMeta = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type BrandsSummary = {
  total_active_channels: number;
};

export type BrandsResult = {
  items: Brand[];
  meta: PaginationMeta;
  summary: BrandsSummary;
};
