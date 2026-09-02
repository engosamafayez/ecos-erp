import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

/**
 * TASK-ECOS-IAM-ADMINISTRATION-WORKSPACE-003 — User creation.
 * Service-layer mocked, real useMutation pipeline runs (goods-inward-mode-card.test.tsx idiom).
 */

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

const mockCreate = vi.hoisted(() => vi.fn());
vi.mock('@/features/iam-admin/services/users-service', () => ({
  usersService: { create: mockCreate },
}));

import { UserCreateDrawer } from './user-create-drawer';

function renderDrawer() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const onOpenChange = vi.fn();
  return {
    onOpenChange,
    ...render(
      <QueryClientProvider client={client}>
        <UserCreateDrawer open onOpenChange={onOpenChange} />
      </QueryClientProvider>,
    ),
  };
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe('UserCreateDrawer', () => {
  // ── 3: create-no-arbitrary-company ──────────────────────────────────────────

  it('renders no company field anywhere in the create form (D2/D3: ownership is always server-derived)', () => {
    renderDrawer();

    // Only the documented fields exist: name, email, display name, username, employee
    // number, phone. None of them is a company selector/input of any kind.
    expect(screen.queryByText(/company/i)).not.toBeInTheDocument();
    expect(screen.getAllByRole('textbox').length).toBeLessThanOrEqual(6);
  });

  it('submits without a company_id field in the payload', async () => {
    const user = userEvent.setup();
    mockCreate.mockResolvedValue({ id: 1 });
    renderDrawer();

    const [nameInput, emailInput] = screen.getAllByRole('textbox');
    await user.type(nameInput, 'Nour Ibrahim');
    await user.type(emailInput, 'nour@ecos.test');
    await user.click(screen.getByRole('button', { name: 'users.create.submit' }));

    await waitFor(() => expect(mockCreate).toHaveBeenCalled());
    const payload = mockCreate.mock.calls[0][0];
    expect(payload).not.toHaveProperty('company_id');
    expect(Object.keys(payload)).not.toContain('company_id');
  });

  // ── 33: 422-field-level ──────────────────────────────────────────────────────

  it('renders field-level errors from a 422 response next to the matching fields', async () => {
    const user = userEvent.setup();
    mockCreate.mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: {
          message: 'The given data was invalid.',
          errors: { email: ['The email has already been taken.'] },
        },
      },
    });
    renderDrawer();

    const [nameInput, emailInput] = screen.getAllByRole('textbox');
    await user.type(nameInput, 'Nour Ibrahim');
    await user.type(emailInput, 'taken@ecos.test');
    await user.click(screen.getByRole('button', { name: 'users.create.submit' }));

    expect(await screen.findByText('The email has already been taken.')).toBeInTheDocument();
    // The top-level server message is also shown (not swallowed in favor of only the field error).
    expect(screen.getByText('The given data was invalid.')).toBeInTheDocument();
  });

  it('does not report a field error for a field the 422 response did not name', async () => {
    const user = userEvent.setup();
    mockCreate.mockRejectedValue({
      isAxiosError: true,
      response: { status: 422, data: { message: 'Invalid.', errors: { email: ['Already taken.'] } } },
    });
    renderDrawer();

    const [nameInput, emailInput] = screen.getAllByRole('textbox');
    await user.type(nameInput, 'Nour Ibrahim');
    await user.type(emailInput, 'taken@ecos.test');
    await user.click(screen.getByRole('button', { name: 'users.create.submit' }));

    await screen.findByText('Already taken.');
    expect(screen.queryByText(/name.*required/i)).not.toBeInTheDocument();
  });
});
