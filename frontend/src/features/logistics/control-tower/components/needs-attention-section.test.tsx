import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { describe, expect, it, vi } from 'vitest';

import { NeedsAttentionSection } from './needs-attention-section';
import type { HealthOverview, OperationalAlert } from '@/features/logistics/operations/types/operations';
import type { LoadingSessionOverviewRow } from '@/features/operations/loading-os/types/loading-os';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-002 §21.3 — Needs Attention deep-links and
 * §21.2/§21.11 — no fabricated data, no invented threshold. The health
 * headline and Loading Needs Review block are both genuinely new sources
 * this task added (`HealthOverview`, a real backend-computed exception
 * bucket) — these tests exist to prove they render the SERVER's numbers
 * verbatim and never appear when there is nothing real to show.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (selector: unknown, vars?: Record<string, unknown>) => {
      if (typeof selector !== 'function') return String(selector);
      const path: string[] = [];
      const probe: unknown = new Proxy(
        {},
        { get(_t, prop): unknown { path.push(String(prop)); return probe; } },
      );
      (selector as (p: unknown) => unknown)(probe);
      const key = path[path.length - 1] ?? '';
      return vars === undefined ? key : [key, ...Object.values(vars).map(String)].join(' ');
    },
  }),
}));

const navigate = vi.fn();
vi.mock('react-router-dom', () => ({
  useNavigate: () => navigate,
}));

vi.mock('@/features/logistics/operations/components/operations-badges', () => ({
  SeverityIcon: () => <span data-testid="severity-icon" />,
  SourceBadge: () => <span data-testid="source-badge" />,
}));

vi.mock('@/features/logistics/operations/components/exception-drawer', () => ({
  ExceptionDrawer: ({ exceptionId, open }: { exceptionId: string | null; open: boolean }) => (
    <div data-testid="exception-drawer" data-open={String(open)} data-exception-id={exceptionId ?? ''} />
  ),
}));

vi.mock('@/features/operations/loading-os/components/loading-groups', () => ({
  useReasonLabel: () => (reason: string) => `reason:${reason}`,
}));

const ALERT: OperationalAlert = {
  exception_id: 'exc-1',
  rule: null,
  source: 'distribution',
  category: 'execution',
  severity: 'critical',
  severity_rank: 1,
  status: 'open',
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- OperationalAlert.title fixture, not UI copy
  title: 'Group DG-4 has no vehicle assigned',
  occurrence_count: 1,
  age_minutes: 42,
  unacknowledged_minutes: 10,
  escalation_level: 0,
  is_overdue: false,
};

const HEALTH: HealthOverview = {
  generated_at: '2026-09-08T00:00:00Z',
  headline: {
    critical_alerts: 1,
    open_exceptions: 9,
    unhealthy_pools: 2,
    exhausted_capacity_slots: 0,
    fieldable_units: 5,
    overdue_escalations: 3,
  },
  alerts: { total: 1, critical: 1, warning: 0, info: 0, unacknowledged: 1, overdue: 0 },
  exceptions: {
    outstanding: 9,
    needs_attention: 9,
    critical: 1,
    escalated: 0,
    by_source: {},
    by_category: {},
    oldest_minutes: 42,
    overdue_for_escalation: 3,
    recurring: 0,
  },
  is_quiet: false,
};

const NEEDS_REVIEW_SESSION: LoadingSessionOverviewRow = {
  session_id: 'sess-1',
  bucket: 'needs_review',
  reasons: ['missing_vehicle_custody'],
  assignments: [],
  session_number: 'LS-0001',
  operational_date: '2026-09-08',
  status: 'loading',
  warehouse_id: 'wh-1',
};

function renderSection(overrides: Partial<Parameters<typeof NeedsAttentionSection>[0]> = {}) {
  return render(
    <NeedsAttentionSection
      alerts={[ALERT]}
      isLoading={false}
      isError={false}
      severityFilter="all"
      onSeverityFilterChange={vi.fn()}
      health={{ data: HEALTH, isLoading: false, isError: false }}
      loadingNeedsReview={{ count: 0, sessions: [], isLoading: false, isError: false }}
      {...overrides}
    />,
  );
}

describe('NeedsAttentionSection', () => {
  it('renders the real health headline numbers verbatim', () => {
    renderSection();

    const headline = screen.getByTestId('control-tower-health-headline');
    expect(headline).toHaveTextContent('9');
    expect(headline).toHaveTextContent('2');
    expect(headline).toHaveTextContent('3');
  });

  it('does not render a health headline when no health data is available (no fabrication)', () => {
    renderSection({ health: { data: null, isLoading: false, isError: false } });

    expect(screen.queryByTestId('control-tower-health-headline')).not.toBeInTheDocument();
  });

  // §21.3 — opens the canonical action surface with the exact exception id.
  it('opens the ExceptionDrawer with the clicked alert\'s exact id', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    renderSection();

    await userEvent.click(screen.getByTestId('needs-attention-open-exc-1'));

    const drawer = screen.getByTestId('exception-drawer');
    expect(drawer).toHaveAttribute('data-open', 'true');
    expect(drawer).toHaveAttribute('data-exception-id', 'exc-1');
  });

  it('shows the quiet message only when the operation truly reports is_quiet, not just an empty alert list', () => {
    renderSection({ alerts: [], health: { data: { ...HEALTH, is_quiet: true }, isLoading: false, isError: false } });

    expect(screen.getByText('quietTitle')).toBeInTheDocument();
  });

  it('does not claim the operation is quiet when the alert list is merely empty for the current filter', () => {
    // severityFilter narrowed to 'info' with zero info-level alerts present —
    // an empty ROWS array that must not be conflated with a truthful is_quiet,
    // even though health.data.is_quiet is (genuinely, for the WHOLE operation) true.
    const { container } = renderSection({
      severityFilter: 'info',
      health: { data: { ...HEALTH, is_quiet: true }, isLoading: false, isError: false },
    });

    expect(screen.queryByText('quietTitle')).not.toBeInTheDocument();
    // The healthy checkmark icon must not appear either — same contradiction
    // class as an incorrect title would be (task §16).
    expect(container.querySelector('.lucide-circle-check')).not.toBeInTheDocument();
    expect(container.querySelector('.lucide-bell-ring')).toBeInTheDocument();
  });

  // §21.11-adjacent (real, non-invented exception bucket) + §21.3 (deep link).
  it('renders real Loading Needs Review sessions and reasons, and only when count > 0', () => {
    renderSection({
      loadingNeedsReview: { count: 1, sessions: [NEEDS_REVIEW_SESSION], isLoading: false, isError: false },
    });

    expect(screen.getByTestId('control-tower-loading-needs-review')).toBeInTheDocument();
    expect(screen.getByText('LS-0001')).toBeInTheDocument();
    expect(screen.getByText('reason:missing_vehicle_custody')).toBeInTheDocument();
  });

  it('hides the Loading Needs Review block entirely when the real count is zero', () => {
    renderSection({ loadingNeedsReview: { count: 0, sessions: [], isLoading: false, isError: false } });

    expect(screen.queryByTestId('control-tower-loading-needs-review')).not.toBeInTheDocument();
  });

  it('deep-links Loading Needs Review to the precise needs_review bucket tab', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    renderSection({
      loadingNeedsReview: { count: 1, sessions: [NEEDS_REVIEW_SESSION], isLoading: false, isError: false },
    });

    await userEvent.click(screen.getByTestId('control-tower-loading-needs-review-open'));

    expect(navigate).toHaveBeenCalledWith('/operations/loading/workspace?tab=needs_review');
  });
});
