import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AxiosError, AxiosHeaders } from 'axios';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import '@testing-library/jest-dom';

/**
 * TASK-ECOS-...-020 §29 items 4-7 — invalid/expired codes are handled safely (no stack trace,
 * no internal detail, no claim of validity before the server confirms it), and a successful
 * verification hands the raw token to the session (never rendered, never logged — asserted via
 * the mocked hook's own call, not by inspecting the DOM for a token string that should never
 * appear there in the first place).
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

const startSession = vi.fn();
vi.mock('@/features/customer-portal/context/tracking-session-context', () => ({
  useTrackingSession: () => ({ isVerified: false, startSession, endSession: vi.fn() }),
}));

const verify = vi.fn();
vi.mock('@/features/customer-portal/services/customer-portal-service', () => ({
  customerPortalService: { verify: (...args: unknown[]) => verify(...args) },
}));

import { VerifyCodeForm } from './verify-code-form';

function renderForm() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <VerifyCodeForm orderNumber="ORD-ABC12345" contact="someone@example.com" onBack={vi.fn()} />
    </QueryClientProvider>,
  );
}

describe('VerifyCodeForm', () => {
  beforeEach(() => {
    verify.mockReset();
    startSession.mockReset();
  });

  it('shows a generic invalid-code message on 422, never a stack trace or internal detail', async () => {
    const error = new AxiosError('Request failed with status code 422');
    error.response = {
      data: {},
      status: 422,
      statusText: 'Unprocessable Content',
      headers: new AxiosHeaders(),
      config: { headers: new AxiosHeaders() },
    };
    verify.mockRejectedValue(error);
    renderForm();
    const user = userEvent.setup();

    await user.type(screen.getByLabelText('verify.codeLabel'), '123456');
    await user.click(screen.getByRole('button', { name: 'verify.submit' }));

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent('verify.invalidCode');
    });
    expect(screen.queryByText(/axios|stack|exception/i)).not.toBeInTheDocument();
  });

  it('starts the tracking session only after the server confirms the code', async () => {
    verify.mockResolvedValue({ token: 'raw-secret-token', expiresAt: '2030-01-01T00:00:00Z' });
    renderForm();
    const user = userEvent.setup();

    expect(startSession).not.toHaveBeenCalled();

    await user.type(screen.getByLabelText('verify.codeLabel'), '654321');
    await user.click(screen.getByRole('button', { name: 'verify.submit' }));

    await waitFor(() => {
      expect(startSession).toHaveBeenCalledWith('raw-secret-token', '2030-01-01T00:00:00Z');
    });
    // The raw token is handed to the session store — it must never be rendered in the DOM.
    expect(screen.queryByText('raw-secret-token')).not.toBeInTheDocument();
  });
});
