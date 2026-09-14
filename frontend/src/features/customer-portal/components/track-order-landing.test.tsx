import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import '@testing-library/jest-dom';

/**
 * TASK-ECOS-...-020 §29 items 1-3 — the landing page asks for ONLY order number + email
 * (no phone/SMS option — §25), and the confirmation-driven transition forward is identical
 * regardless of whether the details actually matched (§4/§29 item 3's enumeration-safety proof
 * lives on the backend, already covered in CustomerVerificationSecurityTest; here we prove the
 * frontend never branches its own behaviour on the response content either).
 */
function pathProxy(path: string): unknown {
  const target = () => path;
  return new Proxy(target, {
    get(_t, prop) {
      if (prop === Symbol.toPrimitive || prop === 'toString' || prop === 'valueOf')
        return () => path;
      return pathProxy(path ? `${path}.${String(prop)}` : String(prop));
    },
  });
}
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) =>
      typeof sel === 'function'
        ? String((sel as (p: unknown) => unknown)(pathProxy('')))
        : String(sel),
  }),
}));

const requestVerification = vi.fn();
vi.mock('@/features/customer-portal/services/customer-portal-service', () => ({
  customerPortalService: {
    requestVerification: (...args: unknown[]) => requestVerification(...args),
  },
}));

import { TrackOrderLanding } from './track-order-landing';

function renderLanding(onRequested = vi.fn()) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <TrackOrderLanding onRequested={onRequested} />
    </QueryClientProvider>,
  );
  return { onRequested };
}

describe('TrackOrderLanding', () => {
  beforeEach(() => {
    requestVerification.mockReset();
  });

  it('renders only an order number field and an email field — no phone/SMS option', () => {
    renderLanding();

    expect(screen.getByLabelText('landing.orderNumberLabel')).toBeInTheDocument();
    expect(screen.getByLabelText('landing.emailLabel')).toBeInTheDocument();
    expect(screen.queryByLabelText(/phone/i)).not.toBeInTheDocument();
    expect(screen.queryByPlaceholderText(/phone|sms/i)).not.toBeInTheDocument();
  });

  it('advances to verification identically on a successful request, regardless of what the backend actually matched', async () => {
    requestVerification.mockResolvedValue(undefined);
    const { onRequested } = renderLanding();
    const user = userEvent.setup();

    await user.type(screen.getByLabelText('landing.orderNumberLabel'), 'ORD-ABC12345');
    await user.type(screen.getByLabelText('landing.emailLabel'), 'someone@example.com');
    await user.click(screen.getByRole('button', { name: 'landing.submit' }));

    await waitFor(() => {
      expect(onRequested).toHaveBeenCalledWith({
        orderNumber: 'ORD-ABC12345',
        contact: 'someone@example.com',
      });
    });
    expect(requestVerification).toHaveBeenCalledWith('ORD-ABC12345', 'someone@example.com');
  });

  it('never submits the request when the email is not a plausible email address', async () => {
    const { onRequested } = renderLanding();
    const user = userEvent.setup();

    await user.type(screen.getByLabelText('landing.orderNumberLabel'), 'ORD-ABC12345');
    await user.type(screen.getByLabelText('landing.emailLabel'), 'not-an-email');
    await user.click(screen.getByRole('button', { name: 'landing.submit' }));

    expect(requestVerification).not.toHaveBeenCalled();
    expect(onRequested).not.toHaveBeenCalled();
  });
});
