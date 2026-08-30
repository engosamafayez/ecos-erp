import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

// ── Public props ─────────────────────────────────────────────────────────────

export type MobileDataCardField = {
  label: ReactNode;
  value: ReactNode;
  /** `end` right-aligns the value (money / quantities); pairs with tabular-nums. */
  align?: 'start' | 'end';
};

export type MobileDataCardProps = {
  /** Primary identifier — rendered large at the top-start. */
  title: ReactNode;
  /** Secondary line under the title (code / date / muted id). */
  subtitle?: ReactNode;
  /** Status slot at the top-end — the consumer supplies its own badge node. */
  status?: ReactNode;
  /** Key facts as label/value pairs, laid out in a mirroring 2-column grid. */
  fields?: MobileDataCardField[];
  /**
   * Footer actions (≤1 primary + an overflow menu). Always tap-visible — never
   * hover-gated — and rendered as a sibling of the tap target so no interactive
   * element is nested inside another.
   */
  actions?: ReactNode;
  /** Whole-card tap target → open the record's detail. */
  onOpen?: () => void;
  /** Accessible label for the open affordance. Defaults to "Open details". */
  openLabel?: string;
  selected?: boolean;
  onSelect?: (checked: boolean) => void;
  selectLabel?: string;
  focused?: boolean;
  className?: string;
};

// ── Component ────────────────────────────────────────────────────────────────

/**
 * MobileDataCard — the canonical "row as a card" for card-mode lists.
 *
 * A single shared component so every card list (grid auto-cards, bespoke
 * `renderMobileCard`, master-data lists) looks and behaves identically. It is
 * presentation-only: it receives already-rendered content and callbacks and
 * holds no domain logic.
 *
 * RTL-safe (logical utilities only), theme-safe (tokens only), and touch-first
 * (the open affordance is a full-width ≥44px tap target; no hover-only action).
 */
export function MobileDataCard({
  title,
  subtitle,
  status,
  fields,
  actions,
  onOpen,
  openLabel,
  selected = false,
  onSelect,
  selectLabel,
  focused = false,
  className,
}: MobileDataCardProps) {
  const { t } = useTranslation('common');

  const hasFields = !!fields && fields.length > 0;

  const body = (
    <>
      <div className="flex items-start justify-between gap-2">
        <span className="min-w-0 flex-1 truncate text-sm font-medium leading-tight">{title}</span>
        {status ? <span className="shrink-0">{status}</span> : null}
      </div>

      {subtitle ? (
        <p className="mt-0.5 truncate text-xs text-muted-foreground">{subtitle}</p>
      ) : null}

      {hasFields ? (
        <dl className="mt-2.5 grid grid-cols-2 gap-x-4 gap-y-2">
          {fields.map((field, index) => (
            <div key={index} className="min-w-0">
              <dt className="truncate text-[11px] uppercase tracking-wide text-muted-foreground">
                {field.label}
              </dt>
              <dd
                className={cn(
                  'mt-0.5 text-sm',
                  field.align === 'end' && 'text-end tabular-nums',
                )}
              >
                {field.value}
              </dd>
            </div>
          ))}
        </dl>
      ) : null}
    </>
  );

  return (
    <div
      role="listitem"
      aria-selected={onSelect ? selected : undefined}
      data-focused={focused || undefined}
      className={cn(
        'relative border-b p-3.5 transition-colors last:border-0',
        selected ? 'bg-primary/5' : 'bg-card',
        focused && 'outline outline-1 -outline-offset-1 outline-primary/50',
        className,
      )}
    >
      {onSelect ? (
        <div className="absolute top-4 start-3.5">
          <input
            type="checkbox"
            checked={selected}
            onChange={(event) => onSelect(event.target.checked)}
            className="size-4 cursor-pointer rounded accent-primary"
            aria-label={selectLabel ?? t(($) => $.selection.selectRow)}
          />
        </div>
      ) : null}

      {onOpen ? (
        <button
          type="button"
          onClick={onOpen}
          aria-label={openLabel ?? t(($) => $.mobile.openDetails)}
          className={cn(
            'block w-full min-h-11 text-start',
            onSelect && 'ps-7',
          )}
        >
          {body}
        </button>
      ) : (
        <div className={cn(onSelect && 'ps-7')}>{body}</div>
      )}

      {actions ? (
        <div className={cn('mt-2.5 flex items-center justify-end gap-1', onSelect && 'ps-7')}>
          {actions}
        </div>
      ) : null}
    </div>
  );
}
