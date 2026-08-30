import { describe, expect, it, vi, beforeEach } from 'vitest';

import { loadingOsService } from './loading-os-service';

/**
 * TASK-LOADING-CLOSURE-001 §A.8 — the ACTUAL response contract.
 *
 * ┌─ WHAT BROKE, AND WHY A GUARD WOULD HAVE BEEN WORSE ──────────────────────┐
 * │ `/loading/sessions` is the one paginated endpoint in this service. The     │
 * │ API envelope is `{ success, message, data, errors }`, and because the      │
 * │ controller paginates, its `data` is itself `{ data: [...], meta }` — so    │
 * │ the array sits one level deeper than for the sibling endpoints.           │
 * │                                                                          │
 * │ Reading `data.data` returned the paginator OBJECT and the workspace died  │
 * │ on `sessions.data?.map is not a function`. An `Array.isArray()` guard in   │
 * │ the page would have stopped the crash and rendered an empty session list   │
 * │ forever — a silent failure worse than the loud one.                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * These tests pin the real shapes so the two cannot drift again: sessions nested,
 * assignments flat.
 */

const get = vi.fn();

vi.mock('@/lib/axios', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
  },
}));

describe('loadingOsService.listSessions', () => {
  beforeEach(() => {
    get.mockReset();
  });

  it('reads the array out of the PAGINATED envelope', async () => {
    get.mockResolvedValue({
      data: {
        data: {
          data: [{ id: 's-1' }, { id: 's-2' }],
          meta: { page: 1, per_page: 50, total: 2, last_page: 1 },
        },
      },
    });

    const sessions = await loadingOsService.listSessions();

    expect(Array.isArray(sessions)).toBe(true);
    expect(sessions).toHaveLength(2);
  });

  /** The exact regression: the page maps over this, so it must be an array. */
  it('returns something the workspace can map over', async () => {
    get.mockResolvedValue({
      data: { data: { data: [{ id: 's-1' }], meta: {} } },
    });

    const sessions = await loadingOsService.listSessions();

    expect(() => sessions.map((s) => s.id)).not.toThrow();
  });

  it('returns an empty array when the page is empty, not undefined', async () => {
    get.mockResolvedValue({
      data: { data: { data: [], meta: { page: 1, per_page: 50, total: 0, last_page: 1 } } },
    });

    await expect(loadingOsService.listSessions()).resolves.toEqual([]);
  });

  it('requests the sessions endpoint with a page size', async () => {
    get.mockResolvedValue({ data: { data: { data: [], meta: {} } } });

    await loadingOsService.listSessions();

    expect(get).toHaveBeenCalledTimes(1);
    expect(get.mock.calls[0][0]).toBe('/loading/sessions');
    expect(get.mock.calls[0][1]).toMatchObject({ params: { per_page: 50 } });
  });
});

describe('loadingOsService.listAssignments', () => {
  beforeEach(() => {
    get.mockReset();
  });

  /**
   * The sibling contract, pinned deliberately. `VehicleAssignmentController::index`
   * returns a bare resource collection — NOT paginated — so its array is at `data.data`.
   * Recording both shapes here is what stops a future "fix" from making them uniform and
   * breaking whichever one it did not test.
   */
  it('reads the array from the FLAT envelope', async () => {
    get.mockResolvedValue({ data: { data: [{ id: 'a-1' }, { id: 'a-2' }] } });

    const assignments = await loadingOsService.listAssignments('s-1');

    expect(Array.isArray(assignments)).toBe(true);
    expect(assignments).toHaveLength(2);
  });
});
