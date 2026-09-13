import { useCallback, useState, type RefObject } from 'react';

/**
 * Shared fix for TASK-ECOS-SYSTEM-WIDE-SEARCHABLE-SELECT-FOCUS-REMEDIATION-006,
 * extracted (TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045) out of
 * EcosCombobox and EcosMultiCombobox, which had each grown an independent copy.
 *
 * ROOT CAUSE: a Radix Dialog/Sheet's `Dialog.Content` wraps its children in a
 * trapped `FocusScope`. That trap's `focusin` listener
 * (`@radix-ui/react-focus-scope`) checks plain DOM containment —
 * `dialogContainer.contains(event.target)` — and synchronously redirects
 * focus back inside the dialog whenever it is false. A Popover's content
 * portals to `document.body` by default: a DOM SIBLING of the Dialog's own
 * portalled content, never a descendant — so any focus landing inside it was
 * immediately yanked back, every time, with no caret ever appearing and no
 * keystroke ever landing. This is why it looked system-wide: nearly every
 * consumer of these comboboxes sits inside a Sheet/Dialog/Drawer.
 *
 * FIX: portal INTO the nearest ancestor Dialog/Sheet's own content node
 * instead of `document.body` when one exists, so the trap's containment
 * check is true and it stops fighting. `[role="dialog"]` is what Radix's own
 * `Dialog.Content` (and this app's Sheet, which wraps it) stamps on that
 * exact node — not a convention invented here. Standalone usage (no ancestor
 * dialog) resolves to `null`, and the Popover falls back to its own default
 * (`document.body`) exactly as before.
 */
export function useDialogAncestorPortal(triggerRef: RefObject<HTMLElement | null>) {
  const [portalContainer, setPortalContainer] = useState<HTMLElement | null>(null);

  /**
   * Call when the popover opens: re-targets the portal to the nearest
   * ancestor dialog (or `null` for standalone use), then — after a short
   * delay so Radix finishes mounting the portal content into its new
   * container — focuses `inputRef`. Returns the timeout id so a caller
   * driving this from a `useEffect` can clear it on cleanup; a caller
   * driving this from an event handler can ignore the return value.
   *
   * Wrapped in `useCallback` (stable identity as long as `triggerRef` is, and
   * refs always are) so effect/handler callers can safely list it as a
   * dependency without the effect re-firing on every render.
   */
  const attachOnOpen = useCallback(
    (inputRef: RefObject<HTMLInputElement | null>): ReturnType<typeof setTimeout> => {
      const dialogAncestor = triggerRef.current?.closest<HTMLElement>('[role="dialog"]') ?? null;
      setPortalContainer(dialogAncestor);
      return setTimeout(() => inputRef.current?.focus(), 10);
    },
    [triggerRef],
  );

  return { portalContainer, attachOnOpen };
}
