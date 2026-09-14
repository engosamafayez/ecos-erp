import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// TASK-ECOS-V1.1-CRM-03-VOICE-UX-AND-FINAL-SOURCE-CLOSURE-016 §32 — Customer 360 Voice
// integration (§12): a "Calls" tab reusing Task 1's EngagementTimelineService, hidden entirely
// (not merely disabled) without cep.voice.use, and never rendering a Message item as a call.
// Mocking follows this drawer file's own established convention (see
// crm-customer-followup-tab.test.tsx): selector-mode t() -> dotted-path proxy, hook-level mocks
// instead of a real QueryClientProvider.

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

let mockCan: (permission: string) => boolean = () => true;
vi.mock('@/features/authorization', () => ({ usePermission: () => ({ can: (p: string) => mockCan(p) }) }));

import {
  useCrmCustomerActivitiesQuery,
  useCrmCustomerIntelligenceQuery,
  useCrmCustomerOrdersQuery,
  useCrmCustomerProfileQuery,
  useCrmCustomerTicketsQuery,
  useCrmCustomerTimelineQuery,
} from '@/features/crm/hooks/use-crm-customers';
vi.mock('@/features/crm/hooks/use-crm-customers', () => ({
  useCrmCustomerProfileQuery: vi.fn(),
  useCrmCustomerTimelineQuery: vi.fn(),
  useCrmCustomerActivitiesQuery: vi.fn(),
  useCrmCustomerIntelligenceQuery: vi.fn(),
  useCrmCustomerOrdersQuery: vi.fn(),
  useCrmCustomerTicketsQuery: vi.fn(),
}));

import { useCustomerEngagementTimeline } from '@/features/customer-engagement/hooks/use-voice';
vi.mock('@/features/customer-engagement/hooks/use-voice', () => ({
  useCustomerEngagementTimeline: vi.fn(),
}));

import { CrmCustomerDrawer } from './crm-customer-drawer';
import type { CrmCustomerProfile } from '@/features/crm/types/crm-customer';
import type { EngagementTimelineItem } from '@/features/customer-engagement/types/cep';

const mockProfile = useCrmCustomerProfileQuery as unknown as ReturnType<typeof vi.fn>;
const mockTimeline = useCrmCustomerTimelineQuery as unknown as ReturnType<typeof vi.fn>;
const mockActivity = useCrmCustomerActivitiesQuery as unknown as ReturnType<typeof vi.fn>;
const mockIntelligence = useCrmCustomerIntelligenceQuery as unknown as ReturnType<typeof vi.fn>;
const mockOrders = useCrmCustomerOrdersQuery as unknown as ReturnType<typeof vi.fn>;
const mockTickets = useCrmCustomerTicketsQuery as unknown as ReturnType<typeof vi.fn>;
const mockEngagementTimeline = useCustomerEngagementTimeline as unknown as ReturnType<typeof vi.fn>;

const PROFILE: CrmCustomerProfile = {
  identity: {
    id: 'cust-1', code: 'CUST-001', company_id: 'company-1', type: 'individual',
    display_name: 'Nadia Customer', first_name: 'Nadia', last_name: 'Customer',
    business_name: null, tax_registration_number: null, status: 'active', is_active: true,
    primary_phone: '201055512345', primary_email: null, preferred_language: 'en',
    preferred_contact_method: null, merged_into_id: null, archived_at: null,
    full_address: null, location: null,
    orders_count: 0, total_order_value: 0, delivered_count: 0, receiving_rate: null,
    average_order_value: null, last_order_at: null,
  },
  group: null,
  phones: [],
  emails: [],
  addresses: [],
  tags: [],
  notes: [],
  documents: [],
  preferences: {},
  order_metrics: {
    orders_count: 0, total_order_value: 0, delivered_count: 0, receiving_rate: null,
    average_order_value: null, last_order_at: null,
  },
  purchased_products: [],
  finance: { balance: 0 },
  blocked: { id: null, is_blocked: false, reason: null, blocked_at: null, blocked_by: null },
  engagement: { conversations_count: 0, last_conversation_at: null },
  crm: { owner: { id: null, name: null }, open_follow_ups_count: 0, next_follow_up: null, recent_activity: null },
};

const TIMELINE_ITEMS: EngagementTimelineItem[] = [
  {
    type: 'message',
    conversation_id: 'conv-1',
    provider: 'whatsapp',
    occurred_at: '2026-09-14T09:00:00Z',
    data: { id: 'msg-1', direction: 'inbound', sender_type: 'customer', sender_name: 'Nadia', message_type: 'text', content: 'Hi' },
  },
  {
    type: 'call',
    conversation_id: 'conv-2',
    provider: 'voice',
    occurred_at: '2026-09-14T10:00:00Z',
    data: { id: 'call-1', direction: 'inbound', canonical_state: 'completed', duration_seconds: 95, handled_by: 'human', outcome: 'resolved' },
  },
];

function setup() {
  mockCan = () => true;
  mockProfile.mockReturnValue({ data: PROFILE, isLoading: false });
  mockTimeline.mockReturnValue({ data: [], isLoading: false });
  mockActivity.mockReturnValue({ data: [], isLoading: false });
  mockIntelligence.mockReturnValue({ data: undefined, isLoading: false });
  mockOrders.mockReturnValue({ data: [], isLoading: false });
  mockTickets.mockReturnValue({ data: [], isLoading: false });
  mockEngagementTimeline.mockReturnValue({ data: TIMELINE_ITEMS, isLoading: false });
}

describe('CrmCustomerDrawer — Calls tab (Voice/Customer 360 integration)', () => {
  beforeEach(setup);

  it('hides the Calls tab entirely without cep.voice.use — hidden, not shown disabled', () => {
    mockCan = () => false;
    render(<CrmCustomerDrawer customerId="cust-1" open onOpenChange={() => {}} />);

    expect(screen.queryByText('drawer.tabs.calls')).not.toBeInTheDocument();
  });

  it('shows the Calls tab once cep.voice.use is granted', () => {
    render(<CrmCustomerDrawer customerId="cust-1" open onOpenChange={() => {}} />);
    expect(screen.getByText('drawer.tabs.calls')).toBeInTheDocument();
  });

  it('renders only the call-type timeline items, never a Message flattened into a fake call row', async () => {
    const user = userEvent.setup();
    render(<CrmCustomerDrawer customerId="cust-1" open onOpenChange={() => {}} />);

    await user.click(screen.getByText('drawer.tabs.calls'));

    // The call item's own facts render...
    expect(screen.getByText('drawer.callsTab.state.completed')).toBeInTheDocument();
    expect(screen.getByText('drawer.callsTab.handledBy.human')).toBeInTheDocument();
    expect(screen.getByText(/resolved/)).toBeInTheDocument();
    // ...but the message item never appears as a call row.
    expect(screen.queryByText('Hi')).not.toBeInTheDocument();
  });

  it('shows the empty state when the customer has no Voice calls', async () => {
    mockEngagementTimeline.mockReturnValue({ data: [], isLoading: false });
    const user = userEvent.setup();
    render(<CrmCustomerDrawer customerId="cust-1" open onOpenChange={() => {}} />);

    await user.click(screen.getByText('drawer.tabs.calls'));
    expect(screen.getByText('drawer.callsTab.empty')).toBeInTheDocument();
  });
});
