/**
 * CRM customer types.
 *
 * These mirror Customer360Service::identity() on the backend — the shape the
 * CRM customer endpoints actually return. They are deliberately NOT shared with
 * the legacy `@/features/customers` types: that feature consumes the Sales
 * `/customers` endpoints, which return a different payload. One shared type
 * across two contracts would be wrong for both.
 */

export type CrmCustomerType = 'individual' | 'business';

export type CrmCustomerStatus = 'prospect' | 'active' | 'inactive' | 'blocked' | 'archived';

/** One row of the CRM customer list — the backend's `identity` projection. */

/**
 * Order-derived KPIs, computed server-side by CustomerOrderMetricsService in ONE
 * aggregate query per page. Never recomputed in the client.
 *
 * Canonical definitions (do not re-derive):
 *   orders_count        COUNT(orders) scoped by customer + company, deleted_at IS NULL
 *   total_order_value   SUM(orders.total) — cancelled and returned ARE included
 *   delivered_count     OrderStatus::Delivered only
 *   receiving_rate      delivered / ALL orders × 100 — NULL (not 0) when never ordered
 *   average_order_value total / count — NULL when never ordered
 */
export type CrmCustomerOrderMetrics = {
  orders_count: number;
  total_order_value: number;
  delivered_count: number;
  /** NULL when the customer has no orders — 0% would read as a failure. */
  receiving_rate: number | null;
  average_order_value: number | null;
  last_order_at: string | null;
};

/** One distinct product the customer has ordered, aggregated across all their orders. */
export type CrmPurchasedProduct = {
  product_id: string;
  product_name: string;
  product_sku: string | null;
  total_quantity: number;
  orders_count: number;
  last_ordered_at: string | null;
};

export type CrmCustomer = {
  id: string;
  code: string | null;
  company_id: string;
  type: CrmCustomerType | null;
  display_name: string;
  first_name: string | null;
  last_name: string | null;
  business_name: string | null;
  tax_registration_number: string | null;
  status: CrmCustomerStatus | null;
  is_active: boolean;
  primary_phone: string | null;
  primary_email: string | null;
  preferred_language: string | null;
  preferred_contact_method: string | null;
  /** Set when this record was folded into another during a merge. */
  merged_into_id: string | null;
  archived_at: string | null;
  /** Composed server-side from the default address; NULL when the customer has none. */
  full_address: string | null;
  /** City / governorate only. NULL when unknown — rendered as an em-dash, never guessed. */
  location: string | null;
} & CrmCustomerOrderMetrics;

/** Query accepted by GET /crm/customers. Mirrors the controller's `only()` list. */
export type CrmCustomersQuery = {
  q?: string;
  status?: CrmCustomerStatus;
  type?: CrmCustomerType;
  group_id?: string;
  tag_id?: string;
  per_page?: number;
  page?: number;
};

export type CrmCustomersMeta = {
  page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type CrmCustomersResult = {
  data: CrmCustomer[];
  meta: CrmCustomersMeta;
};

export type CrmCustomerGroup = {
  id: string;
  name: string;
  code?: string | null;
};

// ── Customer 360 profile ─────────────────────────────────────────────────────
// Mirrors Customer360Service::profile(). Phones, emails, addresses, notes,
// documents and tags have POST endpoints but NO list endpoint of their own —
// this profile call is the only way to read them.

export type CrmPhone = {
  id: string;
  label: string | null;
  phone: string;
  is_primary: boolean;
  is_verified: boolean;
};

export type CrmEmail = {
  id: string;
  label: string | null;
  email: string;
  is_primary: boolean;
  is_verified: boolean;
};

export type CrmAddress = {
  id: string;
  label: string | null;
  governorate: string | null;
  city: string | null;
  area: string | null;
  address_line: string | null;
  is_default: boolean;
};

export type CrmTag = { id: string; name: string; color: string | null };

export type CrmNote = {
  id: string;
  body: string;
  is_pinned: boolean;
  author_id: number | null;
  created_at: string | null;
};

export type CrmDocument = {
  id: string;
  name: string;
  doc_type: string | null;
  mime_type: string | null;
  size_bytes: number | null;
};

export type CrmCustomerProfile = {
  identity: CrmCustomer;
  group: { id: string; name: string } | null;
  phones: CrmPhone[];
  emails: CrmEmail[];
  addresses: CrmAddress[];
  tags: CrmTag[];
  notes: CrmNote[];
  documents: CrmDocument[];
  preferences: Record<string, string>;
  /** Order KPIs from canonical `orders` — never from customer-intelligence facts. */
  order_metrics: CrmCustomerOrderMetrics;
  /** Aggregated server-side: Customer → Orders → Order Lines → Products. */
  purchased_products: CrmPurchasedProduct[];
};

// ── Timeline ─────────────────────────────────────────────────────────────────
// Mirrors TimelineEntry::toArray(). Entries carry no id of their own, so the
// list is keyed by source + type + timestamp.

export type CrmTimelineEntry = {
  source: string;
  type: string;
  title: string;
  channel: string | null;
  direction: string | null;
  body: string | null;
  occurred_at: string;
  ref: { type: string; id: string | null } | null;
  actor_id: number | null;
  meta: Record<string, unknown>;
};

// ── Customer intelligence ────────────────────────────────────────────────────
// GET /crm/intelligence/customers/{id}. Every figure is computed by the backend
// engine and stored; nothing here is derived in the client.

export type CrmRiskBand = 'low' | 'medium' | 'high' | 'critical' | string;

export type CrmIntelligenceProfile = {
  customer_id: string;
  /** Days since the last purchase. */
  recency_days: number | null;
  /** Order count — the orders summary the CRM API exposes. */
  frequency: number;
  /** Total spent. */
  monetary: string | number;
  rfm_segment: string | null;
  average_order_value: string | number;
  lifetime_value: string | number;
  predicted_lifetime_value: string | number;
  purchase_frequency_monthly: string | number;
  avg_interval_days: number | null;
  tenure_days: number;
  churn_risk_score: number;
  churn_risk_band: CrmRiskBand;
  health_score: number;
  health_band: CrmRiskBand;
  segment: string | null;
  lifecycle_stage: string;
  is_repeat: boolean;
  is_retained: boolean;
  first_purchase_at: string | null;
  last_purchase_at: string | null;
  computed_at: string | null;
};

export type CrmInsight = {
  id: string;
  type: string;
  severity: string;
  title: string;
  detail: string | null;
  metric_key: string | null;
  metric_value: string | number | null;
  generated_at: string | null;
};

export type CrmRecommendation = {
  id: string;
  type: string;
  title: string;
  rationale: string | null;
  status: string;
  generated_at: string | null;
};

export type CrmCustomerIntelligence = {
  /** Null until the engine has computed a profile for this customer. */
  profile: CrmIntelligenceProfile | null;
  insights: CrmInsight[];
  recommendations: CrmRecommendation[];
};
