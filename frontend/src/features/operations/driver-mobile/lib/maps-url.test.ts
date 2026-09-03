import { describe, expect, it } from 'vitest';

import { buildMapsUrl } from './maps-url';

describe('buildMapsUrl (§5/§C)', () => {
  it('builds a maps link from a real GPS fix', () => {
    expect(buildMapsUrl({ lat: 30.05, lng: 31.23 })).toBe('https://maps.google.com/?q=30.05,31.23');
  });

  it('returns null when gps is absent — the honest unavailable state, not a fake link', () => {
    expect(buildMapsUrl(null)).toBeNull();
    expect(buildMapsUrl(undefined)).toBeNull();
  });
});
