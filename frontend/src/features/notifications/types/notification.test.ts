import { describe, expect, it } from 'vitest';

import { toUiNotification, type RawNotification } from './notification';

/**
 * TASK-ECOS-NOTIFICATIONS-FOUNDATION-002 (ADR-047 §24) — the new read-model fields are
 * optional and additive. These tests pin that existing rows (no new columns) keep
 * mapping exactly as before, and new rows (with them) carry priority/category through
 * without disturbing source/severity/message derivation.
 */
describe('toUiNotification', () => {
  const base: RawNotification = {
    id: 'n-1',
    type: 'Modules\\Operations\\Preparation\\Application\\Notifications\\WaveStartedNotification',
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock producer payload text; shown verbatim, never translated (see toUiNotification's own docs above)
    data: { message: 'Wave W-1 started', severity: 'info' },
    read_at: null,
    created_at: '2026-09-04T00:00:00Z',
  };

  it('omits priority/category when the source row has none (pre-extension row, or unmigrated producer)', () => {
    const ui = toUiNotification(base);

    expect(ui).not.toHaveProperty('priority');
    expect(ui).not.toHaveProperty('category');
    expect(ui.source).toBe('operations');
    expect(ui.severity).toBe('info');
    expect(ui.message).toBe('Wave W-1 started');
  });

  it('carries priority/category through when the source row has them', () => {
    const ui = toUiNotification({ ...base, priority: 'low', category: 'alert' });

    expect(ui.priority).toBe('low');
    expect(ui.category).toBe('alert');
    // Existing derivation is unaffected by the new fields being present.
    expect(ui.source).toBe('operations');
    expect(ui.severity).toBe('info');
  });
});
