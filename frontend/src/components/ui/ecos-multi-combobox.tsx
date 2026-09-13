import { useEffect, useRef, useState } from 'react';
import { Check, ChevronsUpDown, X } from 'lucide-react';
import * as PopoverPrimitive from '@radix-ui/react-popover';

import { Input } from '@/components/ui/input';
import { useDialogAncestorPortal } from '@/components/ui/use-dialog-ancestor-portal';
import { cn } from '@/lib/utils';

export type EcosMultiComboboxOption = {
  value: string;
  label: string;
};

export type EcosMultiComboboxProps = {
  options: EcosMultiComboboxOption[];
  value: string[];
  onChange: (value: string[]) => void;
  placeholder?: string;
  searchPlaceholder?: string;
  emptyText?: string;
  /** Text shown in the list while `loading` is true. */
  loadingText?: string;
  /** aria-label for the options listbox — this is a generic UI primitive, not
   *  feature-specific, so callers supply their own translated string rather
   *  than this component owning an i18n namespace. */
  optionsLabel?: string;
  loading?: boolean;
  disabled?: boolean;
  className?: string;
  /** Notified as the user types — lets a caller drive SERVER-SIDE search (opt-in). */
  onSearchChange?: (query: string) => void;
  /** When false, the caller supplies already-filtered (e.g. server-searched) options; the
   *  built-in client-side label filter is skipped. Defaults to true (unchanged behaviour). */
  filterClientSide?: boolean;
  /** When true, the options list renders an error state (with an optional retry
   *  action) instead of the generic empty-text branch — lets a caller distinguish
   *  "no results" from "the request failed" (opt-in, defaults to false). */
  isError?: boolean;
  /** Text shown in the error state. Defaults to a generic message. */
  errorText?: string;
  /** Label for the retry action shown in the error state (only rendered when `onRetry` is set). */
  retryLabel?: string;
  /** Called when the user clicks the retry action in the error state. */
  onRetry?: () => void;
};

/**
 * Multi-select sibling of EcosCombobox — same portal-rendered Popover,
 * search, and keyboard nav, but toggles membership in a value list instead
 * of replacing a single value, and the trigger shows removable chips
 * (with overflow) instead of one label. Built for large, searchable
 * catalogs — never a plain checkbox wall.
 */
export function EcosMultiCombobox({
  options,
  value,
  onChange,
  placeholder = 'Select…',
  searchPlaceholder = 'Search…',
  emptyText = 'No results found',
  loadingText = 'Loading…',
  optionsLabel = 'Options',
  loading = false,
  disabled = false,
  className,
  onSearchChange,
  filterClientSide = true,
  isError = false,
  errorText = "Couldn't load options",
  retryLabel = 'Retry',
  onRetry,
}: EcosMultiComboboxProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [activeIndex, setActiveIndex] = useState(-1);
  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  // TASK-ECOS-SYSTEM-WIDE-SEARCHABLE-SELECT-FOCUS-REMEDIATION-006 — shared
  // with EcosCombobox; see use-dialog-ancestor-portal.ts for the root cause
  // and fix (previously an independently-maintained copy here).
  const { portalContainer, attachOnOpen } = useDialogAncestorPortal(triggerRef);

  const selectedOptions = value
    .map((v) => options.find((o) => o.value === v))
    .filter((o): o is EcosMultiComboboxOption => o != null);
  const filtered = filterClientSide && query
    ? options.filter((o) => o.label.toLowerCase().includes(query.toLowerCase()))
    : options;

  // Event-driven (not effect-driven): reset search/active-index as part of
  // the open/close transition itself, and focus once Radix finishes
  // mounting the portal content — avoids setState-in-effect entirely.
  function handleOpenChange(next: boolean) {
    setOpen(next);
    if (next) {
      setQuery('');
      setActiveIndex(-1);
      attachOnOpen(inputRef);
    }
  }

  function handleQueryChange(next: string) {
    setQuery(next);
    setActiveIndex(-1);
    onSearchChange?.(next);
  }

  useEffect(() => {
    if (activeIndex < 0 || !listRef.current) return;
    const el = listRef.current.children[activeIndex] as HTMLElement | undefined;
    el?.scrollIntoView({ block: 'nearest' });
  }, [activeIndex]);

  // Mouse-wheel scrolling on the listbox is blocked when this popover is opened
  // from inside a Radix Dialog/Sheet: the portalled content is a DOM sibling of
  // the Dialog's react-remove-scroll wrapper (not a descendant, not a shard), so
  // the wrapper's wheel-blocking kicks in and native overflow scroll never fires.
  // Fix: don't depend on native scroll at all — drive scrollTop ourselves from a
  // non-passive listener attached directly to the listbox element.
  useEffect(() => {
    const el = listRef.current;
    if (!open || !el) return;
    function handleWheel(e: WheelEvent) {
      el!.scrollTop += e.deltaY;
      e.preventDefault();
    }
    el.addEventListener('wheel', handleWheel, { passive: false });
    return () => el.removeEventListener('wheel', handleWheel);
  }, [open]);

  function toggle(optionValue: string) {
    onChange(
      value.includes(optionValue)
        ? value.filter((v) => v !== optionValue)
        : [...value, optionValue],
    );
  }

  function remove(optionValue: string) {
    onChange(value.filter((v) => v !== optionValue));
  }

  function handleKeyDown(e: React.KeyboardEvent) {
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        setActiveIndex((i) => Math.min(i + 1, filtered.length - 1));
        break;
      case 'ArrowUp':
        e.preventDefault();
        setActiveIndex((i) => Math.max(i - 1, 0));
        break;
      case 'Enter':
        e.preventDefault();
        if (activeIndex >= 0 && filtered[activeIndex]) {
          toggle(filtered[activeIndex].value);
        }
        break;
      case 'Escape':
      case 'Tab':
        setOpen(false);
        break;
    }
  }

  return (
    <PopoverPrimitive.Root open={open} onOpenChange={handleOpenChange}>
      <PopoverPrimitive.Trigger asChild>
        <button
          ref={triggerRef}
          type="button"
          disabled={disabled}
          aria-expanded={open}
          aria-haspopup="listbox"
          className={cn(
            'border-input flex min-h-9 w-full flex-wrap items-center gap-1 rounded-md border bg-transparent px-2 py-1 text-sm shadow-xs outline-none',
            'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
            'disabled:cursor-not-allowed disabled:opacity-50',
            className,
          )}
        >
          {selectedOptions.length === 0 ? (
            <span className="text-muted-foreground truncate px-1">{placeholder}</span>
          ) : (
            selectedOptions.map((o) => (
              <span
                key={o.value}
                className="bg-secondary text-secondary-foreground inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium"
              >
                <span className="max-w-40 truncate">{o.label}</span>
                {!disabled && (
                  <span
                    role="button"
                    tabIndex={-1}
                    onClick={(e) => { e.stopPropagation(); remove(o.value); }}
                    aria-label={`Remove ${o.label}`}
                    className="text-muted-foreground hover:text-foreground transition-colors"
                  >
                    <X className="size-3" />
                  </span>
                )}
              </span>
            ))
          )}
          <ChevronsUpDown className="ms-auto size-4 shrink-0 opacity-50" />
        </button>
      </PopoverPrimitive.Trigger>

      <PopoverPrimitive.Portal container={portalContainer}>
        <PopoverPrimitive.Content
          sideOffset={4}
          align="start"
          avoidCollisions
          collisionPadding={8}
          style={{ width: 'var(--radix-popover-trigger-width)' }}
          className={cn(
            'bg-popover text-popover-foreground z-[9999] overflow-hidden rounded-md border shadow-md',
            'data-[state=open]:animate-in data-[state=closed]:animate-out',
            'data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
            'data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
            'data-[side=bottom]:slide-in-from-top-2 data-[side=top]:slide-in-from-bottom-2',
          )}
          onKeyDown={handleKeyDown}
        >
          <div className="p-1 border-b">
            <Input
              ref={inputRef}
              value={query}
              onChange={(e) => handleQueryChange(e.target.value)}
              placeholder={searchPlaceholder}
              className="h-8"
              aria-label={searchPlaceholder}
            />
          </div>

          <div
            ref={listRef}
            role="listbox"
            aria-multiselectable="true"
            aria-label={optionsLabel}
            className="max-h-[300px] overflow-y-auto p-1"
          >
            {loading ? (
              <p className="text-muted-foreground px-2 py-1.5 text-sm">{loadingText}</p>
            ) : isError ? (
              <div className="flex flex-col items-center gap-1.5 px-2 py-3 text-center">
                <p className="text-muted-foreground text-sm">{errorText}</p>
                {onRetry ? (
                  <button
                    type="button"
                    onClick={onRetry}
                    className="text-primary text-xs font-medium underline-offset-2 hover:underline"
                  >
                    {retryLabel}
                  </button>
                ) : null}
              </div>
            ) : filtered.length === 0 ? (
              <p className="text-muted-foreground px-2 py-1.5 text-sm">{emptyText}</p>
            ) : (
              filtered.map((option, i) => {
                const isSelected = value.includes(option.value);
                return (
                  <button
                    type="button"
                    role="option"
                    key={option.value}
                    aria-selected={isSelected}
                    onClick={() => toggle(option.value)}
                    className={cn(
                      'flex w-full items-center justify-between gap-2 rounded-sm px-2 py-1.5 text-left text-sm',
                      'hover:bg-accent hover:text-accent-foreground',
                      isSelected && 'bg-accent/50 text-accent-foreground',
                      activeIndex === i && 'bg-accent text-accent-foreground',
                    )}
                  >
                    <span className="truncate">{option.label}</span>
                    {isSelected && <Check className="size-4 shrink-0" />}
                  </button>
                );
              })
            )}
          </div>
        </PopoverPrimitive.Content>
      </PopoverPrimitive.Portal>
    </PopoverPrimitive.Root>
  );
}
