/**
 * Focused coverage for WarehouseLiabilityMobileCard — the Mobile "row as
 * card" surface added by TASK-ECOS-MOBILE-REMAINING-PAGES-WAREHOUSE-
 * EXCEPTIONS-002.
 *
 * Scope: card renders the same canonical fields as the desktop table row,
 * quantity/value relationships hold (FIFO snapshot preferred over the raw
 * total when present), status renders correctly per state, and Approve/
 * Reject are offered only while pending — never a frontend-invented
 * settlement/debt action. Warehouse Liability must not become a second
 * accounting engine, so this card exposes exactly the two canonical
 * mutations (approve/reject) and nothing else.
 */

// Brings the toBeInTheDocument matcher's TYPE augmentation with it. test-setup.ts
// registers the matchers at runtime, but it is outside tsconfig.app.json, so the
// types are not visible to a type-check that roots at this file.
import '@testing-library/jest-dom/vitest';
import { describe, it, expect, vi, beforeAll } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import i18n from '@/i18n/i18n';
import { WarehouseLiabilityMobileCard } from './warehouse-liability-mobile-card';
import type { WarehouseLiability } from '../types/inventory-count';

// The `inventory-count` namespace loads lazily via i18n's Vite-glob backend
// (src/i18n/i18n.ts) — awaiting it here avoids the render race where `t()`
// resolves to an empty string because the namespace hasn't fetched yet.
beforeAll(async () => {
  await i18n.loadNamespaces('inventory-count');
});

function makeLiability(overrides: Partial<WarehouseLiability> = {}): WarehouseLiability {
  return {
    id: 'lib-1',
    company_id: 'co-1',
    warehouse_id: 'wh-1',
    product_id: 'p-1',
    count_session_id: null,
    count_line_id: null,
    waste_investigation_id: null,
    warehouse_manager: 'Ahmed',
    liability_type: 'waste_transferred',
    quantity: 3,
    unit_cost: 20,
    total_cost: 60,
    status: 'pending',
    approved_by: null,
    approved_at: null,
    notes: null,
    month: '2026-09',
    product: { id: 'p-1', sku: 'SKU-002', name: 'Gadget', image_url: null },
    warehouse: { id: 'wh-1', name: 'Main Warehouse' },
    created_at: '2026-09-01T00:00:00Z',
    cost_snapshot_unit_cost: null,
    cost_snapshot_total_value: null,
    cost_method: null,
    currency: null,
    metadata: null,
    ...overrides,
  };
}

describe('WarehouseLiabilityMobileCard', () => {
  it('renders the same canonical fields as the desktop row', () => {
    const lib = makeLiability();
    render(<WarehouseLiabilityMobileCard liability={lib} onOpen={vi.fn()} onApprove={vi.fn()} onReject={vi.fn()} />);

    expect(screen.getByText('Gadget')).toBeInTheDocument();
    expect(screen.getByText('SKU-002')).toBeInTheDocument();
    expect(screen.getByText('3.00')).toBeInTheDocument();
    expect(screen.getByText('60.00')).toBeInTheDocument();
    expect(screen.getByText('Main Warehouse')).toBeInTheDocument();
    expect(screen.getByText('Ahmed')).toBeInTheDocument();
  });

  it('prefers the FIFO cost snapshot over the raw total when available', () => {
    const lib = makeLiability({ cost_snapshot_total_value: 55.25 });
    render(<WarehouseLiabilityMobileCard liability={lib} onOpen={vi.fn()} onApprove={vi.fn()} onReject={vi.fn()} />);

    expect(screen.getByText('55.25')).toBeInTheDocument();
    expect(screen.queryByText('60.00')).not.toBeInTheDocument();
  });

  it('offers Approve/Reject only while pending — never on a decided liability', () => {
    const pending = makeLiability({ status: 'pending' });
    const { rerender } = render(
      <WarehouseLiabilityMobileCard liability={pending} onOpen={vi.fn()} onApprove={vi.fn()} onReject={vi.fn()} />,
    );
    expect(screen.getByRole('button', { name: /approve/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /reject/i })).toBeInTheDocument();

    const approved = makeLiability({ status: 'approved', approved_by: 'Sara', approved_at: '2026-09-02T00:00:00Z' });
    rerender(<WarehouseLiabilityMobileCard liability={approved} onOpen={vi.fn()} onApprove={vi.fn()} onReject={vi.fn()} />);
    expect(screen.queryByRole('button', { name: /approve/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /reject/i })).not.toBeInTheDocument();
  });

  it('never offers a frontend-created settlement/debt action — only approve/reject/open exist', () => {
    const lib = makeLiability();
    render(<WarehouseLiabilityMobileCard liability={lib} onOpen={vi.fn()} onApprove={vi.fn()} onReject={vi.fn()} />);

    const buttons = screen.getAllByRole('button');
    const names = buttons.map((b) => b.getAttribute('aria-label') ?? b.textContent);
    expect(buttons).toHaveLength(3);
    expect(names.some((n) => /settle|debt|journal|pay/i.test(n ?? ''))).toBe(false);
  });

  it('calls onOpen with the liability when the card is tapped', async () => {
    const user = userEvent.setup();
    const onOpen = vi.fn();
    const lib = makeLiability();
    render(<WarehouseLiabilityMobileCard liability={lib} onOpen={onOpen} onApprove={vi.fn()} onReject={vi.fn()} />);

    await user.click(screen.getByRole('button', { name: /view details/i }));
    expect(onOpen).toHaveBeenCalledWith(lib);
  });

  it('calls onApprove/onReject (not onOpen) when those actions are tapped', async () => {
    const user = userEvent.setup();
    const onOpen = vi.fn();
    const onApprove = vi.fn();
    const lib = makeLiability();
    render(<WarehouseLiabilityMobileCard liability={lib} onOpen={onOpen} onApprove={onApprove} onReject={vi.fn()} />);

    await user.click(screen.getByRole('button', { name: /approve/i }));
    expect(onApprove).toHaveBeenCalledWith(lib);
    expect(onOpen).not.toHaveBeenCalled();
  });
});
