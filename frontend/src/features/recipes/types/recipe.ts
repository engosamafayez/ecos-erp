// Recipe types — same underlying API shape as BOM, renamed for the domain.

export type RecipeProduct = {
  id: string;
  sku: string;
  name: string;
  image_url: string | null;
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
  lines: RecipeLinePayload[];
};

export type RecipeSortField = 'bom_number' | 'created_at';
export type SortDirection = 'asc' | 'desc';

export type RecipesQuery = {
  search?: string;
  page?: number;
  per_page?: number;
  sort_by?: RecipeSortField;
  sort_dir?: SortDirection;
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
