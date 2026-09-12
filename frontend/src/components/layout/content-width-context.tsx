import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';

/**
 * Canonical fixed-width content-container capability
 * (TASK-ECOS-V1.1-CORE-01-UI-02-SHELL-NAVIGATION-046 §6). Additive: default
 * shell behavior is unchanged (fluid, matching every existing consumer) —
 * a page opts INTO the centered/max-width presentation by calling
 * `useFixedContentWidth()`; nothing forks AppShell or adds a page-local hack.
 *
 * Mirrors the existing `HeaderProvider`/`useHeaderContext` pattern
 * (components/layout/header/header-context.tsx) rather than inventing a new
 * shape of context for the shell.
 */

export type ContentWidth = 'fluid' | 'fixed';

type ContentWidthCtx = {
  contentWidth: ContentWidth;
  setContentWidth: (next: ContentWidth) => void;
};

const ContentWidthContext = createContext<ContentWidthCtx | null>(null);

export function ContentWidthProvider({ children }: { children: ReactNode }) {
  const [contentWidth, setContentWidth] = useState<ContentWidth>('fluid');

  const value = useMemo(() => ({ contentWidth, setContentWidth }), [contentWidth]);

  return <ContentWidthContext.Provider value={value}>{children}</ContentWidthContext.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components -- the read hook belongs beside its provider, same as the existing header-context.tsx pattern; it doesn't affect Fast Refresh correctness, only the rule's static "one export per file" heuristic.
export function useContentWidth() {
  const ctx = useContext(ContentWidthContext);
  if (!ctx) throw new Error('useContentWidth must be inside ContentWidthProvider');
  return ctx;
}

/**
 * Call from a page component to opt the shell's content container into the
 * canonical fixed max-width presentation (`--content-max-width`, defined in
 * index.css) while that page is mounted. Resets to the default `fluid`
 * behavior on unmount, so navigating to a page that doesn't call this always
 * gets the existing (unchanged) full-width presentation.
 */
// eslint-disable-next-line react-refresh/only-export-components -- convenience hook colocated with its provider, same reasoning as useContentWidth above.
export function useFixedContentWidth(): void {
  const { setContentWidth } = useContentWidth();

  useEffect(() => {
    setContentWidth('fixed');
    return () => setContentWidth('fluid');
    // eslint-disable-next-line react-hooks/exhaustive-deps -- setContentWidth is stable (useState setter identity never changes); re-running this effect on its identity would be a no-op at best.
  }, []);
}
