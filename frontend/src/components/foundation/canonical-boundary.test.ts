import { describe, expect, it } from 'vitest';
import { ESLint } from 'eslint';

/**
 * TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045, ticket §17 item 8
 * ("canonical/deprecated import boundary"). Runs the project's REAL
 * eslint.config.js (not a duplicated copy of its rule list) against small
 * synthetic snippets, so this test breaks if the boundary rule is ever
 * accidentally removed or its grandfather list drifts from reality.
 */
describe('canonical/deprecated UI foundation import boundary', () => {
  async function lint(code: string, filePath: string) {
    // No explicit `cwd` — ESLint's own default (its process's cwd) already
    // resolves eslint.config.js correctly when vitest runs from `frontend/`,
    // and `src/**` deliberately has no Node ambient types (browser app code).
    const eslint = new ESLint();
    const [result] = await eslint.lintText(code, { filePath });
    return result.messages.filter((m) => m.ruleId === 'no-restricted-imports');
  }

  it('flags a new file importing a deprecated path', async () => {
    const messages = await lint(
      `import { QuickStatCard } from '@/components/ds/quick-stat-card';\nexport const x = QuickStatCard;\n`,
      'src/features/some-new-page-not-on-the-grandfather-list.tsx',
    );
    expect(messages.length).toBeGreaterThan(0);
  });

  it('flags a new file importing a deprecated named export via a barrel', async () => {
    const messages = await lint(
      `import { QuickStatCard } from '@/components/ds';\nexport const x = QuickStatCard;\n`,
      'src/features/some-new-page-not-on-the-grandfather-list.tsx',
    );
    expect(messages.length).toBeGreaterThan(0);
  });

  it('does not flag the canonical replacement import', async () => {
    const messages = await lint(
      `import { Tabs } from '@/components/ui/tabs';\nexport const x = Tabs;\n`,
      'src/features/some-new-page-not-on-the-grandfather-list.tsx',
    );
    expect(messages).toHaveLength(0);
  });

  it('does not flag an explicitly grandfathered existing consumer', async () => {
    const messages = await lint(
      `import { QuickStatCard } from '@/components/ds/quick-stat-card';\nexport const x = QuickStatCard;\n`,
      'src/features/products/components/product-quick-stats.tsx',
    );
    expect(messages).toHaveLength(0);
  });
});
