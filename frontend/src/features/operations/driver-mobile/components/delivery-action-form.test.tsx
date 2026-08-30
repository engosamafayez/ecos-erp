import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

// Selector-mode i18n → dotted path, so assertions are stable without booting i18next.
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
    t: (sel: unknown, opts?: { defaultValue?: string }) =>
      typeof sel === 'function'
        ? String((sel as (p: unknown) => unknown)(pathProxy('')))
        : (opts?.defaultValue ?? String(sel)),
    i18n: { language: 'en', exists: () => true },
  }),
}));

import { useFailureReasons } from '../hooks/use-driver-mobile';

vi.mock('../hooks/use-driver-mobile', () => ({
  useFailureReasons: vi.fn(),
}));

import { DeliveryActionForm } from './delivery-action-form';
import type { DeliveryActionPayload } from '../services/driver-mobile-service';

const mockFailureReasons = useFailureReasons as unknown as ReturnType<typeof vi.fn>;

beforeEach(() => {
  vi.clearAllMocks();
  // Fixture uses catalogue value codes only; the component localizes each via
  // t($.failureReasons[value]), so label just mirrors the code (no UI copy here).
  const codes = ['customer_unavailable', 'no_answer'];
  mockFailureReasons.mockReturnValue({
    data: codes.map((value) => ({ value, label: value })),
  });
});

describe('DeliveryActionForm — money collection is frozen (TASK-DRIVER-04 §20)', () => {
  it('Delivered carries NO payment fields and submits only the canonical action payload', () => {
    const onSubmit = vi.fn();
    render(<DeliveryActionForm actionType="completed" onSubmit={onSubmit} onCancel={vi.fn()} />);

    // The frozen money block is gone: no payment/amount UI anywhere.
    expect(screen.queryByText(/collect payment/i)).toBeNull();
    expect(screen.queryByText(/payment method/i)).toBeNull();
    expect(screen.queryByText(/amount/i)).toBeNull();

    // Delivered needs no reason → submit proceeds.
    fireEvent.click(screen.getByRole('button', { name: 'actionForm.confirm' }));
    expect(onSubmit).toHaveBeenCalledTimes(1);
    const payload = onSubmit.mock.calls[0][0] as DeliveryActionPayload;
    expect(payload.action_type).toBe('completed');
    const keys = Object.keys(payload);
    expect(keys).not.toContain('payment_type');
    expect(keys).not.toContain('payment_amount');
    expect(keys).not.toContain('reference_number');
  });
});

describe('DeliveryActionForm — canonical failure reason (TASK-DRIVER-04 §7)', () => {
  it('exposes the canonical FailureReason picker and blocks submit until a reason is chosen', () => {
    const onSubmit = vi.fn();
    render(<DeliveryActionForm actionType="refused" onSubmit={onSubmit} onCancel={vi.fn()} />);

    // Canonical reason picker present (label resolved from i18n, not a hardcoded string).
    expect(screen.getByText('stop.failureReason.label')).toBeInTheDocument();

    // Server-authoritative reason is required — Confirm is disabled with no reason selected.
    expect(screen.getByRole('button', { name: 'actionForm.confirm' })).toBeDisabled();
    expect(onSubmit).not.toHaveBeenCalled();
  });
});

describe('DeliveryActionForm — localized labels (TASK-DRIVER-04 §16)', () => {
  it('renders i18n keys for the action header and controls, never raw English', () => {
    render(<DeliveryActionForm actionType="delay" onSubmit={vi.fn()} onCancel={vi.fn()} />);
    expect(screen.getByText('actions.delay')).toBeInTheDocument();
    expect(screen.getByText('actionForm.newDate')).toBeInTheDocument();
    expect(screen.getByText('actionForm.notes')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'actionForm.cancel' })).toBeInTheDocument();
  });
});
