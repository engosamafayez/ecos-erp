import { describe, expect, it } from 'vitest';

import {
  buildAddressQuery,
  clusterByCoordinate,
  coordinateKey,
  isAddressResolvable,
  REGISTERED_GEOCODER,
  resolveOrderLocation,
} from './order-location';

/**
 * TASK-DISTRIBUTION-MAP-LOCATION-UX-003 §21 — the location resolution layer.
 *
 * The property these tests exist to protect is NEGATIVE: an order that cannot be placed
 * must never be given coordinates. Everything else on the map is cosmetic by comparison
 * — a fabricated pin is indistinguishable from a real delivery address and someone
 * would drive to it.
 */
describe('resolveOrderLocation', () => {
  // §21.1
  it('uses captured coordinates when present', () => {
    expect(resolveOrderLocation({ latitude: 30.05, longitude: 31.25 })).toEqual({
      status: 'RESOLVED_FROM_COORDINATES',
      lat: 30.05,
      lng: 31.25,
    });
  });

  // §21.5 — the one that matters most.
  it('never invents coordinates when there are none', () => {
    const result = resolveOrderLocation({ latitude: null, longitude: null });

    expect(result.status).toBe('UNRESOLVED');
    expect(result.lat).toBeNull();
    expect(result.lng).toBeNull();
  });

  it('treats a half-recorded coordinate as unresolved rather than guessing the other half', () => {
    expect(resolveOrderLocation({ latitude: 30.05, longitude: null }).status).toBe('UNRESOLVED');
    expect(resolveOrderLocation({ latitude: null, longitude: 31.25 }).status).toBe('UNRESOLVED');
  });

  // §21.4 — with no provider registered, address resolution cannot succeed, and the
  // layer says so instead of quietly behaving as if it had tried.
  it('has no geocoding provider registered, so address resolution is unreachable', () => {
    expect(REGISTERED_GEOCODER).toBeNull();
  });
});

describe('buildAddressQuery', () => {
  // §21.2 — a real full address is composed narrow -> wide.
  it('composes the full address a geocoder would need', () => {
    const query = buildAddressQuery({
      address: '2 Shalaby',
      building: '222222',
      apartment: '2',
      area: 'Maadi',
      city_name: 'Cairo',
      city_text: null,
      governorate_name: 'Cairo',
    });

    expect(query).toBe('2 Shalaby, Bldg 222222, Apt 2, Maadi, Cairo, Cairo, Egypt');
  });

  // The explicit anti-requirement from the brief: "Maadi" alone is not an address.
  it('refuses a locality with no street or building', () => {
    expect(
      buildAddressQuery({
        address: null,
        building: null,
        apartment: null,
        area: 'Maadi',
        city_name: 'Cairo',
        city_text: null,
        governorate_name: 'Cairo',
      }),
    ).toBeNull();
  });

  it('refuses a street with no locality — unplaceable on its own', () => {
    expect(
      buildAddressQuery({
        address: '2 Shalaby',
        building: null,
        apartment: null,
        area: null,
        city_name: null,
        city_text: null,
        governorate_name: null,
      }),
    ).toBeNull();
  });

  it('falls back to the raw city text when no city was matched', () => {
    const query = buildAddressQuery({
      address: '2 Shalaby',
      building: null,
      apartment: null,
      area: null,
      city_name: null,
      city_text: 'Nasr City',
      governorate_name: 'Cairo',
    });

    expect(query).toBe('2 Shalaby, Nasr City, Cairo, Egypt');
  });

  it('separates "no address on file" from "address on file, nothing to resolve it with"', () => {
    const thin = {
      address: null,
      building: null,
      apartment: null,
      area: null,
      city_name: null,
      city_text: null,
      governorate_name: null,
    };
    const full = { ...thin, address: '2 Shalaby', city_name: 'Cairo' };

    expect(isAddressResolvable(thin)).toBe(false);
    expect(isAddressResolvable(full)).toBe(true);
  });
});

describe('clusterByCoordinate', () => {
  it('keeps a lone order as a cluster of one at its exact coordinate', () => {
    const clusters = clusterByCoordinate([
      { order_id: 'a', latitude: 30.0194554, longitude: 31.4838207 },
    ]);

    expect(clusters).toHaveLength(1);
    expect(clusters[0].orders.map((o) => o.order_id)).toEqual(['a']);
    expect(clusters[0].lat).toBe(30.0194554);
    expect(clusters[0].lng).toBe(31.4838207);
  });

  // The core requirement: six orders at one point are ONE marker of six, none lost.
  it('merges every order sharing an exact coordinate into a single cluster', () => {
    const shared = { latitude: 30.0194554, longitude: 31.4838207 };
    const clusters = clusterByCoordinate([
      { order_id: 'ORD-00001', ...shared },
      { order_id: 'ORD-00009', ...shared },
      { order_id: 'ORD-00012', ...shared },
      { order_id: 'ORD-00016', ...shared },
      { order_id: 'ORD-00018', ...shared },
      { order_id: 'ORD-00019', ...shared },
    ]);

    expect(clusters).toHaveLength(1);
    expect(clusters[0].orders).toHaveLength(6);
    expect(clusters[0].orders.map((o) => o.order_id)).toEqual([
      'ORD-00001',
      'ORD-00009',
      'ORD-00012',
      'ORD-00016',
      'ORD-00018',
      'ORD-00019',
    ]);
  });

  it('never merges genuinely different coordinates', () => {
    const clusters = clusterByCoordinate([
      { order_id: 'a', latitude: 30.05, longitude: 31.25 },
      { order_id: 'b', latitude: 31.2, longitude: 29.9 },
    ]);

    expect(clusters).toHaveLength(2);
    expect(clusters.every((c) => c.orders.length === 1)).toBe(true);
  });

  // Points that differ within the coordinate's own precision are distinct places.
  it('keeps points that differ before the precision limit separate', () => {
    const clusters = clusterByCoordinate([
      { order_id: 'a', latitude: 30.000001, longitude: 31.0 },
      { order_id: 'b', latitude: 30.000002, longitude: 31.0 },
    ]);

    expect(clusters).toHaveLength(2);
  });

  it('aggregates by coordinate only — never by object identity', () => {
    const shared = { latitude: 30.05, longitude: 31.25 };
    const clusters = clusterByCoordinate([
      { order_id: 'a', ...shared },
      { order_id: 'b', ...shared },
    ]);

    expect(clusters).toHaveLength(1);
    expect(clusters[0].orders).toHaveLength(2);
  });

  it('is deterministic across runs, so markers do not reshuffle under the cursor', () => {
    const input = [
      { order_id: 'a', latitude: 30.05, longitude: 31.25 },
      { order_id: 'b', latitude: 30.05, longitude: 31.25 },
      { order_id: 'c', latitude: 30.05, longitude: 31.25 },
    ];

    expect(clusterByCoordinate(input)).toEqual(clusterByCoordinate(input));
  });

  it('skips orders with no coordinates rather than placing them somewhere', () => {
    const clusters = clusterByCoordinate([
      { order_id: 'a', latitude: 30.05, longitude: 31.25 },
      { order_id: 'nowhere', latitude: null, longitude: null },
    ]);

    expect(clusters.flatMap((c) => c.orders).map((o) => o.order_id)).toEqual(['a']);
  });
});

describe('coordinateKey', () => {
  it('is identical for identical coordinates and differs otherwise', () => {
    expect(coordinateKey(30.0194554, 31.4838207)).toBe(coordinateKey(30.0194554, 31.4838207));
    expect(coordinateKey(30.0194554, 31.4838207)).not.toBe(coordinateKey(30.0194555, 31.4838207));
  });
});
