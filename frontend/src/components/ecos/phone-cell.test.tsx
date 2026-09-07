import '@testing-library/jest-dom';

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-FINAL-USER-REVIEW-REMEDIATION-004.
 *
 * PhoneCell is the shared Call/WhatsApp/Copy authority (Orders, Customers, Suppliers,
 * driver-mobile — see its own docblock). The clipboard/legacy-fallback branching itself
 * is unit-tested in isolation (clipboard.test.ts); here only PhoneCell's OWN wiring is
 * under test — that it calls the shared helper with the right text, surfaces success/
 * error as a toast, and that separate instances never share state.
 */

const mockCopyToClipboard = vi.hoisted(() => vi.fn());
vi.mock('@/lib/clipboard', () => ({ copyToClipboard: mockCopyToClipboard }));

const mockToastSuccess = vi.hoisted(() => vi.fn());
const mockToastError = vi.hoisted(() => vi.fn());
vi.mock('@/components/ds/use-toast', () => ({
  toast: { success: mockToastSuccess, error: mockToastError },
}));

import { PhoneCell } from './phone-cell';

beforeEach(() => {
  vi.clearAllMocks();
  mockCopyToClipboard.mockResolvedValue(true);
});

describe('PhoneCell', () => {
  it('renders a dash and no menu when there is no phone', () => {
    render(<PhoneCell phone={null} />);

    expect(screen.getByText('—')).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('copies the phone and shows a success toast', async () => {
    const user = userEvent.setup();
    render(<PhoneCell phone="0501112222" labels={{ copySuccessTitle: 'Copied it' }} />);

    await user.click(screen.getByText('0501112222'));
    await user.click(await screen.findByText('Copy'));

    expect(mockCopyToClipboard).toHaveBeenCalledWith('0501112222');
    // Radix closes the dropdown on select, so the transient in-menu "Copied!"
    // checkmark (pre-existing, purely cosmetic) may never get a chance to render —
    // the toast is the durable, required success signal and is asserted instead.
    await waitFor(() => expect(mockToastSuccess).toHaveBeenCalledWith('Copied it'));
    expect(mockToastError).not.toHaveBeenCalled();
  });

  it('shows an error toast and no checkmark when copy genuinely fails', async () => {
    mockCopyToClipboard.mockResolvedValue(false);
    const user = userEvent.setup();
    render(<PhoneCell phone="0501112222" labels={{ copyErrorTitle: 'Copy blocked' }} />);

    await user.click(screen.getByText('0501112222'));
    await user.click(await screen.findByText('Copy'));

    await waitFor(() => expect(mockToastError).toHaveBeenCalledWith('Copy blocked'));
    expect(mockToastSuccess).not.toHaveBeenCalled();
    expect(screen.queryByText('Copied!')).not.toBeInTheDocument();
  });

  it('each instance copies its own phone — no shared/stale state between rows', async () => {
    const user = userEvent.setup();
    render(
      <>
        <PhoneCell phone="0501110000" />
        <PhoneCell phone="0502220000" />
      </>,
    );

    await user.click(screen.getByText('0502220000'));
    await user.click(await screen.findByText('Copy'));
    expect(mockCopyToClipboard).toHaveBeenLastCalledWith('0502220000');

    await user.click(screen.getByText('0501110000'));
    await user.click(await screen.findByText('Copy'));
    expect(mockCopyToClipboard).toHaveBeenLastCalledWith('0501110000');
  });

  it('the icon variant copies the same phone as its trigger represents', async () => {
    const user = userEvent.setup();
    render(<PhoneCell phone="0501112222" variant="icon" ariaLabel="Call customer" />);

    await user.click(screen.getByLabelText('Call customer'));
    await user.click(await screen.findByText('Copy'));

    expect(mockCopyToClipboard).toHaveBeenCalledWith('0501112222');
  });
});
