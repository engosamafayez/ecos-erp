import { renderHook } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';

import { useAssistantContext } from './use-assistant-context';

/**
 * CORE-03 Task 2 §7 (items 6-8) — route-only context resolution: an order/
 * list page sends module/page only (no fabricated id), a route that genuinely
 * carries an entity id in its own path resolves entity_type/entity_id, and an
 * unrecognized route degrades to route-only context, never a guess.
 */
function renderAt(path: string) {
  return renderHook(() => useAssistantContext(), {
    wrapper: ({ children }) => <MemoryRouter initialEntries={[path]}>{children}</MemoryRouter>,
  });
}

describe('useAssistantContext', () => {
  it('resolves module/page only for a list page with no entity id in the URL', () => {
    const { result } = renderAt('/orders');

    expect(result.current).toEqual({ route: '/orders', module: 'commerce', page: 'orders' });
  });

  it('resolves entity_type/entity_id for a route that genuinely carries one', () => {
    const { result } = renderAt('/customers/customer-42');

    expect(result.current).toMatchObject({
      module: 'crm',
      page: 'customer-detail',
      entity_type: 'customer',
      entity_id: 'customer-42',
    });
  });

  it('resolves a report id from the report-detail route', () => {
    const { result } = renderAt('/reports/RPT-EXEC-01');

    expect(result.current).toMatchObject({
      module: 'reporting',
      page: 'report-detail',
      entity_type: 'report',
      entity_id: 'RPT-EXEC-01',
    });
  });

  it('degrades to route-only context for an unrecognized page, never fabricating an entity', () => {
    const { result } = renderAt('/some/unmapped/page');

    expect(result.current).toEqual({ route: '/some/unmapped/page' });
  });
});
