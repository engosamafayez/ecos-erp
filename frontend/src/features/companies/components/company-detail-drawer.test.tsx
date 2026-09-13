import { useRef } from 'react';
import { render, screen } from '@testing-library/react';
// Redundant with src/test-setup.ts's global setup (harmless — side-effect imports are
// idempotent) but needed here so the pre-commit Guardian's staged-file-scoped type-check,
// which only follows a file's own transitive imports, sees the jest-dom matcher typings too.
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import type { Company } from '@/features/companies/types/company';

/**
 * Regression test for the same "opening a company crashes with React error #310" defect
 * class fixed in BrandDetailDrawer (see brand-detail-drawer.test.tsx).
 *
 * Root cause: CompanyDetailDrawer had `if (!company) return null` BEFORE its three
 * relationship query hooks (useBrandsQuery/useWarehousesQuery/useTeamsQuery). The drawer
 * stays mounted at all times in CompaniesPage (only its `company`/`open` props change), so
 * the first time an operator opened a company — `viewCompany` flipping from null to a real
 * Company on the SAME component instance — the hook count went from 0 to 3, violating the
 * Rules of Hooks ("Rendered more hooks than during the previous render").
 *
 * This test reproduces exactly that transition — render once with company=null, then
 * rerender the same instance with a real company — and asserts it does not throw.
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

vi.mock('@/features/brands/services/brands-service', () => ({
  brandsService: { list: vi.fn().mockResolvedValue(emptyList) },
}));
vi.mock('@/features/warehouses/services/warehouses-service', () => ({
  warehousesService: { list: vi.fn().mockResolvedValue(emptyList) },
}));
vi.mock('@/features/teams/services/teams-service', () => ({
  teamsService: { list: vi.fn().mockResolvedValue(emptyList) },
}));

import { CompanyDetailDrawer } from './company-detail-drawer';

const COMPANY: Company = {
  id: 'company-1',
  code: 'CO-1',
  name: 'Acme Co',
  legal_name: null,
  tax_number: null,
  commercial_registration: null,
  email: null,
  phone: null,
  mobile: null,
  website: null,
  currency: null,
  timezone: null,
  language: null,
  locale: null,
  date_format: null,
  number_format: null,
  week_start: null,
  fiscal_year_start: null,
  fiscal_year_end: null,
  description: null,
  country: null,
  city: null,
  address: null,
  postal_code: null,
  logo: null,
  is_active: true,
  brands_count: 0,
  warehouses_count: 0,
  teams_count: 0,
  channels_count: 0,
  business_accounts_count: 0,
  created_at: null,
  updated_at: null,
};

function renderDrawer(company: Company | null, open: boolean) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const utils = render(
    <QueryClientProvider client={client}>
      <CompanyDetailDrawer company={company} open={open} onOpenChange={() => undefined} />
    </QueryClientProvider>,
  );
  return { client, ...utils };
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe('CompanyDetailDrawer', () => {
  it('renders nothing while no company is selected', () => {
    const { container } = renderDrawer(null, false);
    expect(container).toBeEmptyDOMElement();
  });

  it('does not crash when company flips from null to a real company on the same mounted instance (regression for React error #310)', () => {
    const { rerender, container, client } = renderDrawer(null, false);
    expect(container).toBeEmptyDOMElement();

    // This exact re-render — same component instance, same QueryClient, company going
    // null -> non-null while `open` also flips true — is what previously threw "Rendered
    // more hooks than during the previous render" because the query hooks were gated
    // behind `if (!company) return null`.
    expect(() => {
      rerender(
        <QueryClientProvider client={client}>
          <CompanyDetailDrawer company={COMPANY} open={true} onOpenChange={() => undefined} />
        </QueryClientProvider>,
      );
    }).not.toThrow();

    // Company name legitimately appears twice (header + Overview detail row) — the point of
    // this assertion is only that rendering completed without throwing, above.
    expect(screen.getAllByText('Acme Co').length).toBeGreaterThan(0);
  });

  it('renders company details once open with a company selected', () => {
    renderDrawer(COMPANY, true);

    expect(screen.getAllByText('Acme Co').length).toBeGreaterThan(0);
    expect(screen.getAllByText('CO-1').length).toBeGreaterThan(0);
  });
});
