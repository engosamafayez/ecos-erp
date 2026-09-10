import { useTranslation } from 'react-i18next';

import type enLogistics from '@/i18n/locales/en/logistics.json';

import type { ExceptionCategory, ExceptionSource } from '../types/operations';

/**
 * A label held as an i18next selector rather than a key string.
 *
 * Selector mode has no type for a key chosen at runtime, so a table of
 * key strings can never type-check. The selector is the same expression
 * the compiler validates at an inline call site, kept in the table.
 */
type LogisticsLabel = ($: typeof enLogistics) => string;

/** Which module owns the fact behind an exception — shared with `SourceBadge`. */
export const SOURCE: Record<ExceptionSource, LogisticsLabel> = {
  fleet: ($) => $.operations.badges.source.fleet,
  drivers: ($) => $.operations.badges.source.drivers,
  network: ($) => $.operations.badges.source.network,
  dispatch: ($) => $.operations.badges.source.dispatch,
  routing: ($) => $.operations.badges.source.routing,
  carriers: ($) => $.operations.badges.source.carriers,
  distribution: ($) => $.operations.badges.source.distribution,
  delivery: ($) => $.operations.badges.source.delivery,
  operations: ($) => $.operations.badges.source.operations,
};

const CATEGORY: Record<ExceptionCategory, LogisticsLabel> = {
  resource: ($) => $.operations.badges.category.resource,
  capacity: ($) => $.operations.badges.category.capacity,
  dispatch: ($) => $.operations.badges.category.dispatch,
  routing: ($) => $.operations.badges.category.routing,
  execution: ($) => $.operations.badges.category.execution,
  carrier: ($) => $.operations.badges.category.carrier,
  integration: ($) => $.operations.badges.category.integration,
  policy: ($) => $.operations.badges.category.policy,
};

/**
 * What kind of problem an exception is — distinct from `SourceBadge` (which
 * module owns the fix). Exposed as a hook (not a component, since callers mix
 * it into plain text rather than a badge) so it never renders the backend's
 * raw `category_label` string directly. Kept in its own module (not
 * `operations-badges.tsx`, which exports components) so Fast Refresh stays
 * intact — a file mixing component and hook exports breaks it.
 */
export function useExceptionCategoryLabel() {
  const { t } = useTranslation('logistics');
  return (category: ExceptionCategory) => t(CATEGORY[category]);
}

/** Same translated map `SourceBadge` renders, exposed for plain-text (non-badge) uses. */
export function useExceptionSourceLabel() {
  const { t } = useTranslation('logistics');
  return (source: ExceptionSource) => t(SOURCE[source]);
}
