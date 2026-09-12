import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// Selector-mode i18n → resolve t($ => $.a.b.c) to the dotted path string.
// Matches the established pattern in task-status-badge.test.tsx etc.
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
    i18n: { language: 'en', exists: () => true },
  }),
}));

import { PageNoResultsState } from './page-no-results-state';
import { PagePermissionState } from './page-permission-state';

/**
 * TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045, ticket §17 item 9
 * ("compatibility consumer smoke tests for touched components"). These two
 * files became thin re-exports of the new canonical crud/no-results-state and
 * crud/permission-state — this proves the old import path (used today by
 * features/suppliers/pages/suppliers-page.tsx) still renders correctly.
 */
describe('page/states compatibility re-exports', () => {
  it('PageNoResultsState (re-exported) still renders', () => {
    render(<PageNoResultsState />);
    expect(screen.getByText('noResults.title')).toBeInTheDocument();
  });

  it('PagePermissionState (re-exported) still renders', () => {
    render(<PagePermissionState />);
    expect(screen.getByText('permission.accessDenied')).toBeInTheDocument();
  });
});
