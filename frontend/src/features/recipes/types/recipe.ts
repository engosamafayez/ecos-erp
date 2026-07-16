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
  /** The official cost used by the engine (null = missing, not yet set). */
  material_cost: number | null;
  current_fifo_cost: number | null;
  average_cost: number | null;
  last_purchase_cost: number | null;
  unit: RecipeUnit | null;
};

export type CostSource = 'fifo' | 'average' | 'last_purchase' | 'manual' | 'missing';
export type CostStatus = 'available' | 'missing';

export type RecipeLine = {
  id: string;
  raw_material_id: string;
  raw_material: RecipeMaterial | null;
  quantity: number;
  waste_percentage: number;
  /** Engine-computed per-line fields (null when material has no cost). */
  unit_cost: number | null;
  effective_qty: number;
  line_total: number | null;
  cost_source: CostSource;
  cost_status: CostStatus;
};

export type RecipeCostSummary = {
  raw_material_cost: number;
  packaging_cost: number;
  manufacturing_cost: number;
  other_cost: number;
  /** Total = raw + packaging + manufacturing + other */
  recipe_cost: number;
  finished_product_cost: number;
  suggested_selling_price: number | null;
  current_selling_price: number | null;
  margin_amount: number | null;
  margin_percent: number | null;
  last_calculated_at: string;
  has_missing_costs: boolean;
  missing_material_count: number;
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
  /** Materials-only subtotal (raw + packaging). Use cost_summary.recipe_cost for the total. */
  recipe_cost: number;
  packaging_cost: number;
  cost_summary: RecipeCostSummary | null;
  /** True when any component material has no cost set. */
  cost_pending: boolean;
  recipe_cost_updated_at: string | null;
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

export type RecipeCostHistoryEntry = {
  id: string;
  previous_materials_cost: number | null;
  new_materials_cost: number;
  difference: number | null;
  trigger_type: 'recipe_edit' | 'material_cost_update' | string;
  trigger_source: string | null;
  triggered_by: string | null;
  has_missing_costs: boolean;
  occurred_at: string;
};

export type RecipeCostHistoryResult = {
  items: RecipeCostHistoryEntry[];
  meta: PaginationMeta;
};
