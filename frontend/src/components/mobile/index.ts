// ── Shared Mobile Foundation (Workstream A) ──────────────────────────────────
// Presentation-only mobile primitives, composed from existing ui/ + crud/
// primitives. No new runtime dependency; no data fetching; no business rules.

export { MobileDataCard } from './mobile-data-card';
export type { MobileDataCardProps, MobileDataCardField } from './mobile-data-card';

export { AutoDataCard } from './auto-data-card';
export type { AutoDataCardProps } from './auto-data-card';

export { MobileFilterSheet } from './mobile-filter-sheet';
export type { MobileFilterSheetProps } from './mobile-filter-sheet';

export { MobileSortMenu } from './mobile-sort-menu';
export type { MobileSortMenuProps, MobileSortOption } from './mobile-sort-menu';

export { MobileActionBar } from './mobile-action-bar';
export type { MobileActionBarProps } from './mobile-action-bar';

export {
  MobileDetailSheet,
  MobileDetailSection,
  MobileDetailFacts,
} from './mobile-detail-sheet';
export type {
  MobileDetailSheetProps,
  MobileDetailSectionProps,
  MobileDetailFact,
  MobileDetailFactsProps,
} from './mobile-detail-sheet';

// ── Shared types ─────────────────────────────────────────────────────────────
export type { CardFieldRole, AutoCardColumn } from './types';
