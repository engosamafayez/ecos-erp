import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

/**
 * TASK-ECOS-IAM-ADMINISTRATION-WORKSPACE-003 — User creation.
 * Service-layer mocked, real useMutation pipeline runs (goods-inward-mode-card.test.tsx idiom).
 *
 * Extended by TASK-ECOS-IAM-FINAL-SOURCE-CAPTURE-INTEGRATION-DEV-CLOSURE-002 for the approved
 * §6-§10 writable Create User contract: initial password, employee lookup (real records, no
 * free text), role assignment, organization scope (real entities), and immediate activation —
 * all in the one submission. `iamDirectoriesService`/`roleTemplatesService` are now real
 * dependencies of this drawer (EmployeeLookupField / OrganizationScopePicker /
 * RoleAssignmentPicker) and are mocked here to deterministic EMPTY states — with an empty
 * organization directory the picker renders its empty state and never mounts its own search
 * textbox, so the identity fields' textbox POSITIONS are unchanged from before this task
 * (name, email, display_name, username, phone — the same five, in the same order; the
 * employee-link field is a lookup button, not a textbox, and password inputs carry no
 * accessible role of "textbox"). None of these tests exercises selecting an actual
 * employee/role/org entity — that belongs to those components' own tests — only that
 * Create User still works end-to-end with the new sections present.
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
const mockEmployees = vi.hoisted(() => vi.fn());
const mockOrganization = vi.hoisted(() => vi.fn());
vi.mock('@/features/iam-admin/services/users-service', () => ({
  usersService: { create: mockCreate },
  iamDirectoriesService: { employees: mockEmployees, organization: mockOrganization },
}));

const mockTemplatesList = vi.hoisted(() => vi.fn());
vi.mock('@/features/iam-admin/services/role-templates-service', () => ({
  roleTemplatesService: { list: mockTemplatesList },
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
  // Deterministic EMPTY states for the three new directory-backed sections. With an
  // empty organization directory, OrganizationScopePicker renders its EmptyState branch
  // and never mounts a search textbox — so it adds zero always-visible textboxes, and the
  // five identity-field positions below are unchanged from before this task.
  mockEmployees.mockResolvedValue({ available: false, data: [] });
  mockOrganization.mockResolvedValue({ levels: [], free_form_types: [] });
  mockTemplatesList.mockResolvedValue([]);
});

describe('UserCreateDrawer', () => {
  // ── 3: create-no-arbitrary-company (ownership, not the §9 organization-scope picker) ──

  it('renders exactly the five identity textboxes — no company OWNERSHIP field anywhere', () => {
    renderDrawer();

    // §9's organization-scope picker legitimately offers "Company" as one of several
    // selectable ORG-SCOPE entity types — a different concern from D2/D3's "no company
    // OWNERSHIP field" — but it is empty in this test (mocked with zero levels) and
    // renders no control at all, so the assertion below is still exactly "no company
    // field of any kind is present", not weakened to tolerate one.
    expect(screen.queryByText(/company/i)).not.toBeInTheDocument();
    // name, email, display_name, username, phone — employee link is a lookup button,
    // not a textbox; password fields carry type="password", not role "textbox".
    expect(screen.getAllByRole('textbox')).toHaveLength(5);
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

  // ── §6-§10: the new provisioning sections are actually present ─────────────────

  it('renders the initial-password, role-assignment, organization-scope and activate sections', () => {
    renderDrawer();

    expect(screen.getByText('users.password.initialSectionTitle')).toBeInTheDocument();
    expect(screen.getByText('users.roles.title')).toBeInTheDocument();
    expect(screen.getByText('users.organization.title')).toBeInTheDocument();
    expect(screen.getByText('users.lifecycle.activateNow')).toBeInTheDocument();
    // §6 — the employee link is a lookup, never a free-text input: no textbox is labeled
    // for it (only the lookup's own trigger button, which the count assertion above
    // already excludes).
    expect(screen.getAllByRole('textbox')).toHaveLength(5);
  });

  it('omits the initial password entirely when left blank — the historical unusable-random-password path', async () => {
    const user = userEvent.setup();
    mockCreate.mockResolvedValue({ id: 1 });
    renderDrawer();

    const [nameInput, emailInput] = screen.getAllByRole('textbox');
    await user.type(nameInput, 'Nour Ibrahim');
    await user.type(emailInput, 'nour@ecos.test');
    await user.click(screen.getByRole('button', { name: 'users.create.submit' }));

    await waitFor(() => expect(mockCreate).toHaveBeenCalled());
    const payload = mockCreate.mock.calls[0][0];
    expect(payload).not.toHaveProperty('password');
    expect(payload).not.toHaveProperty('password_confirmation');
    expect(payload.activate).toBe(false);
    expect(payload.role_templates).toBeUndefined();
    expect(payload.organizations).toBeUndefined();
  });

  it('includes the initial password and activate flag when the admin sets them', async () => {
    const user = userEvent.setup();
    mockCreate.mockResolvedValue({ id: 1 });
    const { container } = renderDrawer();

    const [nameInput, emailInput] = screen.getAllByRole('textbox');
    await user.type(nameInput, 'Nour Ibrahim');
    await user.type(emailInput, 'nour@ecos.test');

    // Password inputs carry type="password" and no accessible name of their own (matching
    // the sibling identity fields' own lack of one in this codebase's FormField — see
    // user-security-panel.test.tsx for the same positional convention on a password
    // field); targeted by their DOM position via the render's own container instead.
    const passwordInputs = container.querySelectorAll<HTMLInputElement>('input[type="password"]');
    expect(passwordInputs).toHaveLength(2);
    await user.type(passwordInputs[0], 'Str0ng!Passw0rd');
    await user.type(passwordInputs[1], 'Str0ng!Passw0rd');

    // The checkbox is nested INSIDE its own <label>...</label> (not FormField's
    // htmlFor-to-wrapper-div pattern), so clicking the label text natively toggles it.
    await user.click(screen.getByText('users.lifecycle.activateNow'));
    await user.click(screen.getByRole('button', { name: 'users.create.submit' }));

    await waitFor(() => expect(mockCreate).toHaveBeenCalled());
    const payload = mockCreate.mock.calls[0][0];
    expect(payload.password).toBe('Str0ng!Passw0rd');
    expect(payload.password_confirmation).toBe('Str0ng!Passw0rd');
    expect(payload.activate).toBe(true);
  });

  // ── 33: 422-field-level ──────────────────────────────────────────────────────

  it('renders field-level errors from a 422 response next to the matching fields', async () => {
    const user = userEvent.setup();
    mockCreate.mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: {
          // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock server-response text; the app displays server messages verbatim (see extractMessage), it does not translate them client-side
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
      // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock server-response text; the app displays server messages verbatim (see extractMessage), it does not translate them client-side
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
