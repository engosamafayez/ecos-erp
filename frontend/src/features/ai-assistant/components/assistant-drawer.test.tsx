import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import axios from 'axios';
import { describe, expect, it, vi, beforeEach } from 'vitest';

// Redundant with src/test-setup.ts's global setup (harmless — side-effect imports are
// idempotent) but needed here so the pre-commit Guardian's staged-file-scoped type-check,
// which only follows a file's own transitive imports, sees the jest-dom matcher typings too.
import '@testing-library/jest-dom';
import { LanguageContext } from '@/providers/language-context';

/**
 * CORE-03 Task 2 §5/§27 — the contextual drawer: context chip, bounded
 * history, suggested prompts, honest status rendering (denied/unavailable/
 * tool_limit_reached), transport-error handling (422/429/generic), trusted
 * entity references, working state, company-switch isolation, and
 * Arabic/English/mixed content.
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

let mockCompanyId = 'company-1';
vi.mock('@/features/organization/context/organization-context', () => ({
  useOrganizationContext: () => ({ activeCompanyId: mockCompanyId }),
}));

const sendMessageMock = vi.hoisted(() => vi.fn());
vi.mock('@/features/ai-assistant/services/assistant-service', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../services/assistant-service')>();
  return {
    ...actual,
    assistantService: { sendMessage: sendMessageMock },
  };
});

import { AssistantDrawer } from './assistant-drawer';

function renderDrawer(path = '/orders') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <LanguageContext.Provider value={{ language: 'en', dir: 'ltr', setLanguage: () => {} }}>
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={[path]}>
          <AssistantDrawer open onOpenChange={() => {}} />
        </MemoryRouter>
      </QueryClientProvider>
    </LanguageContext.Provider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  mockCompanyId = 'company-1';
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock assistant API response fixture, never rendered through i18n
  sendMessageMock.mockResolvedValue({ status: 'ok', message: 'Sure, here you go.', references: [] });
});

describe('AssistantDrawer', () => {
  it('renders the current context chip and an empty state with no conversation yet', () => {
    renderDrawer('/orders');

    expect(screen.getByText('context.label:')).toBeInTheDocument();
    expect(screen.getByText('empty.title')).toBeInTheDocument();
  });

  it('sends a typed message and renders both the user turn and the assistant reply', async () => {
    const user = userEvent.setup();
    renderDrawer();

    await user.type(screen.getByLabelText('drawer.composerLabel'), 'Why is this order stuck?');
    await user.click(screen.getByRole('button', { name: 'drawer.send' }));

    expect(await screen.findByText('Why is this order stuck?')).toBeInTheDocument();
    expect(await screen.findByText('Sure, here you go.')).toBeInTheDocument();
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- asserting the request payload built from the simulated user input above, not UI copy
    expect(sendMessageMock).toHaveBeenCalledWith(expect.objectContaining({ message: 'Why is this order stuck?', module: 'commerce', page: 'orders' }));
  });

  it('shows a working indicator while the request is pending', async () => {
    let resolveFn: (value: unknown) => void = () => {};
    sendMessageMock.mockReturnValue(new Promise((resolve) => { resolveFn = resolve; }));
    const user = userEvent.setup();
    renderDrawer();

    await user.type(screen.getByLabelText('drawer.composerLabel'), 'hi');
    await user.click(screen.getByRole('button', { name: 'drawer.send' }));

    expect(await screen.findByText('status.working')).toBeInTheDocument();

    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock assistant API response fixture, never rendered through i18n
    resolveFn({ status: 'ok', message: 'done', references: [] });
    await waitFor(() => expect(screen.queryByText('status.working')).not.toBeInTheDocument());
  });

  it('selecting a suggested prompt sends it as a user message', async () => {
    const user = userEvent.setup();
    renderDrawer('/orders');

    // Mocked t() resolves a selector to its dotted key path, so the rendered
    // prompt button's accessible name is "suggestedPrompts.commerce1" here —
    // not the real English copy.
    await user.click(screen.getByRole('button', { name: 'suggestedPrompts.commerce1' }));

    expect(await screen.findByText('suggestedPrompts.commerce1')).toBeInTheDocument();
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- asserting the request payload built from the mocked prompt key above, not UI copy
    expect(sendMessageMock).toHaveBeenCalledWith(expect.objectContaining({ message: 'suggestedPrompts.commerce1' }));
  });

  it('renders an honest denied state, not a generic success message', async () => {
    sendMessageMock.mockResolvedValue({ status: 'denied', message: null, references: [] });
    const user = userEvent.setup();
    renderDrawer();

    await user.type(screen.getByLabelText('drawer.composerLabel'), 'hi');
    await user.click(screen.getByRole('button', { name: 'drawer.send' }));

    expect(await screen.findByText('status.denied')).toBeInTheDocument();
  });

  it('renders an honest unavailable state', async () => {
    sendMessageMock.mockResolvedValue({ status: 'unavailable', message: null, references: [] });
    const user = userEvent.setup();
    renderDrawer();

    await user.type(screen.getByLabelText('drawer.composerLabel'), 'hi');
    await user.click(screen.getByRole('button', { name: 'drawer.send' }));

    expect(await screen.findByText('status.unavailable')).toBeInTheDocument();
  });

  it('renders a bounded tool_limit_reached state rather than pretending the answer is complete', async () => {
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock assistant API response fixture, never rendered through i18n
    sendMessageMock.mockResolvedValue({ status: 'tool_limit_reached', message: 'partial info', references: [] });
    const user = userEvent.setup();
    renderDrawer();

    await user.type(screen.getByLabelText('drawer.composerLabel'), 'hi');
    await user.click(screen.getByRole('button', { name: 'drawer.send' }));

    expect(await screen.findByText('partial info')).toBeInTheDocument();
    expect(await screen.findByText('status.toolLimitReached')).toBeInTheDocument();
  });

  it('renders a validation-error banner on HTTP 422 without crashing', async () => {
    sendMessageMock.mockRejectedValue(Object.assign(new axios.AxiosError('bad'), { response: { status: 422 } }));
    const user = userEvent.setup();
    renderDrawer();

    await user.type(screen.getByLabelText('drawer.composerLabel'), 'hi');
    await user.click(screen.getByRole('button', { name: 'drawer.send' }));

    expect(await screen.findByText('status.validationError')).toBeInTheDocument();
  });

  it('renders a rate-limit banner on HTTP 429', async () => {
    sendMessageMock.mockRejectedValue(Object.assign(new axios.AxiosError('slow down'), { response: { status: 429 } }));
    const user = userEvent.setup();
    renderDrawer();

    await user.type(screen.getByLabelText('drawer.composerLabel'), 'hi');
    await user.click(screen.getByRole('button', { name: 'drawer.send' }));

    expect(await screen.findByText('status.rateLimited')).toBeInTheDocument();
  });

  it('renders a generic error banner for an unexpected failure without crashing the shell', async () => {
    sendMessageMock.mockRejectedValue(new Error('boom'));
    const user = userEvent.setup();
    renderDrawer();

    await user.type(screen.getByLabelText('drawer.composerLabel'), 'hi');
    await user.click(screen.getByRole('button', { name: 'drawer.send' }));

    expect(await screen.findByText('status.genericError')).toBeInTheDocument();
  });

  it('renders server-generated entity references as trusted links, never from model prose', async () => {
    sendMessageMock.mockResolvedValue({
      status: 'ok',
      // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock assistant API response fixture, never rendered through i18n
      message: 'Here is the order.',
      // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock server-generated entity reference fixture, never rendered through i18n
      references: [{ type: 'order', id: 'order-1', label: 'Order #123', route: '/orders?open=order-1' }],
    });
    const user = userEvent.setup();
    renderDrawer();

    await user.type(screen.getByLabelText('drawer.composerLabel'), 'hi');
    await user.click(screen.getByRole('button', { name: 'drawer.send' }));

    const link = await screen.findByRole('link', { name: /Order #123/ });
    expect(link).toHaveAttribute('href', '/orders?open=order-1');
  });

  it('bounds the resent history to the client-side cap', async () => {
    const user = userEvent.setup();
    renderDrawer();

    for (let i = 0; i < 12; i++) {
      await user.type(screen.getByLabelText('drawer.composerLabel'), `msg ${i}`);
      await user.click(screen.getByRole('button', { name: 'drawer.send' }));
      await waitFor(() => expect(screen.getAllByText('Sure, here you go.').length).toBe(i + 1));
    }

    const lastCallHistory = sendMessageMock.mock.calls.at(-1)?.[0].history;
    expect(lastCallHistory.length).toBeLessThanOrEqual(10);
  });

  it('clears the conversation when the active company changes', async () => {
    const user = userEvent.setup();
    const { rerender } = renderDrawer();

    await user.type(screen.getByLabelText('drawer.composerLabel'), 'hi');
    await user.click(screen.getByRole('button', { name: 'drawer.send' }));
    expect(await screen.findByText('hi')).toBeInTheDocument();

    mockCompanyId = 'company-2';
    rerender(
      <LanguageContext.Provider value={{ language: 'en', dir: 'ltr', setLanguage: () => {} }}>
        <QueryClientProvider client={new QueryClient()}>
          <MemoryRouter initialEntries={['/orders']}>
            <AssistantDrawer open onOpenChange={() => {}} />
          </MemoryRouter>
        </QueryClientProvider>
      </LanguageContext.Provider>,
    );

    await waitFor(() => expect(screen.queryByText('hi')).not.toBeInTheDocument());
  });

  it('renders Egyptian Arabic, English, and mixed content correctly', async () => {
    // eslint-disable-next-line ecos-i18n/no-arabic-literals -- mock assistant API response fixture, never rendered through i18n
    sendMessageMock.mockResolvedValue({ status: 'ok', message: 'Stock available: 12 units متاح', references: [] });
    const user = userEvent.setup();
    renderDrawer();

    // eslint-disable-next-line ecos-i18n/no-arabic-literals -- simulated user-typed Egyptian Arabic input, never rendered through i18n
    await user.type(screen.getByLabelText('drawer.composerLabel'), 'الأوردر ده واقف ليه؟');
    await user.click(screen.getByRole('button', { name: 'drawer.send' }));

    // eslint-disable-next-line ecos-i18n/no-arabic-literals -- asserting on the simulated user input above, not UI copy
    expect(await screen.findByText('الأوردر ده واقف ليه؟')).toBeInTheDocument();
    // eslint-disable-next-line ecos-i18n/no-arabic-literals -- asserting on the mock assistant reply fixture above, not UI copy
    expect(await screen.findByText('Stock available: 12 units متاح')).toBeInTheDocument();
  });
});
