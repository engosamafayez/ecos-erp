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

// ── Release Manager ─────────────────────────────────────────────────────────

export type PipelineStatus = 'pending' | 'running' | 'completed' | 'failed' | 'cancelled';
export type StageStatus    = 'pending' | 'running' | 'success' | 'failed' | 'retrying' | 'skipped' | 'cancelled';
export type PipelineStage  =
  | 'development_guardian'
  | 'architecture_guardian'
  | 'build'
  | 'tests'
  | 'commit'
  | 'push'
  | 'github_actions'
  | 'deployment_guardian'
  | 'certification'
  | 'health_check'
  | 'notifications';

export const STAGE_LABELS: Record<PipelineStage, string> = {
  development_guardian:  'Development Guardian',
  architecture_guardian: 'Architecture Guardian',
  build:                 'Build',
  tests:                 'Tests',
  commit:                'Auto Commit',
  push:                  'Auto Push',
  github_actions:        'GitHub Actions',
  deployment_guardian:   'Deployment Guardian',
  certification:         'Certification',
  health_check:          'Health Check',
  notifications:         'Notifications',
};

export const ORDERED_STAGES: PipelineStage[] = [
  'development_guardian',
  'architecture_guardian',
  'build',
  'tests',
  'commit',
  'push',
  'github_actions',
  'deployment_guardian',
  'certification',
  'health_check',
  'notifications',
];

export interface PipelineLog {
  id: string;
  stage: PipelineStage;
  stage_label: string;
  status: StageStatus;
  started_at: string | null;
  finished_at: string | null;
  duration_seconds: number | null;
  message: string | null;
  payload: unknown[] | null;
  retry_count: number;
}

export interface EngineeringPipeline {
  id: string;
  task_name: string;
  branch: string;
  commit_sha: string | null;
  status: PipelineStatus;
  current_stage: PipelineStage | null;
  current_stage_label: string | null;
  started_at: string | null;
  finished_at: string | null;
  duration_seconds: number | null;
  duration_formatted: string;
  initiated_by: string;
  error_message: string | null;
  auto_deploy: boolean;
  logs?: PipelineLog[];
}

export type NotificationSeverity = 'info' | 'warning' | 'error' | 'success';
export type NotificationType =
  | 'pipeline_started'
  | 'pipeline_completed'
  | 'pipeline_failed'
  | 'certification_failed'
  | 'health_check_failed'
  | 'deployment_pending'
  | 'deployment_failed';

export interface EngineeringPipelineNotification {
  id: string;
  pipeline_id: string | null;
  type: NotificationType;
  title: string;
  message: string;
  severity: NotificationSeverity;
  is_read: boolean;
  read_at: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
}

export interface NotificationListResponse {
  data: EngineeringPipelineNotification[];
  unread_count: number;
  meta: {
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
  };
}
