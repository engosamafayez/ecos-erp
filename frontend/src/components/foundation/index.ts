/**
 * ECOS Canonical UI Foundation — single import surface for redesigned pages.
 *
 * TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045, closing the decision
 * in TASK-ECOS-V1.1-UI-REDESIGN-ARCHITECTURE-042B-R1-REPORT.md §1: the
 * canonical foundation is `components/crud/*` + `components/ui/*`, with
 * `UniversalDataGrid` (from `components/data-grid`) and `WorkspaceHeader` /
 * `WorkspaceBreadcrumbs` / `WorkspaceMetricCard` (from `components/workspace`)
 * promoted in as the table and page-header primitives, replacing `EntityTable`
 * and raw `<h1>`/`PageHeader` usage in new work.
 *
 * This barrel sits ABOVE `crud`/`data-grid`/`workspace` and depends on all
 * three — it is not imported BY any of them — specifically so it can
 * re-export `UniversalDataGrid` without creating a cycle:
 * `data-grid/universal-data-grid.tsx` already imports `EmptyState`/
 * `ErrorState`/`Pagination` from the `crud` barrel, so `crud/index.ts` itself
 * must never import from `data-grid` or `workspace`.
 *
 * Deliberately NOT re-exported here (see the compatibility/deprecation matrix
 * in the 045 engineering report): `EntityTable` (`crud/entity-table` —
 * compatibility-only, superseded by `UniversalDataGrid`), and anything from
 * `components/entity`, `components/form`, `components/page`, or
 * `components/ds/{tabs,quick-stat-card}` (deprecated — see
 * `eslint.config.js`'s `no-restricted-imports` boundary). Raw Radix-based
 * primitives (Dialog, Sheet, Tabs, Button, Input, EcosCombobox, …) stay
 * imported directly from `@/components/ui/*` — they don't need another layer
 * of indirection over an already-uncontested, non-duplicated import path.
 */

// ── Re-export the whole crud kit ─────────────────────────────────────────────
export * from '@/components/crud';

// ── Promoted: table/grid ─────────────────────────────────────────────────────
export { UniversalDataGrid } from '@/components/data-grid';
export type { UniversalDataGridProps, DataGridColumnDef } from '@/components/data-grid';

// ── Promoted: page header / breadcrumbs / KPI card ───────────────────────────
export { WorkspaceHeader, WorkspaceBreadcrumbs, WorkspaceMetricCard } from '@/components/workspace';
export type {
  WorkspaceHeaderProps,
  WorkspaceBreadcrumb,
  WorkspaceMetric,
} from '@/components/workspace';
