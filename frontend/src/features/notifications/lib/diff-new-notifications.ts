/**
 * ADR-047 §26.7 "Realtime Arrival Sequence" — with no realtime transport in V1, "arrival"
 * means the next poll tick reveals a row this session has not observed before.
 *
 * Pure and side-effect free on purpose: the only way to guarantee a notification pops at
 * most once (per this task's own required test coverage) is a function that can be
 * exhaustively tested without mounting React, a timer, or a network mock.
 */

/**
 * Returns the ids present in `currentIds` but absent from `knownIds`, in the order they
 * appear in `currentIds`. Does not mutate `knownIds` — the caller decides when (and
 * whether) to fold the result back in, so a caller can inspect "what's new" before
 * committing to having seen it.
 */
export function findNewlyArrivedIds(knownIds: ReadonlySet<string>, currentIds: readonly string[]): string[] {
  return currentIds.filter((id) => !knownIds.has(id));
}
