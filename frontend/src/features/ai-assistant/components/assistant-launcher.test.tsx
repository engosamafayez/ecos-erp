import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';

// Redundant with src/test-setup.ts's global setup (harmless — side-effect imports are
// idempotent) but needed here so the pre-commit Guardian's staged-file-scoped type-check,
// which only follows a file's own transitive imports, sees the jest-dom matcher typings too.
import '@testing-library/jest-dom';
import { LanguageContext } from '@/providers/language-context';

/**
 * CORE-03 Task 2 §4/§13/§27 (items 1-4) — the AppShell entry point: visible
 * only with ai.assistant.use, opens/closes the drawer correctly.
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

let mockCan = true;
vi.mock('@/features/authorization', () => ({
  usePermission: () => ({ can: () => mockCan }),
}));

vi.mock('@/features/organization/context/organization-context', () => ({
  useOrganizationContext: () => ({ activeCompanyId: 'company-1' }),
}));

vi.mock('@/features/ai-assistant/services/assistant-service', () => ({
  assistantService: { sendMessage: vi.fn() },
  isValidationError: () => false,
  isRateLimitedError: () => false,
  isAuthError: () => false,
}));

import { AssistantLauncher } from './assistant-launcher';

function renderLauncher() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <LanguageContext.Provider value={{ language: 'en', dir: 'ltr', setLanguage: () => {} }}>
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={['/orders']}>
          <AssistantLauncher />
        </MemoryRouter>
      </QueryClientProvider>
    </LanguageContext.Provider>,
  );
}

describe('AssistantLauncher', () => {
  it('renders when the user has ai.assistant.use', () => {
    mockCan = true;
    renderLauncher();

    expect(screen.getByRole('button', { name: 'launcher.ariaLabel' })).toBeInTheDocument();
  });

  it('renders nothing when the user lacks ai.assistant.use', () => {
    mockCan = false;
    const { container } = renderLauncher();

    expect(container).toBeEmptyDOMElement();
  });

  it('opens the assistant drawer when clicked', async () => {
    mockCan = true;
    const user = userEvent.setup();
    renderLauncher();

    await user.click(screen.getByRole('button', { name: 'launcher.ariaLabel' }));

    expect(await screen.findByText('drawer.title')).toBeInTheDocument();
  });
});
