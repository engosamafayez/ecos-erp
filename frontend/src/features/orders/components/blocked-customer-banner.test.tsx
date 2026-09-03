/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009-R1 (§5).
 *
 * Focused coverage for the shared BlockedCustomerBanner component, extracted in
 * this remediation so order-detail-drawer.tsx (Orders list / Distribution /
 * Driver Settlement) can reuse the exact same "On Hold — Blocked Customer"
 * context order-detail-page.tsx already showed, instead of a second copy.
 */

import '@testing-library/jest-dom/vitest';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';

import enOrders from '@/i18n/locales/en/orders.json';
import arOrders from '@/i18n/locales/ar/orders.json';

const { activeBundle } = vi.hoisted(() => ({ activeBundle: { current: null as unknown } }));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (selector: (b: unknown) => string) => selector(activeBundle.current),
  }),
}));

import { BlockedCustomerBanner } from './blocked-customer-banner';

type BannerOrder = { status: string; hold_reason_code: string | null; is_blocked_customer_hold?: boolean };

beforeEach(() => {
  activeBundle.current = enOrders;
});

describe('BlockedCustomerBanner', () => {
  it('renders the "still blocked" copy when the hold is live', () => {
    const order = { status: 'on_hold', hold_reason_code: 'blocked_customer', is_blocked_customer_hold: true } as BannerOrder;
    render(<BlockedCustomerBanner order={order as never} />);

    expect(screen.getByText(enOrders.orderDetail.blockedCustomer.bannerTitle)).toBeInTheDocument();
    expect(screen.getByText(enOrders.orderDetail.blockedCustomer.bannerDescription)).toBeInTheDocument();
  });

  it('renders the "overridden" copy once an override has been granted', () => {
    const order = { status: 'on_hold', hold_reason_code: 'blocked_customer', is_blocked_customer_hold: false } as BannerOrder;
    render(<BlockedCustomerBanner order={order as never} />);

    expect(screen.getByText(enOrders.orderDetail.blockedCustomer.bannerTitle)).toBeInTheDocument();
    expect(screen.getByText(enOrders.orderDetail.blockedCustomer.bannerDescriptionOverridden)).toBeInTheDocument();
  });

  it('renders nothing for an unrelated On Hold cause', () => {
    const order = { status: 'on_hold', hold_reason_code: 'shipping_review', is_blocked_customer_hold: false } as BannerOrder;
    const { container } = render(<BlockedCustomerBanner order={order as never} />);

    expect(container).toBeEmptyDOMElement();
  });

  it('renders nothing for a non-on_hold status even if hold_reason_code is stale', () => {
    const order = { status: 'in_progress', hold_reason_code: 'blocked_customer', is_blocked_customer_hold: true } as BannerOrder;
    const { container } = render(<BlockedCustomerBanner order={order as never} />);

    expect(container).toBeEmptyDOMElement();
  });

  it('renders in Arabic without a missing key', () => {
    activeBundle.current = arOrders;
    const order = { status: 'on_hold', hold_reason_code: 'blocked_customer', is_blocked_customer_hold: true } as BannerOrder;
    render(<BlockedCustomerBanner order={order as never} />);

    expect(arOrders.orderDetail.blockedCustomer.bannerTitle).toBeTruthy();
    expect(arOrders.orderDetail.blockedCustomer.bannerTitle).not.toBe(enOrders.orderDetail.blockedCustomer.bannerTitle);
    expect(screen.getByText(arOrders.orderDetail.blockedCustomer.bannerTitle)).toBeInTheDocument();
  });
});
