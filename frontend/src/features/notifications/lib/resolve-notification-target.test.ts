import { describe, expect, it } from 'vitest';

import { resolveNotificationTarget } from './resolve-notification-target';

describe('resolveNotificationTarget', () => {
  it('returns null when the notification has no deep link at all', () => {
    expect(resolveNotificationTarget({ deepLink: undefined })).toBeNull();
  });

  it('returns null for an unrecognised entity type rather than guessing a URL', () => {
    expect(
      resolveNotificationTarget({
        deepLink: { entityType: 'wave', entityId: 'wave-1', actionKey: null, route: null },
      }),
    ).toBeNull();
  });

  it('resolves a known entity type (customer) to its real route', () => {
    expect(
      resolveNotificationTarget({
        deepLink: { entityType: 'customer', entityId: 'c-123', actionKey: null, route: null },
      }),
    ).toBe('/customers/c-123');
  });

  it('never falls back to an embedded raw route field — only the allowlisted entity type decides', () => {
    // A raw `route` string could be attacker- or bug-supplied; only entityType is trusted.
    expect(
      resolveNotificationTarget({
        deepLink: { entityType: 'unknown_type', entityId: 'x', actionKey: null, route: '/admin/danger' },
      }),
    ).toBeNull();
  });
});
