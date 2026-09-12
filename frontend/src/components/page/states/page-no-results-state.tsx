/**
 * Compatibility re-export (TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045).
 * The real implementation moved to the canonical crud kit as `NoResultsState`
 * — import from `@/components/crud` (or `@/components/foundation`) in new
 * code. This path is kept working for its existing consumer
 * (features/suppliers/pages/suppliers-page.tsx); do not add new imports here.
 */
export { NoResultsState as PageNoResultsState } from '@/components/crud/no-results-state';
