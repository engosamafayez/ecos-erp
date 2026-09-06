/**
 * Reporting V1 frontend types — mirror `Modules/Reporting/Domain/Catalog/*::make()`
 * and `ReportResult::toArray()` / `ReportPeriod::toArray()` exactly (TASK-ECOS-
 * REPORTING-V1-USER-VISIBLE-NAVIGATION-CLOSURE-010). No field here is invented;
 * every key was confirmed against the actual backend source before being typed.
 */

export type ReportCatalogueEntry = {
  id: string;
  name: string;
  category: string;
  metric_ids: string[];
  read_strategy: string;
  /** The category-level `reports.<category>.view` permission gating execution. */
  permission: string;
  gap_classification: string;
  is_v1: boolean;
  source_modules: string[];
  notes: string | null;
};

export type MetricDictionaryEntry = {
  id: string;
  name: string;
  category: string;
  definition: string;
  source_modules: string[];
  classification: string;
  freshness: string;
  freshness_note: string | null;
  date_basis: string | null;
  known_dependency: string | null;
};

export type ReportCataloguePayload = {
  reports: ReportCatalogueEntry[];
  total: number;
};

export type MetricDictionaryPayload = {
  metrics: MetricDictionaryEntry[];
  total: number;
};

export type ReportPeriod = {
  from: string | null;
  to: string | null;
  timezone: string;
};

export type ReportResult = {
  report_id: string;
  kpis: Record<string, number | string | null>;
  rows: Array<Record<string, unknown>>;
  totals: Record<string, number | string | null>;
  period: ReportPeriod;
  applied_filters: Record<string, unknown>;
  generated_at: string;
};
