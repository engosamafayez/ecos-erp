export type CertStatus = 'PASS' | 'WARN' | 'FAIL' | 'SKIP';
export type FindingSeverity = 'CRITICAL' | 'HIGH' | 'MEDIUM' | 'LOW';

export interface CategoryResult {
  score: number;
  status: CertStatus;
  weight: number;
}

export interface EngineeringMetrics {
  dead_code_files: number;
  arch_critical: number;
  arch_high: number;
  arch_medium: number;
  arch_low: number;
  arch_total: number;
  repo_health_pct: number;
  todo_count: number;
  phpstan_baseline_issues: number;
  missing_translation_keys: number;
  total_php_files: number;
  total_tsx_files: number;
}

export interface EngineeringRun {
  id: string;
  branch: string;
  commit: string | null;
  mode: 'full' | 'fast';
  overall_score: number;
  release_ready: boolean;
  categories: Record<string, CategoryResult>;
  metrics: EngineeringMetrics;
  blockers: string[];
  report_json: Record<string, unknown> | null;
  certified_at: string;
  created_at: string;
  findings?: EngineeringFinding[];
  findings_summary?: FindingsSummary;
}

export interface EngineeringFinding {
  id: string;
  engineering_run_id: string;
  severity: FindingSeverity;
  category: string;
  file: string | null;
  line: number | null;
  title: string;
  description: string | null;
  fix_suggestion: string | null;
  created_at: string;
}

export interface FindingsSummary {
  CRITICAL: number;
  HIGH: number;
  MEDIUM: number;
  LOW: number;
}

export interface ScoreTrendPoint {
  date: string;
  score: number;
  release_ready: boolean;
}

export interface EngineeringDashboard {
  has_data: boolean;
  latest_run: EngineeringRun | null;
  score_trend: ScoreTrendPoint[];
  findings_count: FindingsSummary;
  total_runs: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
  };
}
