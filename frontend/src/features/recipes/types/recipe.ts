// Recipe types — same underlying API shape as BOM, renamed for the domain.

export type RecipeProductChannel = {
  id: string;
  name: string;
  company_id: string;
  company_name: string | null;
};

export type RecipeProductCategory = {
  id: string;
  name: string;
};

export type RecipeProduct = {
  id: string;
  sku: string;
  name: string;
  image_url: string | null;
  category: RecipeProductCategory | null;
  channels: RecipeProductChannel[];
};

export type RecipeUnit = {
  id: string;
  name: string;
  symbol: string;
};

export type RecipeMaterial = {
  id: string;
  sku: string;
  name: string;
  product_type: 'raw_material' | 'packaging_material' | string;
  image_url: string | null;
  material_cost: number;
  unit: RecipeUnit | null;
};

export type RecipeLine = {
  id: string;
  raw_material_id: string;
  raw_material: RecipeMaterial | null;
  quantity: number;
  waste_percentage: number;
};

export type Recipe = {
  id: string;
  bom_number: string;
  product_id: string;
  product: RecipeProduct | null;
  version: string;
  is_active: boolean;
  notes: string | null;
  manufacturing_cost: number;
  other_costs: number;
  recipe_cost: number;
  total_waste_pct: number;
  execution_instructions: string | null;
  lines_count: number;
  lines: RecipeLine[];
  created_at: string | null;
  updated_at: string | null;
};

export type RecipeLinePayload = {
  raw_material_id: string;
  quantity: number;
  waste_percentage: number;
};

export type RecipePayload = {
  product_id: string;
  version: string;
  is_active: boolean;
  notes?: string | null;
  manufacturing_cost?: number;
  other_costs?: number;
  execution_instructions?: string | null;
  lines: RecipeLinePayload[];
};

export type RecipeSortField =
  | 'bom_number'
  | 'created_at'
  | 'updated_at'
  | 'product_name'
  | 'category'
  | 'recipe_cost'
  | 'total_waste_pct'
  | 'lines_count';
export type SortDirection = 'asc' | 'desc';

export type RecipesQuery = {
  search?: string;
  page?: number;
  per_page?: number;
  sort_by?: RecipeSortField;
  sort_dir?: SortDirection;
  status?: 'active' | 'draft' | 'all';
  product_id?: string;
  company_id?: string;
  channel_id?: string;
  has_manufacturing_cost?: boolean;
  has_packaging_materials?: boolean;
  updated_from?: string;
  updated_to?: string;
};

export type PaginationMeta = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type RecipesResult = {
  items: Recipe[];
  meta: PaginationMeta;
};

export type RecipeStats = {
  total: number;
  active: number;
  draft: number;
  avgCost: number;
};
