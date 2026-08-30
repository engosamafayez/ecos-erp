import { useSyncExternalStore } from 'react';

/**
 * The mobile breakpoint boundary. Matches Tailwind's `md` (768px): a viewport
 * narrower than this is treated as "mobile" (single-column, card layout, sheet
 * filters), everything from `md` up is tablet/desktop.
 *
 * This deliberately mirrors the CSS breakpoint the table components switch on
 * so JS-driven branching (filter sheet vs inline panel, detail-sheet
 * composition) stays consistent with the purely-CSS card/table switch.
 */
export const MOBILE_MEDIA_QUERY = '(max-width: 767px)';

function hasMatchMedia(): boolean {
  return typeof window !== 'undefined' && typeof window.matchMedia === 'function';
}

function subscribe(onChange: () => void): () => void {
  if (!hasMatchMedia()) return () => {};
  const mql = window.matchMedia(MOBILE_MEDIA_QUERY);
  mql.addEventListener('change', onChange);
  return () => mql.removeEventListener('change', onChange);
}

function getSnapshot(): boolean {
  return hasMatchMedia() ? window.matchMedia(MOBILE_MEDIA_QUERY).matches : false;
}

function getServerSnapshot(): boolean {
  return false;
}

/**
 * `useIsMobile` — the single JS breakpoint signal for the app.
 *
 * The ECOS enterprise shell is CSS-responsive by default; prefer Tailwind
 * breakpoint classes wherever the layout can be expressed in CSS. Reach for
 * this hook ONLY when an interaction genuinely cannot be branched in CSS —
 * e.g. rendering a bottom `Sheet` on mobile but an inline panel on desktop
 * (mounting two focus-trapping dialogs and hiding one with CSS is worse than
 * choosing one in JS).
 *
 * Implemented with `useSyncExternalStore` so it subscribes to the media query
 * without an effect, stays tear-free, and is SSR-safe (`false` on the server /
 * first paint). Because the table components already render the correct layout
 * purely in CSS, that initial `false` never causes a data-visibility flash — it
 * only affects the few controls that opt into JS branching.
 */
export function useIsMobile(): boolean {
  return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
