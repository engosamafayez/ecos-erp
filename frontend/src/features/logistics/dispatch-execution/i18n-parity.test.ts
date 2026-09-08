import { describe, expect, it } from 'vitest';

import en from '@/i18n/locales/en/dispatch-execution.json';
import ar from '@/i18n/locales/ar/dispatch-execution.json';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-003 §22 — EN/AR parity for every key this
 * task added or changed (assignment.* rewritten from a gap card to a real
 * table, trips.* progress/exception/settlement keys).
 */

function keyPaths(value: unknown, prefix = ''): string[] {
  if (value === null || typeof value !== 'object') return [prefix];
  return Object.entries(value as Record<string, unknown>).flatMap(([k, v]) =>
    keyPaths(v, prefix ? `${prefix}.${k}` : k),
  );
}

function leaves(value: unknown, prefix = ''): [string, unknown][] {
  if (value === null || typeof value !== 'object') return [[prefix, value]];
  return Object.entries(value as Record<string, unknown>).flatMap(([k, v]) =>
    leaves(v, prefix ? `${prefix}.${k}` : k),
  );
}

describe('dispatch-execution i18n parity', () => {
  it('has an identical key structure in en and ar', () => {
    const enKeys = keyPaths(en).sort();
    const arKeys = keyPaths(ar).sort();

    expect(enKeys.filter((k) => !arKeys.includes(k))).toEqual([]);
    expect(arKeys.filter((k) => !enKeys.includes(k))).toEqual([]);
  });

  it('has no empty Arabic string for any key the English file defines', () => {
    expect(leaves(ar).filter(([, v]) => v === '')).toEqual([]);
  });
});
