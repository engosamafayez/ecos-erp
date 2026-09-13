import { useRef } from 'react';
import { render, screen } from '@testing-library/react';
// Redundant with src/test-setup.ts's global setup (harmless — side-effect imports are
// idempotent) but needed here so the pre-commit Guardian's staged-file-scoped type-check,
// which only follows a file's own transitive imports, sees the jest-dom matcher typings too.
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import type { BusinessAccount } from '@/features/business-accounts/types/business-account';

/**
 * Regression test for the same "opening an account crashes with React error #310" defect
 * class fixed in BrandDetailDrawer (see brand-detail-drawer.test.tsx).
 *
 * Root cause: BusinessAccountDetailDrawer had `if (!account) return null` BEFORE its
 * useChannelsQuery hook. The drawer stays mounted at all times in BusinessAccountsPage (only
 * its `account`/`open` props change), so the first time an operator opened an account —
 * `activeAccount` flipping from null to a real BusinessAccount on the SAME component
 * instance — the hook count went from 0 to 1, violating the Rules of Hooks ("Rendered more
 * hooks than during the previous render").
 *
 * This test reproduces exactly that transition — render once with account=null, then
 * rerender the same instance with a real account — and asserts it does not throw.
 */

// Mock below deliberately still calls a REAL React hook (useRef) internally, matching the
// real useOrganizationContext (which calls a real hook under the hood) — a mock that calls
// zero real hooks would make the very first render register zero hooks with React, which
// does not reproduce the rules-of-hooks mismatch this test exists to catch.
vi.mock('@/features/organization/context/organization-context', () => ({
  useOrganizationContext: () => {
    useRef(null);
    return { activeCompanyId: 'company-1' };
  },
}));

const emptyList = vi.hoisted(() => ({ items: [], meta: { total: 0, current_page: 1, per_page: 50, last_page: 1 } }));

vi.mock('@/features/channels/services/channels-service', () => ({
  channelsService: { list: vi.fn().mockResolvedValue(emptyList) },
}));

import { BusinessAccountDetailDrawer } from './business-account-detail-drawer';

const ACCOUNT: BusinessAccount = {
  id: 'account-1',
  company_id: 'company-1',
  company: { id: 'company-1', code: 'CO-1', name: 'Acme Co' },
  brand_id: null,
  brand: null,
  code: 'BA-1',
  name: 'Acme Meta Account',
  provider: 'Meta',
  status: 'active',
  description: null,
  logo: null,
  oauth_config: null,
  api_keys: null,
  webhook_config: null,
  sync_settings: null,
  external_metadata: null,
  created_at: '2024-01-01T00:00:00Z',
  updated_at: '2024-01-01T00:00:00Z',
};

function renderDrawer(account: BusinessAccount | null, open: boolean) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const utils = render(
    <QueryClientProvider client={client}>
      <BusinessAccountDetailDrawer account={account} open={open} onOpenChange={() => undefined} />
    </QueryClientProvider>,
  );
  return { client, ...utils };
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe('BusinessAccountDetailDrawer', () => {
  it('renders nothing while no account is selected', () => {
    const { container } = renderDrawer(null, false);
    expect(container).toBeEmptyDOMElement();
  });

  it('does not crash when account flips from null to a real account on the same mounted instance (regression for React error #310)', () => {
    const { rerender, container, client } = renderDrawer(null, false);
    expect(container).toBeEmptyDOMElement();

    // This exact re-render — same component instance, same QueryClient, account going
    // null -> non-null while `open` also flips true — is what previously threw "Rendered
    // more hooks than during the previous render" because the query hook was gated behind
    // `if (!account) return null`.
    expect(() => {
      rerender(
        <QueryClientProvider client={client}>
          <BusinessAccountDetailDrawer account={ACCOUNT} open={true} onOpenChange={() => undefined} />
        </QueryClientProvider>,
      );
    }).not.toThrow();

    // Account name legitimately appears twice (header + Overview detail row) — the point of
    // this assertion is only that rendering completed without throwing, above.
    expect(screen.getAllByText('Acme Meta Account').length).toBeGreaterThan(0);
  });

  it('renders account details once open with an account selected', () => {
    renderDrawer(ACCOUNT, true);

    expect(screen.getAllByText('Acme Meta Account').length).toBeGreaterThan(0);
    expect(screen.getAllByText('BA-1').length).toBeGreaterThan(0);
  });
});
