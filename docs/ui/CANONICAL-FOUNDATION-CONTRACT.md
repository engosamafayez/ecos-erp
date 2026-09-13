# ECOS Canonical UI Foundation Contract

**Status:** Implemented (UI-01) — this is the as-built contract, not an aspiration.
**Established by:** TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045, closing the decisions in
`TASK-ECOS-V1.1-UI-REDESIGN-ARCHITECTURE-042B-REPORT.md` and `-042B-R1-REPORT.md`.

This is the one place a redesigned page should look to find which shared component owns a given
concern. Import from `@/components/foundation` where listed below; primitives that already have a
single, uncontested import path stay imported directly from `@/components/ui/*` — they don't need
another layer of indirection over them.

| Concern | Canonical component | Import from |
|---|---|---|
| Page header | `WorkspaceHeader` | `@/components/foundation` |
| Breadcrumbs | `WorkspaceBreadcrumbs` | `@/components/foundation` |
| Search | `SearchInput` | `@/components/foundation` |
| Filters | `FilterPanel` | `@/components/foundation` |
| Buttons/actions | `Button` | `@/components/ui/button` |
| Status badges | `StatusBadge` (`status` for the 4 built-in lifecycle states; `tone` for any other domain status — success/warning/error/info/neutral) | `@/components/foundation` |
| Tables/data grids | `UniversalDataGrid` | `@/components/foundation` |
| Pagination | `Pagination` | `@/components/foundation` |
| Forms | `EntityForm`, `FormField` | `@/components/foundation` |
| Inputs | `Input`, `Textarea` | `@/components/ui/input`, `@/components/ui/textarea` |
| Select/Combobox | `EcosCombobox` / `EcosMultiCombobox` (searchable); raw `Select` (simple, non-searchable only) | `@/components/ui/ecos-combobox`, `@/components/ui/ecos-multi-combobox`, `@/components/ui/select` |
| Dialog | `Dialog` — short confirmation, focused small-form interaction, modal decision | `@/components/ui/dialog`; `ConfirmDialog` for the confirm case, from `@/components/foundation` |
| Sheet/Drawer | `EntityDrawer` — create/edit workflow, rich detail, longer operational interaction | `@/components/foundation` |
| Tabs | `Tabs` (Radix) | `@/components/ui/tabs` |
| Loading | `LoadingState` | `@/components/foundation` |
| Empty/no-results | `EmptyState` (no data at all) / `NoResultsState` (search or filter matched nothing) — never interchange these two | `@/components/foundation` |
| Error | `ErrorState` (a *read failure* — retryable; never rendered as Empty/NoResults) | `@/components/foundation` |
| Permission/forbidden | `PermissionState` | `@/components/foundation` |
| Toast/feedback | `useToast` | `@/components/ds` |
| Responsive utilities | `useIsMobile` (mirrors the `md` / 768px breakpoint — prefer a CSS breakpoint over this hook unless the branch genuinely cannot be expressed in CSS) | `@/hooks/use-is-mobile` |
| RTL-safe primitives | Logical Tailwind utilities (`ps-*`/`pe-*`, `text-start`/`text-end`, `border-s`/`border-e`); `[data-flip-rtl]` for icon mirroring; `--space-start`/`--space-end` tokens for spacing not expressible as a logical utility | `frontend/src/index.css` |

## Dialog vs. Sheet/Drawer — the rule

- **Dialog**: short confirmation, a focused small-form interaction, or a modal decision the user must
  resolve before continuing. Centered, `max-w-lg` by default.
- **Sheet/Drawer** (`EntityDrawer`): a create/edit workflow, rich record detail, or any longer
  operational interaction. Slides in from the side.

Both are thin, CSS-only wrappers over the same underlying Radix `Dialog` primitive — feature modules
must not invent a third shell primitive for either role.

## Standard shared states — the rule

Four states are structurally distinct, not interchangeable by convention alone:

1. **Loading** (`LoadingState`) — request in flight.
2. **Empty** (`EmptyState`) — request succeeded, there is genuinely no data yet.
3. **No results** (`NoResultsState`) — request succeeded, a search/filter matched nothing (data
   exists).
4. **Error** (`ErrorState`) — the *read* failed. Exposes only `onRetry` (a read retry) — there is no
   generic `action` slot, so a mutation CTA can never become available merely because a read failed.
5. **Permission** (`PermissionState`) — authenticated but not authorized (backend 403).

A read failure must never be rendered via `EmptyState` or `NoResultsState`.

## Table/grid contract (`UniversalDataGrid`)

Loading, empty, error, selection, pagination, sorting, a mobile card fallback, row actions, and
accessible column headers (`scope="col"`) are all built in. Horizontal overflow is contained inside
the grid, not the page. The grid takes authorized actions/data from its caller — it must never embed
domain permission logic itself.

## Canonical vs. compatibility-only vs. deprecated

See the "Compatibility/Deprecation Matrix" section of
`TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045-REPORT.md` for the full, current list, and
`frontend/eslint.config.js`'s `no-restricted-imports` block for what is mechanically enforced today.
