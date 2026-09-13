import '@testing-library/jest-dom/vitest';
import type { ReactNode } from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// TASK-ECOS-CRM-CUSTOMER-PORTFOLIO-AND-FOLLOWUP-003 — the CRM Portfolio had
// no frontend at all before this task. These tests cover: real data render
// (owner/follow-up/overdue/blocked/balance, all server-composed), and the
// loading/empty/error states staying honestly distinct — an API failure must
// never render as "no customers" (§28).

function pathProxy(path: string): unknown {
  const target = () => path;
  return new Proxy(target, {
    get(_t, prop) {
      if (prop === Symbol.toPrimitive || prop === 'toString' || prop === 'valueOf') return () => path;
      return pathProxy(path ? `${path}.${String(prop)}` : String(prop));
    },
  });
}
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) => (typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel)),
  }),
}));

// TASK-ECOS-CRM-OPERATIONAL-WIRING-IAM-AND-SOURCE-HARDENING-004 (§22/§29) —
// permission-aware UX: defaults to authorized so existing render tests keep
// their prior behavior; individual tests override mockCan to prove the
// forbidden-mutation-hidden case.
let mockCan: (permission: string) => boolean = () => true;
vi.mock('@/features/authorization', () => ({ usePermission: () => ({ can: (p: string) => mockCan(p) }) }));

vi.mock('@/components/data-grid/smart-toolbar', () => ({ SmartToolbar: () => <div data-testid="toolbar" /> }));
vi.mock('@/components/data-grid/universal-data-grid', () => ({
  UniversalDataGrid: ({
    data,
    columns,
    rowId,
    onRowClick,
    loading,
    error,
    emptyState,
    errorState,
  }: {
    data: unknown[];
    columns: { key: string; cell: (r: unknown) => ReactNode }[];
    rowId: (r: unknown) => string;
    onRowClick?: (r: unknown) => void;
    loading?: boolean;
    error?: boolean;
    emptyState: ReactNode;
    errorState: ReactNode;
  }) => {
    if (loading) return <div data-testid="loading" />;
    if (error) return <div data-testid="error-state">{errorState}</div>;
    if (data.length === 0) return <div data-testid="empty-state">{emptyState}</div>;

    return (
      <table>
        <tbody>
          {data.map((r) => (
            <tr key={rowId(r)} data-testid="portfolio-row" onClick={() => onRowClick?.(r)}>
              {columns.map((c) => (
                <td key={c.key}>{c.cell(r)}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    );
  },
}));

vi.mock('@/features/crm/components/crm-customer-drawer', () => ({
  CrmCustomerDrawer: ({ open }: { open: boolean }) => (open ? <div data-testid="drawer-open" /> : null),
}));

import { useAssignSalesOwner, useCrmPortfolioQuery } from '@/features/crm/hooks/use-crm-portfolio';
vi.mock('@/features/crm/hooks/use-crm-portfolio', () => ({
  useCrmPortfolioQuery: vi.fn(),
  useAssignSalesOwner: vi.fn(),
}));

import { CrmPortfolioPage } from './crm-portfolio-page';
import type { CrmPortfolioRow } from '@/features/crm/types/crm-customer';

const mockPortfolioQuery = useCrmPortfolioQuery as unknown as ReturnType<typeof vi.fn>;
const mockAssignOwner = useAssignSalesOwner as unknown as ReturnType<typeof vi.fn>;

// Fixture data below is asserted against by value (server-composed test content),
// never rendered as real UI copy — not subject to the i18n hardcoded-string rule.
/* eslint-disable ecos-i18n/no-hardcoded-ui-strings */
const ROW: CrmPortfolioRow = {
  id: 'cust-1',
  code: 'CUS-000001',
  name: 'Zeinab Kamel',
  primary_phone: '01000000001',
  sales_owner_id: null,
  sales_owner_name: null,
  is_unassigned: true,
  blocked: { id: null, is_blocked: false, reason: null, blocked_at: null, blocked_by: null },
  finance: { balance: 1250.5 },
  commerce: {
    orders_count: 4,
    total_order_value: 5000,
    delivered_count: 3,
    receiving_rate: 75,
    average_order_value: 1250,
    last_order_at: null,
  },
  crm: {
    owner: { id: null, name: null },
    open_follow_ups_count: 1,
    next_follow_up: {
      id: 'task-1',
      task_type: 'follow_up',
      title: 'Call about renewal',
      description: null,
      status: 'open',
      priority: 'high',
      priority_valid: true,
      due_at: '2026-01-01T09:00:00Z',
      scheduled_at: null,
      location: null,
      assignee_id: null,
      completed_at: null,
      queue: 'overdue',
      is_overdue: true,
    },
    recent_activity: null,
  },
  engagement: { conversations_count: 2, last_conversation_at: '2026-01-01T00:00:00Z' },
};

function setup(over: Partial<{ rows: CrmPortfolioRow[]; loading: boolean; error: boolean }> = {}) {
  const pending = Boolean(over.error) || Boolean(over.loading);
  mockPortfolioQuery.mockReturnValue({
    data: pending ? undefined : { data: over.rows ?? [ROW], meta: { page: 1, per_page: 25, total: (over.rows ?? [ROW]).length, last_page: 1 } },
    isLoading: Boolean(over.loading),
    isError: Boolean(over.error),
    isFetching: false,
    refetch: vi.fn(),
  });
  mockAssignOwner.mockReturnValue({ mutate: vi.fn(), isPending: false });
}

describe('CrmPortfolioPage — real composed data, never a fabricated empty state', () => {
  beforeEach(() => {
    mockCan = () => true;
  });

  it('renders the owner, next follow-up (overdue), blocked, balance and engagement facts — all server-computed', () => {
    setup();
    render(<CrmPortfolioPage />);

    expect(screen.getByText('Zeinab Kamel')).toBeInTheDocument();
    expect(screen.getByText('Call about renewal')).toBeInTheDocument();
    expect(screen.getByText('portfolio.queue.overdue')).toBeInTheDocument();
    expect(screen.getByText('portfolio.owner.unassigned')).toBeInTheDocument();
    expect(screen.getByText('1,250.50')).toBeInTheDocument();
  });

  it('opens the customer drawer on row click', () => {
    setup();
    render(<CrmPortfolioPage />);

    fireEvent.click(screen.getByTestId('portfolio-row'));
    expect(screen.getByTestId('drawer-open')).toBeInTheDocument();
  });

  it('shows a loading state, not an empty one, while the request is in flight', () => {
    setup({ loading: true });
    render(<CrmPortfolioPage />);

    expect(screen.getByTestId('loading')).toBeInTheDocument();
    expect(screen.queryByTestId('empty-state')).not.toBeInTheDocument();
  });

  it('shows a truthful error state on API failure — never renders as an empty portfolio', () => {
    setup({ error: true });
    render(<CrmPortfolioPage />);

    expect(screen.getByTestId('error-state')).toBeInTheDocument();
    expect(screen.queryByTestId('empty-state')).not.toBeInTheDocument();
    expect(screen.queryByText('Zeinab Kamel')).not.toBeInTheDocument();
  });

  it('shows the genuine empty state only when the request succeeded with zero rows', () => {
    setup({ rows: [] });
    render(<CrmPortfolioPage />);

    expect(screen.getByTestId('empty-state')).toBeInTheDocument();
  });

  // TASK-ECOS-CRM-OPERATIONAL-WIRING-IAM-AND-SOURCE-HARDENING-004 §22 — a
  // viewer without crm.customers.update sees the same data, never the
  // mutation control (not disabled — absent, per §22's own instruction).
  it('hides the owner-assignment control for a user without crm.customers.update, while still showing the read data', () => {
    mockCan = () => false;
    setup();
    render(<CrmPortfolioPage />);

    expect(screen.getByText('portfolio.owner.unassigned')).toBeInTheDocument();
    expect(screen.queryByText('portfolio.owner.assign')).not.toBeInTheDocument();
    expect(screen.queryByPlaceholderText('portfolio.owner.idPlaceholder')).not.toBeInTheDocument();
  });

  it('shows the owner-assignment control for a user with crm.customers.update', () => {
    mockCan = () => true;
    setup();
    render(<CrmPortfolioPage />);

    expect(screen.getByText('portfolio.owner.assign')).toBeInTheDocument();
  });
});
