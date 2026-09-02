/**
 * Focused coverage for WasteInvestigationMobileCard — the Mobile "row as card"
 * surface added by TASK-ECOS-MOBILE-REMAINING-PAGES-WAREHOUSE-EXCEPTIONS-002.
 *
 * Scope (per the task's validation checklist): card renders the same core
 * operational data as the desktop table row, status/SLA badges render
 * correctly, the Resolve action is offered only while pending (never on a
 * resolved investigation, and never as a fabricated "create liability"
 * action — the only legitimate mutation exposed here is the existing
 * canonical resolve workflow), and tapping the card opens the detail surface
 * for the right record.
 */

// Brings the toBeInTheDocument matcher's TYPE augmentation with it. test-setup.ts
// registers the matchers at runtime, but it is outside tsconfig.app.json, so the
// types are not visible to a type-check that roots at this file.
import '@testing-library/jest-dom/vitest';
import { describe, it, expect, vi, beforeAll } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import i18n from '@/i18n/i18n';
import { WasteInvestigationMobileCard } from './waste-investigation-mobile-card';
import type { WasteInvestigation } from '../types/inventory-count';

// The `inventory-count` namespace loads lazily via i18n's Vite-glob backend
// (src/i18n/i18n.ts) — awaiting it here avoids the render race where `t()`
// resolves to an empty string because the namespace hasn't fetched yet.
beforeAll(async () => {
  await i18n.loadNamespaces('inventory-count');
});

function makeInvestigation(overrides: Partial<WasteInvestigation> = {}): WasteInvestigation {
  return {
    id: 'inv-1',
    company_id: 'co-1',
    warehouse_id: 'wh-1',
    count_session_id: null,
    count_line_id: null,
    product_id: 'p-1',
    product: { id: 'p-1', sku: 'SKU-001', name: 'Widget', image_url: null },
    warehouse: { id: 'wh-1', name: 'Main Warehouse' },
    quantity: 5,
    unit_cost: 10,
    total_cost: 50,
    damage_reason: 'Dropped during handling',
    status: 'pending_investigation',
    outcome: null,
    investigator_notes: null,
    resolved_by: null,
    resolved_at: null,
    month: '2026-09',
    created_at: '2026-09-01T00:00:00Z',
    cost_snapshot_unit_cost: null,
    cost_snapshot_total_value: null,
    cost_method: null,
    currency: null,
    cost_snapshot_at: null,
    metadata: null,
    created_by: null,
    days_pending: 2,
    is_overdue_3: false,
    is_overdue_7: false,
    ...overrides,
  };
}

describe('WasteInvestigationMobileCard', () => {
  it('renders the same core operational data as the desktop row', () => {
    const inv = makeInvestigation();
    render(<WasteInvestigationMobileCard investigation={inv} onOpen={vi.fn()} onResolve={vi.fn()} />);

    expect(screen.getByText('Widget')).toBeInTheDocument();
    expect(screen.getByText('SKU-001')).toBeInTheDocument();
    expect(screen.getByText('5.00')).toBeInTheDocument();
    expect(screen.getByText('50.00')).toBeInTheDocument();
    expect(screen.getByText('Dropped during handling')).toBeInTheDocument();
    expect(screen.getByText('Main Warehouse')).toBeInTheDocument();
  });

  it('prefers the FIFO cost snapshot over the raw total when available', () => {
    const inv = makeInvestigation({ cost_snapshot_total_value: 42.5 });
    render(<WasteInvestigationMobileCard investigation={inv} onOpen={vi.fn()} onResolve={vi.fn()} />);

    expect(screen.getByText('42.50')).toBeInTheDocument();
    expect(screen.queryByText('50.00')).not.toBeInTheDocument();
  });

  it('shows the Resolve action for a pending investigation, not a resolved one', () => {
    const pending = makeInvestigation({ status: 'pending_investigation' });
    const { rerender } = render(
      <WasteInvestigationMobileCard investigation={pending} onOpen={vi.fn()} onResolve={vi.fn()} />,
    );
    expect(screen.getByRole('button', { name: /resolve/i })).toBeInTheDocument();

    const resolved = makeInvestigation({
      status: 'resolved',
      outcome: 'operational_waste',
      resolved_by: 'Jane',
      resolved_at: '2026-09-02T00:00:00Z',
    });
    rerender(<WasteInvestigationMobileCard investigation={resolved} onOpen={vi.fn()} onResolve={vi.fn()} />);
    expect(screen.queryByRole('button', { name: /resolve/i })).not.toBeInTheDocument();
  });

  it('never offers a liability/financial action from this card — open + resolve are the only controls', () => {
    const inv = makeInvestigation();
    render(<WasteInvestigationMobileCard investigation={inv} onOpen={vi.fn()} onResolve={vi.fn()} />);

    const buttons = screen.getAllByRole('button');
    const names = buttons.map((b) => b.getAttribute('aria-label') ?? b.textContent);
    expect(buttons).toHaveLength(2);
    expect(names.some((n) => /view details/i.test(n ?? ''))).toBe(true);
    expect(names.some((n) => /resolve/i.test(n ?? ''))).toBe(true);
    expect(names.some((n) => /liability|convert|debt|settle/i.test(n ?? ''))).toBe(false);
  });

  it('calls onOpen with the investigation when the card is tapped', async () => {
    const user = userEvent.setup();
    const onOpen = vi.fn();
    const inv = makeInvestigation();
    render(<WasteInvestigationMobileCard investigation={inv} onOpen={onOpen} onResolve={vi.fn()} />);

    await user.click(screen.getByRole('button', { name: /view details/i }));
    expect(onOpen).toHaveBeenCalledWith(inv);
  });

  it('calls onResolve (not onOpen) when the Resolve action is tapped', async () => {
    const user = userEvent.setup();
    const onOpen = vi.fn();
    const onResolve = vi.fn();
    const inv = makeInvestigation();
    render(<WasteInvestigationMobileCard investigation={inv} onOpen={onOpen} onResolve={onResolve} />);

    await user.click(screen.getByRole('button', { name: /resolve/i }));
    expect(onResolve).toHaveBeenCalledWith(inv);
    expect(onOpen).not.toHaveBeenCalled();
  });
});
