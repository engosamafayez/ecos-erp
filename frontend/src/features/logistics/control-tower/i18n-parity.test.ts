import { describe, expect, it } from 'vitest';

import en from '@/i18n/locales/en/control-tower.json';
import ar from '@/i18n/locales/ar/control-tower.json';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-002 §21.12 — Arabic/English label parity.
 * Every English key added or changed this task (sections, activeExecution,
 * needsAttention.headline/loadingNeedsReview/empty.quietTitle) must have a
 * real Arabic counterpart at the identical path — not merely "the file
 * exists". A structural key-path diff catches a forgotten translation even
 * when both files independently parse as valid JSON.
 */

function keyPaths(value: unknown, prefix = ''): string[] {
  if (value === null || typeof value !== 'object') return [prefix];
  return Object.entries(value as Record<string, unknown>).flatMap(([k, v]) =>
    keyPaths(v, prefix ? `${prefix}.${k}` : k),
  );
}

describe('control-tower i18n parity', () => {
  it('has an identical key structure in en and ar', () => {
    const enKeys = keyPaths(en).sort();
    const arKeys = keyPaths(ar).sort();

    const missingFromAr = enKeys.filter((k) => !arKeys.includes(k));
    const missingFromEn = arKeys.filter((k) => !enKeys.includes(k));

    expect(missingFromAr).toEqual([]);
    expect(missingFromEn).toEqual([]);
  });

  it('has no empty Arabic string for any key the English file defines', () => {
    function leaves(value: unknown, prefix = ''): [string, unknown][] {
      if (value === null || typeof value !== 'object') return [[prefix, value]];
      return Object.entries(value as Record<string, unknown>).flatMap(([k, v]) =>
        leaves(v, prefix ? `${prefix}.${k}` : k),
      );
    }

    const emptyArValues = leaves(ar).filter(([, v]) => v === '');
    expect(emptyArValues).toEqual([]);
  });
});
