/**
 * CRM-01 Task 1 — Lead 360.
 *
 * `Modules\Crm\Sales\Domain\Models\Lead` (`crm_leads`) — the canonical CRM
 * sales lead. Deliberately distinct from `CustomerEngagement`'s `cep_leads`
 * (the omnichannel/conversation-sourced prospect, surfaced separately under
 * `/customer-engagement/leads`) — see CRM-01 report, "Lead authority". Do not
 * merge these types or point this feature's service at `/customer-engagement`.
 */

export type CrmLeadStatus = 'new' | 'contacted' | 'qualified' | 'unqualified' | 'converted';

export type CrmLead = {
  id: string;
  name: string;
  phone: string | null;
  email: string | null;
  company_name: string | null;
  source: string | null;
  status: CrmLeadStatus;
  score: number | null;
  owner_id: number | null;
  customer_id: string | null;
  converted_opportunity_id: string | null;
  converted_at: string | null;
};

export type CrmLeadsQuery = {
  q?: string;
  status?: CrmLeadStatus | 'all';
  owner_id?: number;
  page?: number;
  per_page?: number;
};

export type CrmLeadsMeta = { page: number; per_page: number; total: number; last_page: number };

export type CrmLeadsResult = { data: CrmLead[]; meta: CrmLeadsMeta };

export type CrmLeadCreateValues = {
  name: string;
  phone?: string | null;
  email?: string | null;
  company_name?: string | null;
  source?: string | null;
  notes?: string | null;
};

export type CrmLeadConvertValues = {
  opportunity_name?: string | null;
  amount?: number | null;
  expected_close_date?: string | null;
  existing_customer_id?: string | null;
};

export type CrmLeadConvertResult = { lead: CrmLead; customer_id: string; opportunity_id: string };

/** Shared with Opportunities — a Lead's activities/follow-ups (`crm_sales_activities`). */
export type CrmSalesActivity = {
  id: string;
  subject_type: 'lead' | 'opportunity';
  subject_id: string;
  activity_type: string;
  title: string;
  status: 'planned' | 'done' | 'cancelled' | string;
  due_at: string | null;
  remind_at: string | null;
  completed_at: string | null;
  assignee_id: number | null;
};
