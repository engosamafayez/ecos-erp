import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// TASK-ECOS-CRM-CUSTOMER-PORTFOLIO-AND-FOLLOWUP-003 — Customer 360's CRM
// section is new. Covers: Gate B facts (finance/blocked/engagement) that had
// no frontend consumer before this task, plus the new follow-up list/actions,
// with the historical-priority case degrading gracefully (never crashing,
// never silently shown as 'normal' — §8).

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
// defaults to authorized so existing render tests keep their prior behavior.
let mockCan: (permission: string) => boolean = () => true;
vi.mock('@/features/authorization', () => ({ usePermission: () => ({ can: (p: string) => mockCan(p) }) }));

import {
  useCancelCrmTask,
  useCompleteCrmTask,
  useCreateCrmTask,
  useCrmCustomerTasksQuery,
} from '@/features/crm/hooks/use-crm-customers';
vi.mock('@/features/crm/hooks/use-crm-customers', () => ({
  useCrmCustomerTasksQuery: vi.fn(),
  useCreateCrmTask: vi.fn(),
  useCompleteCrmTask: vi.fn(),
  useCancelCrmTask: vi.fn(),
}));

import { CrmCustomerFollowUpTab } from './crm-customer-followup-tab';
import type { CrmTask } from '@/features/crm/types/crm-customer';

const mockTasksQuery = useCrmCustomerTasksQuery as unknown as ReturnType<typeof vi.fn>;
const mockCreate = useCreateCrmTask as unknown as ReturnType<typeof vi.fn>;
const mockComplete = useCompleteCrmTask as unknown as ReturnType<typeof vi.fn>;
const mockCancel = useCancelCrmTask as unknown as ReturnType<typeof vi.fn>;

// Fixture data below is asserted against by value (server-composed test content),
// never rendered as real UI copy — not subject to the i18n hardcoded-string rule.
/* eslint-disable ecos-i18n/no-hardcoded-ui-strings */
const OPEN_TASK: CrmTask = {
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
};

const LEGACY_TASK: CrmTask = {
  ...OPEN_TASK,
  id: 'task-legacy',
  title: 'Old imported task',
  priority: 'critical',
  priority_valid: false,
};

const completeMutate = vi.fn();
const cancelMutate = vi.fn();
const createMutate = vi.fn();

function setup(tasks: CrmTask[] = [OPEN_TASK]) {
  mockTasksQuery.mockReturnValue({ data: tasks, isLoading: false });
  mockCreate.mockReturnValue({ mutate: createMutate, isPending: false });
  mockComplete.mockReturnValue({ mutate: completeMutate, isPending: false });
  mockCancel.mockReturnValue({ mutate: cancelMutate, isPending: false });
}

const BASE_PROPS = {
  customerId: 'cust-1',
  crm: {
    owner: { id: '5', name: 'Nadia Owner' },
    open_follow_ups_count: 1,
    next_follow_up: OPEN_TASK,
    recent_activity: null,
  },
  finance: { balance: 1250.5 },
  blocked: { is_blocked: false, reason: null, blocked_at: null, blocked_by: null },
  engagement: { conversations_count: 2, last_conversation_at: '2026-01-01T00:00:00Z' },
};

describe('CrmCustomerFollowUpTab', () => {
  beforeEach(() => {
    mockCan = () => true;
  });

  it('renders the owner, Finance balance and engagement recency — Gate B facts with no prior frontend consumer', () => {
    setup();
    render(<CrmCustomerFollowUpTab {...BASE_PROPS} />);

    expect(screen.getByText('Nadia Owner')).toBeInTheDocument();
    expect(screen.getByText('1,250.50')).toBeInTheDocument();
  });

  it('shows the blocked banner and reason when the customer is blocked', () => {
    setup();
    render(
      <CrmCustomerFollowUpTab
        {...BASE_PROPS}
        blocked={{ is_blocked: true, reason: 'Repeated non-payment', blocked_at: '2026-01-01T00:00:00Z', blocked_by: '3' }}
      />,
    );

    expect(screen.getByText('Repeated non-payment')).toBeInTheDocument();
  });

  it('lists open follow-ups and completes/cancels via the real mutations', () => {
    setup();
    render(<CrmCustomerFollowUpTab {...BASE_PROPS} />);

    expect(screen.getByText('Call about renewal')).toBeInTheDocument();

    fireEvent.click(screen.getByText('followUp.complete'));
    expect(completeMutate).toHaveBeenCalledWith('task-1');

    fireEvent.click(screen.getByText('followUp.cancel'));
    expect(cancelMutate).toHaveBeenCalledWith('task-1');
  });

  it('shows a historical priority outside the approved set without crashing or relabeling it "normal"', () => {
    setup([LEGACY_TASK]);
    render(<CrmCustomerFollowUpTab {...BASE_PROPS} />);

    expect(screen.getByText('critical')).toBeInTheDocument();
    expect(screen.queryByText('normal')).not.toBeInTheDocument();
  });

  it('shows the graceful empty state when there are zero open follow-ups', () => {
    setup([]);
    render(<CrmCustomerFollowUpTab {...BASE_PROPS} crm={{ ...BASE_PROPS.crm, open_follow_ups_count: 0, next_follow_up: null }} />);

    expect(screen.getByText('followUp.none')).toBeInTheDocument();
  });

  it('creates a follow-up through the real mutation on form submit', () => {
    setup();
    render(<CrmCustomerFollowUpTab {...BASE_PROPS} />);

    fireEvent.change(screen.getByLabelText('followUp.newTitle'), {
      target: { value: 'Send renewal quote' },
    });
    fireEvent.click(screen.getByText('followUp.create'));

    expect(createMutate).toHaveBeenCalledWith(
      expect.objectContaining({ task_type: 'follow_up', title: 'Send renewal quote' }),
      expect.anything(),
    );
  });

  // TASK-ECOS-CRM-OPERATIONAL-WIRING-IAM-AND-SOURCE-HARDENING-004 §22 — a
  // viewer without crm.engagement.task.manage sees the follow-up data (and
  // Gate B's facts) but no mutation control — never a disabled button, an
  // absent one, matching §22's own instruction.
  it('hides complete/cancel/create controls for a user without crm.engagement.task.manage, while still showing the data', () => {
    mockCan = () => false;
    setup();
    render(<CrmCustomerFollowUpTab {...BASE_PROPS} />);

    expect(screen.getByText('Call about renewal')).toBeInTheDocument();
    expect(screen.getByText('Nadia Owner')).toBeInTheDocument();
    expect(screen.queryByText('followUp.complete')).not.toBeInTheDocument();
    expect(screen.queryByText('followUp.cancel')).not.toBeInTheDocument();
    expect(screen.queryByText('followUp.create')).not.toBeInTheDocument();
  });
});
