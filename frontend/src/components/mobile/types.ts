import type { ReactNode } from 'react';

/**
 * Shared mobile-layer types (Workstream A — Mobile Responsive Foundation).
 *
 * These types are consumed by the two table components (`UniversalDataGrid`,
 * `EntityTable`) and the shared mobile primitives. They are presentation-only:
 * nothing here fetches data or encodes a business rule.
 */

/**
 * Optional, additive hint on a column definition that lets a page tune how its
 * auto-generated mobile card looks — WITHOUT hand-writing a `renderMobileCard`.
 *
 * All roles are opt-in. When no column carries a role, the auto-card falls back
 * to a conservative default (first column = title, the rest = label/value
 * fields). The hint never fabricates data or infers a status; it only decides
 * where an existing column's rendered cell is placed inside the card.
 */
export type CardFieldRole =
  /** Rendered large, as the card's primary identifier (top-start). */
  | 'title'
  /** Rendered muted, directly under the title (code / date / secondary id). */
  | 'subtitle'
  /** Rendered top-end as the status slot (the column supplies its own badge). */
  | 'status'
  /** Rendered as a label/value pair in the metadata grid (the default). */
  | 'meta'
  /** Omitted from the mobile card (e.g. a desktop-only technical column). */
  | 'hidden';

/**
 * A column normalised for the auto-card renderer. Both table components map
 * their own column shape onto this so a single `AutoDataCard` serves both.
 *
 * - `label` is the existing header text — never a fabricated string.
 * - `render` is the existing cell renderer — no value is recomputed.
 */
export type AutoCardColumn<T> = {
  key: string;
  label: ReactNode;
  render: (row: T) => ReactNode;
  cardRole?: CardFieldRole;
  align?: 'start' | 'center' | 'end';
};
