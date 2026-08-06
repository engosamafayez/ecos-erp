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
};

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
