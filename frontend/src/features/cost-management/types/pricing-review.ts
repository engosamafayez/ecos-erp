export type ReviewStatus = 'pending' | 'approved' | 'kept' | 'custom_price' | 'snoozed';

export type ImpactType =
  | 'margin_below_target'
  | 'cost_increased'
  | 'cost_decreased'
  | 'recipe_changed'
  | 'packaging_changed';

export type PersonRef = {
  id: string;
  name: string;
};

export type ProductRef = {
  id: string;
  name: string;
  sku: string;
  image_url: string | null;
  category: string | null;
};

export type CompanyRef = { id: string; name: string };
export type ChannelRef  = { id: string; name: string };

export type PricingReview = {
  id: string;
  product: ProductRef;
  company: CompanyRef;
  channel: ChannelRef;
  current_cost: number;
  previous_cost: number;
  cost_difference: number;
  cost_change_pct: number;
  current_selling_price: number;
  suggested_selling_price: number;
  current_margin: number;
  target_margin: number;
  impacts: ImpactType[];
  status: ReviewStatus;
  reviewer: PersonRef | null;
  snooze_until: string | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
};

export type CostBreakdownLine = {
  label: string;
  category: 'raw_material' | 'packaging' | 'other';
  current_cost: number;
  previous_cost: number;
  difference: number;
  pct_of_total: number;
};

export type RecipeChangeLine = {
  material_name: string;
  sku: string;
  old_price: number;
  new_price: number;
  difference: number;
  quantity: number;
};

export type PriceHistoryEntry = {
  date: string;
  selling_price: number;
  cost: number;
  margin: number;
  changed_by: PersonRef;
};

export type ApprovalHistoryEntry = {
  id: string;
  action: 'approved' | 'kept' | 'custom_price' | 'snoozed' | 'assigned';
  old_price: number | null;
  new_price: number | null;
  reason: string | null;
  actor: PersonRef;
  created_at: string;
};

export type ProductCostDetail = {
  review: PricingReview;
  cost_breakdown: CostBreakdownLine[];
  recipe_changes: RecipeChangeLine[];
  price_history: PriceHistoryEntry[];
  approval_history: ApprovalHistoryEntry[];
};

export type ReviewSummary = {
  pending_count: number;
  below_target_count: number;
  above_target_count: number;
  cost_increased_today: number;
  cost_decreased_today: number;
  expected_profit_change: number;
};

export type PricingReviewsQuery = {
  search?: string;
  company_id?: string;
  channel_id?: string;
  category_id?: string;
  status?: ReviewStatus | 'all';
  impact?: ImpactType | 'all';
  page?: number;
  per_page?: number;
};

export type PricingReviewsResult = {
  items: PricingReview[];
  summary: ReviewSummary;
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
};

export type ApprovePayload = {
  action: 'approve_suggested' | 'keep_current' | 'custom_price';
  custom_price?: number;
  reason?: string;
};

export type SnoozePayload = { until: string };
export type AssignPayload  = { reviewer_name: string };
