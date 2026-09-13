import js from '@eslint/js';
import globals from 'globals';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import tseslint from 'typescript-eslint';
import { defineConfig, globalIgnores } from 'eslint/config';
import ecosI18n from './eslint-rules/index.js';

export default defineConfig([
  globalIgnores(['dist']),
  {
    files: ['**/*.{ts,tsx}'],
    extends: [
      js.configs.recommended,
      tseslint.configs.recommended,
      reactHooks.configs.flat.recommended,
      reactRefresh.configs.vite,
    ],
    languageOptions: {
      globals: globals.browser,
    },
  },
  {
    // ── i18n Guard (TASK-I18N-GUARD-001) ──────────────────────────────────
    // Every user-facing string must come from the localization system.
    // CI fails if a new hardcoded UI string is introduced. Brand names,
    // product names, technical identifiers, and API names are exempt — see
    // the allow-lists in eslint-rules/no-hardcoded-ui-strings.js.
    files: ['src/**/*.{ts,tsx}'],
    plugins: { 'ecos-i18n': ecosI18n },
    rules: {
      'ecos-i18n/no-hardcoded-ui-strings': 'error',
      'ecos-i18n/no-arabic-literals': 'error',
    },
  },
  {
    // The i18n layer itself legitimately contains locale strings and the
    // namespace registry; the guard would flag its own scaffolding.
    files: ['src/i18n/**/*.{ts,tsx}'],
    rules: {
      'ecos-i18n/no-hardcoded-ui-strings': 'off',
      'ecos-i18n/no-arabic-literals': 'off',
    },
  },
  {
    // shadcn/ui primitives canonically export their cva variant helpers
    // alongside the component, which is incompatible with the Fast Refresh
    // "only export components" rule. This does not affect runtime behavior.
    files: ['src/components/ui/**/*.{ts,tsx}'],
    rules: {
      'react-refresh/only-export-components': 'off',
    },
  },
  {
    // ── Canonical UI foundation boundary (TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045) ──
    // Closes 042B's highest-leverage finding: two component generations were
    // running concurrently with nothing stopping new code from picking the
    // wrong one. This is a RATCHET, not a cliff — it only blocks NEW imports of
    // paths confirmed deprecated by the 042B/045 architecture reports; the
    // override block below explicitly grandfathers every file that already
    // imports one of them (verified by direct grep at 045-implementation time,
    // not carried over from the reports' approximate counts) so existing pages
    // keep working unmigrated. Do not add new files to that grandfather list —
    // migrate them onto the canonical replacement instead (see
    // components/foundation/index.ts and the 045 report's compatibility
    // matrix). Two large-adoption items (EntityTable: 39 consumers, PageDrawer:
    // 35 consumers) are deliberately NOT restricted here — see the 045 report's
    // "Known Limitations" for why enforcing those is deferred to the slice that
    // actually migrates them.
    files: ['src/**/*.{ts,tsx}'],
    rules: {
      'no-restricted-imports': ['error', {
        paths: [
          { name: '@/components/ds/tabs', message: 'Deprecated: use `Tabs` from `@/components/ui/tabs` (Radix) instead.' },
          { name: '@/components/ds', importNames: ['Tabs', 'TabItem'], message: 'Deprecated: use `Tabs` from `@/components/ui/tabs` (Radix) instead.' },
          { name: '@/components/ds/quick-stat-card', message: 'Deprecated: use `WorkspaceMetricCard` from `@/components/foundation` instead.' },
          { name: '@/components/ds', importNames: ['QuickStatCard'], message: 'Deprecated: use `WorkspaceMetricCard` from `@/components/foundation` instead.' },
          { name: '@/components/page/dialog/page-confirm-dialog', message: 'Deprecated: use `ConfirmDialog` from `@/components/crud` instead.' },
          { name: '@/components/page', importNames: ['PageConfirmDialog'], message: 'Deprecated: use `ConfirmDialog` from `@/components/crud` instead.' },
          { name: '@/components/page/pagination/page-pagination', message: 'Deprecated: use `Pagination` from `@/components/crud` instead.' },
          { name: '@/components/page', importNames: ['PagePagination'], message: 'Deprecated: use `Pagination` from `@/components/crud` instead.' },
          { name: '@/components/page/states/page-empty-state', message: 'Deprecated: use `EmptyState` from `@/components/crud` instead.' },
          { name: '@/components/page', importNames: ['PageEmptyState'], message: 'Deprecated: use `EmptyState` from `@/components/crud` instead.' },
          { name: '@/components/page/states/page-loading-state', message: 'Deprecated, unused: use `LoadingState` from `@/components/crud` instead.' },
          { name: '@/components/page', importNames: ['PageLoadingState'], message: 'Deprecated, unused: use `LoadingState` from `@/components/crud` instead.' },
          { name: '@/components/page/states/page-error-state', message: 'Deprecated, unused: use `ErrorState` from `@/components/crud` instead.' },
          { name: '@/components/page', importNames: ['PageErrorState'], message: 'Deprecated, unused: use `ErrorState` from `@/components/crud` instead.' },
          { name: '@/components/page/states/page-no-results-state', message: 'Use `NoResultsState` from `@/components/crud` instead (same implementation).' },
          { name: '@/components/page', importNames: ['PageNoResultsState'], message: 'Use `NoResultsState` from `@/components/crud` instead (same implementation).' },
          { name: '@/components/page/states/page-permission-state', message: 'Use `PermissionState` from `@/components/crud` instead (same implementation).' },
          { name: '@/components/page', importNames: ['PagePermissionState'], message: 'Use `PermissionState` from `@/components/crud` instead (same implementation).' },
          { name: '@/components/form/drawer/page-form-drawer', message: 'Deprecated: use `EntityDrawer` from `@/components/crud` instead.' },
          { name: '@/components/form', importNames: ['PageFormDrawer'], message: 'Deprecated: use `EntityDrawer` from `@/components/crud` instead.' },
          { name: '@/components/form/layout/form-section', message: 'Deprecated, unused (0 consumers) — see TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045-REPORT.md.' },
          { name: '@/components/form', importNames: ['FormSection'], message: 'Deprecated, unused (0 consumers).' },
          { name: '@/components/form/layout/form-grid', message: 'Deprecated, unused (0 consumers) — see TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045-REPORT.md.' },
          { name: '@/components/form', importNames: ['FormGrid'], message: 'Deprecated, unused (0 consumers).' },
          { name: '@/components/form/tabs/drawer-tabs', message: 'Deprecated, unused (0 consumers): use `Tabs` from `@/components/ui/tabs` instead.' },
          { name: '@/components/form', importNames: ['DrawerTabs', 'DrawerTabItem'], message: 'Deprecated, unused (0 consumers).' },
          // Discovered during 045's implementation (not previously flagged by
          // 042B/R1): a second, fully unadopted "single entry point" barrel —
          // 0 real consumers in src/features (verified by direct grep),
          // despite its own doc comment inviting feature modules to use it.
          { name: '@/components/ecos', message: 'Deprecated, unused (0 real consumers) — use `@/components/foundation` (or `@/components/crud`) instead.' },
        ],
        patterns: [
          {
            group: ['@/components/entity', '@/components/entity/*'],
            message: 'Deprecated, unused (0 consumers) — the whole EntityWorkspace unifier was never adopted. See TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045-REPORT.md.',
          },
        ],
      }],
    },
  },
  {
    // Grandfathered pre-existing consumers of the deprecated paths above,
    // verified by direct grep on 2026-09-12 (see the 045 engineering report's
    // "Changed Files" / deprecation-boundary section for the mapping of each
    // file to which deprecated import it still uses). Do not add new files
    // here — migrate the file off the deprecated import instead, then remove
    // its entry.
    files: [
      'src/features/cost-management/components/product-cost-drawer.tsx',
      'src/features/raw-materials/components/raw-material-detail-drawer.tsx',
      'src/features/recipes/components/recipe-detail-drawer.tsx',
      'src/features/crm/components/crm-customer-drawer.tsx',
      'src/features/brands/components/brand-delivery-windows-tab.tsx',
      'src/features/crm/pages/crm-executive-workspace-page.tsx',
      'src/features/customers/pages/customers-page.tsx',
      'src/features/engineering/pages/engineering-dashboard-page.tsx',
      'src/features/products/components/product-quick-stats.tsx',
      'src/features/products/components/product-quick-stats.test.tsx',
      'src/features/raw-materials/components/raw-material-stats.tsx',
      'src/features/recipes/pages/recipes-page.tsx',
      'src/features/operations/loading-os/components/loading-session-overview.tsx',
      'src/features/suppliers/pages/suppliers-page.tsx',
      'src/features/engineering/components/RunDetailDrawer.tsx',
      // Barrels whose entire job is re-exporting some of the paths above —
      // linting their own re-export statements would be flagging the
      // plumbing, not a new consumer adopting a deprecated pattern.
      'src/components/ds/index.ts',
      'src/components/ecos/index.ts',
      // The whole entity/ directory is itself the fully-unadopted
      // EntityWorkspace unifier (see the `patterns` restriction above) — its
      // own internal imports of other deprecated paths aren't worth
      // separately cleaning up.
      'src/components/entity/**/*.{ts,tsx}',
    ],
    rules: {
      'no-restricted-imports': 'off',
    },
  },
]);
