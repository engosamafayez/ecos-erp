import { describe, expect, it } from 'vitest';

import { isDriverOnly, resolvePostLoginPath } from './post-login-landing';
import { ROUTES } from '@/router/routes';
import type { AuthUser } from '@/features/auth/types';

function user(overrides: Partial<AuthUser>): AuthUser {
  return {
    id: 1,
    name: 'Test',
    email: 't@example.com',
    company_id: 'c1',
    permissions: [],
    ...overrides,
  } as AuthUser;
}

describe('resolvePostLoginPath', () => {
  it('sends a driver-only account to the Driver App', () => {
    const driver = user({ permissions: ['logistics.shipping.view', 'loading.driver.operate'] });
    expect(resolvePostLoginPath(driver)).toBe(ROUTES.driverHome);
  });

  it('sends an enterprise user to the dashboard', () => {
    const ops = user({ permissions: ['sales.orders.view', 'inventory.products.view'] });
    expect(resolvePostLoginPath(ops)).toBe(ROUTES.dashboard);
  });

  it('keeps a user who is BOTH driver and enterprise on the dashboard', () => {
    // A dispatcher who is also a driver still runs the ERP — do not trap them.
    const both = user({ permissions: ['loading.driver.operate', 'logistics.distribution.update'] });
    expect(resolvePostLoginPath(both)).toBe(ROUTES.dashboard);
  });

  it('never traps a system user in the driver app', () => {
    const sys = user({ is_system: true, permissions: ['loading.driver.operate'] });
    expect(resolvePostLoginPath(sys)).toBe(ROUTES.dashboard);
  });

  it('defaults to the dashboard when there are no permissions', () => {
    expect(resolvePostLoginPath(user({ permissions: [] }))).toBe(ROUTES.dashboard);
  });

  it('defaults to the dashboard for a null user', () => {
    expect(resolvePostLoginPath(null)).toBe(ROUTES.dashboard);
  });

  // isDriverOnly is the shared predicate behind both the login resolver and the
  // /dashboard deep-link guard — pinned directly so the two entry points cannot diverge.
  describe('isDriverOnly (shared predicate)', () => {
    it('true for a driver-only account', () => {
      expect(isDriverOnly(user({ permissions: ['logistics.shipping.view', 'loading.driver.operate'] }))).toBe(true);
    });
    it('false for an enterprise-only user', () => {
      expect(isDriverOnly(user({ permissions: ['sales.orders.view'] }))).toBe(false);
    });
    it('false for a mixed driver + enterprise user', () => {
      expect(isDriverOnly(user({ permissions: ['loading.driver.operate', 'sales.orders.view'] }))).toBe(false);
    });
    it('false for a system user', () => {
      expect(isDriverOnly(user({ is_system: true, permissions: ['loading.driver.operate'] }))).toBe(false);
    });
    it('false for a null user', () => {
      expect(isDriverOnly(null)).toBe(false);
    });
    it('false when the dispatcher surface is also held', () => {
      expect(isDriverOnly(user({ permissions: ['loading.driver.operate', 'logistics.distribution.update'] }))).toBe(false);
    });
  });

  it('treats the dispatcher surface as enterprise, not driver', () => {
    // logistics.distribution.* is the dispatcher, not a driver capability — the exact
    // permission the DEV RBAC drift wrongly granted drivers.
    const drift = user({ permissions: ['loading.driver.operate', 'logistics.distribution.view'] });
    expect(resolvePostLoginPath(drift)).toBe(ROUTES.dashboard);
  });
});
