import type { CrmPortfolioRow } from '@/features/crm/types/crm-customer';

/** CRM-01 Task 2 — Pipeline + My Work, against the already-complete Crm\Sales backend. */

export type CrmPipelineStage = {
  id: string;
  name: string;
  order: number;
  probability: number;
  is_won: boolean;
  is_lost: boolean;
};

export type CrmPipeline = {
  id: string;
  name: string;
  is_default: boolean;
  stages: CrmPipelineStage[];
};

export type CrmOpportunityStatus = 'open' | 'won' | 'lost';

export type CrmOpportunity = {
  id: string;
  name: string;
  customer_id: string | null;
  lead_id: string | null;
  pipeline_id: string | null;
  stage_id: string | null;
  amount: number;
  currency: string;
  probability: number;
  weighted_value: number;
  status: CrmOpportunityStatus;
  source: string | null;
  owner_id: number | null;
  expected_close_date: string | null;
  order_reference: string | null;
  won_at: string | null;
  lost_at: string | null;
  lost_reason: string | null;
};

export type CrmOpportunitiesQuery = {
  pipeline_id?: string;
  status?: CrmOpportunityStatus | 'all';
  owner_id?: number;
  q?: string;
};

export type CrmOpportunityCreateValues = {
  name: string;
  pipeline_id?: string | null;
  amount?: number | null;
  expected_close_date?: string | null;
};

// ── My Work ────────────────────────────────────────────────────────────────

export type CrmMyWorkLead = {
  id: string;
  name: string;
  company_name: string | null;
  status: string;
  source: string | null;
  score: number | null;
};

export type CrmMyWorkOpportunity = {
  id: string;
  name: string;
  customer_id: string | null;
  lead_id: string | null;
  pipeline_id: string | null;
  stage_id: string | null;
  amount: number;
  expected_close_date: string | null;
};

export type CrmMyWorkActivity = {
  id: string;
  subject_type: 'lead' | 'opportunity';
  subject_id: string;
  activity_type: string;
  title: string;
  due_at: string | null;
};

export type CrmMyWorkInternalTask = {
  id: string;
  title: string;
  status: string;
  priority: string;
  due_at: string | null;
};

export type CrmMyWork = {
  leads: CrmMyWorkLead[];
  opportunities: CrmMyWorkOpportunity[];
  due_activities: CrmMyWorkActivity[];
  /** Same row shape Portfolio's own list already returns — composed, not duplicated. */
  customer_follow_ups: CrmPortfolioRow[];
  internal_tasks: CrmMyWorkInternalTask[];
};
