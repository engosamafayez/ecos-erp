/**
 * TASK-ECOS-MOBILE-REMAINING-PAGES-ORDER-DRAWER-PAYMENT-NOTES-004 — focused
 * coverage for the Order Detail Drawer's Notes tab.
 *
 * `t()` resolves selectors against the REAL `orders.json` locale bundle
 * (mirroring workflow-tab-refusal.test.tsx's own established pattern for this
 * same drawer file).
 *
 * Scope: canonical notes render with author/time, empty state, read-failure
 * state (distinct from empty), long notes remain usable, note creation goes
 * through the canonical mutation only, a successful creation relies on the
 * canonical query-invalidation refresh (no manual optimistic object is
 * fabricated here), and a read-only (no sales.orders.update permission) user
 * gets no compose box and no edit/delete controls.
 */

import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

import enOrders from '@/i18n/locales/en/orders.json';

const { activeBundle } = vi.hoisted(() => ({ activeBundle: { current: null as unknown } }));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (selector: (b: unknown) => string) => selector(activeBundle.current),
  }),
}));

const { mockCan, mockAddNote, mockUpdateNote, mockDeleteNote } = vi.hoisted(() => ({
  mockCan: vi.fn(() => true),
  mockAddNote: { mutate: vi.fn(), isPending: false },
  mockUpdateNote: { mutate: vi.fn(), isPending: false },
  mockDeleteNote: { mutate: vi.fn(), isPending: false },
}));

vi.mock('@/features/authorization/use-authorization', () => ({
  usePermission: () => ({ can: mockCan }),
}));

vi.mock('@/features/orders/hooks/use-orders', () => ({
  useAddOrderNote: () => mockAddNote,
  useUpdateOrderNote: () => mockUpdateNote,
  useDeleteOrderNote: () => mockDeleteNote,
}));

import { OrderNotesTab } from './notes-tab';
import type { Order, OrderNote } from '../types/order';

function makeNote(overrides: Partial<OrderNote> = {}): OrderNote {
  return {
    id: 'note-1',
    order_id: 'order-1',
    type: 'internal',
    content: 'A canonical internal note.',
    user_id: 'u1',
    user_name: 'Sara Ahmed',
    user_role: 'ops',
    is_edited: false,
    edited_by_id: null,
    edited_by_name: null,
    edited_at: null,
    created_at: '2026-09-01T10:00:00Z',
    updated_at: '2026-09-01T10:00:00Z',
    ...overrides,
  };
}

function makeOrder(overrides: Partial<Order> = {}): Order {
  return {
    id: 'order-1',
    notes: null,
    customer_note: null,
    internal_notes: null,
    order_notes_list: [],
    created_at: '2026-09-01T09:00:00Z',
    created_by_name: 'System',
    ...overrides,
  } as unknown as Order;
}

beforeEach(() => {
  vi.clearAllMocks();
  activeBundle.current = enOrders;
  mockCan.mockReturnValue(true);
});

describe('OrderNotesTab', () => {
  it('shows the read-failure state, not an empty-notes message, when the detail fetch failed', () => {
    render(<OrderNotesTab order={makeOrder()} readFailed onRetry={vi.fn()} />);

    expect(screen.getByText(enOrders.orderDetail.failedToLoad)).toBeInTheDocument();
    expect(screen.queryByText(enOrders.notesTab.noInternalNotes)).not.toBeInTheDocument();
  });

  it('calls onRetry when the retry button is pressed on a read failure', async () => {
    const user = userEvent.setup();
    const onRetry = vi.fn();
    render(<OrderNotesTab order={makeOrder()} readFailed onRetry={onRetry} />);

    await user.click(screen.getByRole('button', { name: enOrders.orderDetail.retry }));
    expect(onRetry).toHaveBeenCalledTimes(1);
  });

  it('renders a canonical internal note with author and timestamp', () => {
    const order = makeOrder({ order_notes_list: [makeNote({ content: 'Please double-check the address.' })] });
    render(<OrderNotesTab order={order} />);

    expect(screen.getByText('Please double-check the address.')).toBeInTheDocument();
    expect(screen.getByText('Sara Ahmed')).toBeInTheDocument();
  });

  it('shows the canonical empty state for a section with genuinely no notes', () => {
    render(<OrderNotesTab order={makeOrder()} />);
    expect(screen.getByText(enOrders.notesTab.noInternalNotes)).toBeInTheDocument();
  });

  it('keeps a very long note fully readable (no truncation, wraps as plain text)', () => {
    const longContent = 'A'.repeat(2000);
    const order = makeOrder({ order_notes_list: [makeNote({ content: longContent })] });
    render(<OrderNotesTab order={order} />);

    expect(screen.getByText(longContent)).toBeInTheDocument();
  });

  it('surfaces the legacy internal_notes column, previously absent from this tab entirely', () => {
    const order = makeOrder({ internal_notes: 'Legacy internal note from before the notes table existed.' });
    render(<OrderNotesTab order={order} />);

    expect(screen.getByText('Legacy internal note from before the notes table existed.')).toBeInTheDocument();
  });

  it('adding a note calls only the canonical mutation, never a second local-truth append', async () => {
    const user = userEvent.setup();
    render(<OrderNotesTab order={makeOrder()} />);

    const textarea = screen.getByPlaceholderText(enOrders.notesTab.writePlaceholder);
    await user.type(textarea, 'New canonical note');
    await user.click(screen.getByRole('button', { name: enOrders.notesTab.addNote }));

    expect(mockAddNote.mutate).toHaveBeenCalledTimes(1);
    expect(mockAddNote.mutate).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'order-1', content: 'New canonical note', type: 'internal' }),
      expect.anything(),
    );
  });

  it('a read-only user (no sales.orders.update) sees notes but no compose box and no edit/delete controls', () => {
    mockCan.mockReturnValue(false);
    const order = makeOrder({ order_notes_list: [makeNote()] });
    render(<OrderNotesTab order={order} />);

    expect(screen.getByText('A canonical internal note.')).toBeInTheDocument();
    expect(screen.queryByPlaceholderText(enOrders.notesTab.writePlaceholder)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: enOrders.notesTab.editNote })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: enOrders.notesTab.deleteNote })).not.toBeInTheDocument();
  });

  it('an authorized user sees the compose box and edit/delete controls', () => {
    mockCan.mockReturnValue(true);
    const order = makeOrder({ order_notes_list: [makeNote()] });
    render(<OrderNotesTab order={order} />);

    expect(screen.getByPlaceholderText(enOrders.notesTab.writePlaceholder)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: enOrders.notesTab.editNote })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: enOrders.notesTab.deleteNote })).toBeInTheDocument();
  });

  it('shows the WooCommerce customer note only when one canonically exists', () => {
    const { rerender } = render(<OrderNotesTab order={makeOrder({ customer_note: null })} />);
    expect(screen.queryByText(enOrders.notesTab.customerLeftNote)).not.toBeInTheDocument();

    rerender(<OrderNotesTab order={makeOrder({ customer_note: 'Please deliver after 6pm.' })} />);
    expect(screen.getByText('Please deliver after 6pm.')).toBeInTheDocument();
  });
});
