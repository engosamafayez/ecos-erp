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
    // matrix). EntityTable (39 consumers at 045-time) was fully migrated and
    // removed in TASK-ECOS-V1.1-CORE-01-UI-07. QuickStatCard (migrated to
    // WorkspaceMetricCard) and PageDrawer (35 consumers, migrated to
    // EntityDrawer) were both fully migrated and removed in
    // TASK-ECOS-V1.1-CORE-01-UI-08.
    files: ['src/**/*.{ts,tsx}'],
    rules: {
      'no-restricted-imports': ['error', {
        paths: [
          { name: '@/components/page/pagination/page-pagination', message: 'Deprecated: use `Pagination` from `@/components/crud` instead.' },
          { name: '@/components/page', importNames: ['PagePagination'], message: 'Deprecated: use `Pagination` from `@/components/crud` instead.' },
          // Discovered during 045's implementation (not previously flagged by
          // 042B/R1): a second, fully unadopted "single entry point" barrel —
          // 0 real consumers in src/features (verified by direct grep),
          // despite its own doc comment inviting feature modules to use it.
          { name: '@/components/ecos', message: 'Deprecated, unused (0 real consumers) — use `@/components/foundation` (or `@/components/crud`) instead.' },
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
      'src/features/operations/loading-os/components/loading-session-overview.tsx',
    ],
    rules: {
      'no-restricted-imports': 'off',
    },
  },
]);
