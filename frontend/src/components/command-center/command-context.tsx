import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
} from 'react';
import type { ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';

import { useHeaderContext } from '@/components/layout/header/header-context';

import { createDefaultCommands } from './command-groups';
import { commandRegistry, useRegisteredCommands } from './command-registry';
import type { Command } from './command-types';

// ── Context type ──────────────────────────────────────────────────────────────

type CommandCenterCtx = {
  /** Whether the command palette dialog is open. */
  isOpen: boolean;
  open: () => void;
  close: () => void;
  toggle: () => void;

  /**
   * All commands currently available in the palette.
   * Includes default (navigation + actions + recent + favorites + AI)
   * and any module-registered commands.
   */
  commands: Command[];

  /**
   * Register a set of commands under a namespace.
   * Returns an unregister function — call it in useEffect cleanup.
   *
   * Example — wire module commands at layout mount:
   *   const { registerCommands } = useCommandCenter();
   *   useEffect(() => registerCommands('orders', myCommands), []);
   *
   * Prefer the `useRegisterCommands` hook for a one-liner alternative.
   */
  registerCommands: (namespace: string, commands: Command[]) => () => void;
};

const CommandCenterContext = createContext<CommandCenterCtx | null>(null);

// ── Provider ──────────────────────────────────────────────────────────────────

/**
 * CommandProvider — the root context for the ECOS ERP Command Center.
 *
 * Must be placed:
 *   1. Inside React Router (uses useNavigate)
 *   2. Inside HeaderProvider (reuses searchOpen / openSearch / closeSearch)
 *
 * Recommended placement:
 *   <HeaderProvider>
 *     <CommandProvider>
 *       <AppTopbar />
 *       <main>...</main>
 *     </CommandProvider>
 *   </HeaderProvider>
 *
 * This provider bridges two concerns:
 *   - Open/close state  → delegated to HeaderContext (single source of truth)
 *   - Command registry  → owns the merged command list (default + module-registered)
 */
export function CommandProvider({ children }: { children: ReactNode }) {
  const navigate = useNavigate();
  const { searchOpen, openSearch, closeSearch } = useHeaderContext();

  // Stable reference to close so navigation commands don't capture stale values
  const closeRef = useRef(closeSearch);
  closeRef.current = closeSearch;
  const stableClose = useCallback(() => closeRef.current(), []);

  // Default command set — regenerated only when navigate or stableClose changes
  const defaultCommands = useMemo(
    () => createDefaultCommands(navigate, stableClose),
    [navigate, stableClose],
  );

  // Module-registered commands — re-rendered on registry mutations
  const moduleCommands = useRegisteredCommands();

  // Merge: defaults are always first, module commands are appended
  const allCommands = useMemo(
    () => [...defaultCommands, ...moduleCommands],
    [defaultCommands, moduleCommands],
  );

  const toggle = useCallback(
    () => (searchOpen ? closeSearch() : openSearch()),
    [searchOpen, closeSearch, openSearch],
  );

  const registerCommands = useCallback(
    (ns: string, cmds: Command[]) => commandRegistry.register(ns, cmds),
    [],
  );

  const value = useMemo<CommandCenterCtx>(
    () => ({
      isOpen: searchOpen,
      open: openSearch,
      close: closeSearch,
      toggle,
      commands: allCommands,
      registerCommands,
    }),
    [searchOpen, openSearch, closeSearch, toggle, allCommands, registerCommands],
  );

  return (
    <CommandCenterContext.Provider value={value}>
      {children}
    </CommandCenterContext.Provider>
  );
}

// ── Hooks ─────────────────────────────────────────────────────────────────────

/**
 * Access the Command Center context.
 * Must be used inside <CommandProvider>.
 */
export function useCommandCenter(): CommandCenterCtx {
  const ctx = useContext(CommandCenterContext);
  if (!ctx) throw new Error('useCommandCenter must be used inside CommandProvider');
  return ctx;
}
