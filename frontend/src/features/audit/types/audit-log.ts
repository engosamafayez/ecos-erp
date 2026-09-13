export type AuditLogActor = {
  id: number;
  name: string;
  email: string;
} | null;

export type AuditLogEntry = {
  id: string;
  company_id: string | null;
  action: string;
  entity_type: string;
  entity_id: string;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  metadata: Record<string, unknown> | null;
  occurred_at: string | null;
  actor: AuditLogActor;
};

export type AuditLogListParams = {
  user_id?: number;
  action?: string;
  entity_type?: string;
  entity_id?: string;
  company_id?: string;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
};

export type AuditLogListData = {
  items: AuditLogEntry[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
};
