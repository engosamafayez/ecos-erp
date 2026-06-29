import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Search, Sparkles } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

import { cn } from '@/lib/utils';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';

import {
  CATEGORY_LABEL,
  CATEGORY_SECTION_ICON,
  EMPTY_STATE_MOCK,
  SEARCH_MOCK,
} from './search-mock-data';
import type { SearchCategory, SearchResult } from './search-mock-data';

type Props = {
  open: boolean;
  onClose: () => void;
};

type GroupedItem = { item: SearchResult; flatIdx: number };
type Group = { category: SearchCategory; items: GroupedItem[] };

export function SearchCommandDialog({ open, onClose }: Props) {
  const [query, setQuery] = useState('');
  const [activeIndex, setActiveIndex] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLDivElement>(null);
  const navigate = useNavigate();

  // Reset state when dialog opens
  useEffect(() => {
    if (open) {
      setQuery('');
      setActiveIndex(0);
      const id = setTimeout(() => inputRef.current?.focus(), 50);
      return () => clearTimeout(id);
    }
  }, [open]);

  // Source switches between empty-state mock and filterable results
  const results = useMemo<SearchResult[]>(() => {
    const q = query.trim().toLowerCase();
    if (!q) return EMPTY_STATE_MOCK;
    return SEARCH_MOCK.filter(
      (r) =>
        r.label.toLowerCase().includes(q) ||
        r.subtitle?.toLowerCase().includes(q) ||
        r.category.includes(q),
    );
  }, [query]);

  // Group results with flat indices for keyboard nav
  const groups = useMemo<Group[]>(() => {
    let idx = 0;
    const map = new Map<SearchCategory, GroupedItem[]>();
    for (const item of results) {
      if (!map.has(item.category)) map.set(item.category, []);
      map.get(item.category)!.push({ item, flatIdx: idx++ });
    }
    return Array.from(map.entries()).map(([category, items]) => ({ category, items }));
  }, [results]);

  // Clamp activeIndex when result count shrinks
  useEffect(() => {
    setActiveIndex((i) => Math.min(i, Math.max(0, results.length - 1)));
  }, [results.length]);

  // Scroll active item into view
  useEffect(() => {
    listRef.current?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
  }, [activeIndex]);

  const handleSelect = useCallback(
    (result: SearchResult) => {
      if (result.href) navigate(result.href);
      onClose();
    },
    [navigate, onClose],
  );

  function handleKeyDown(e: React.KeyboardEvent) {
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        setActiveIndex((i) => Math.min(i + 1, results.length - 1));
        break;
      case 'ArrowUp':
        e.preventDefault();
        setActiveIndex((i) => Math.max(i - 1, 0));
        break;
      case 'Enter':
        e.preventDefault();
        if (results[activeIndex]) handleSelect(results[activeIndex]);
        break;
    }
  }

  const hasQuery = query.trim().length > 0;

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
      <DialogContent
        className="gap-0 overflow-hidden p-0 sm:max-w-2xl"
        aria-describedby={undefined}
        onKeyDown={handleKeyDown}
      >
        <DialogTitle className="sr-only">Global Search</DialogTitle>

        {/* ── Input bar ─────────────────────────────────────────────────── */}
        <div className="flex items-center gap-3 border-b px-4 py-3">
          <Search className="size-5 shrink-0 text-muted-foreground" aria-hidden />
          <input
            ref={inputRef}
            type="text"
            value={query}
            onChange={(e) => { setQuery(e.target.value); setActiveIndex(0); }}
            placeholder="Search anything..."
            className="flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
            aria-label="Global search"
            aria-autocomplete="list"
            autoComplete="off"
            spellCheck={false}
          />
          <div className="flex shrink-0 items-center gap-2">
            {hasQuery ? (
              <button
                type="button"
                onClick={() => { setQuery(''); inputRef.current?.focus(); }}
                className="rounded px-1.5 py-0.5 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
              >
                Clear
              </button>
            ) : (
              <kbd className="hidden select-none items-center rounded border bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground sm:inline-flex">
                ESC
              </kbd>
            )}
          </div>
        </div>

        {/* ── Results ───────────────────────────────────────────────────── */}
        <div
          ref={listRef}
          role="listbox"
          aria-label="Search results"
          className="max-h-[58vh] overflow-y-auto py-2"
        >
          {results.length === 0 ? (
            <div className="flex flex-col items-center gap-2 py-12 text-center">
              <Search className="size-8 text-muted-foreground/30" aria-hidden />
              <p className="text-sm font-medium">No results for &ldquo;{query}&rdquo;</p>
              <p className="text-xs text-muted-foreground">
                Try searching by order number, SKU, or customer name
              </p>
            </div>
          ) : (
            groups.map(({ category, items }) => {
              const SectionIcon = CATEGORY_SECTION_ICON[category];
              return (
                <div key={category} role="group" aria-label={CATEGORY_LABEL[category]}>
                  {/* Section header */}
                  <div className="flex items-center gap-1.5 px-4 pb-1 pt-3">
                    <SectionIcon className="size-3 text-muted-foreground/60" aria-hidden />
                    <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                      {CATEGORY_LABEL[category]}
                    </p>
                  </div>

                  {/* Items */}
                  {items.map(({ item, flatIdx }) => {
                    const Icon = item.icon;
                    const isActive = flatIdx === activeIndex;
                    return (
                      <button
                        key={item.id}
                        type="button"
                        role="option"
                        aria-selected={isActive}
                        data-active={isActive || undefined}
                        onClick={() => handleSelect(item)}
                        onMouseEnter={() => setActiveIndex(flatIdx)}
                        className={cn(
                          'flex w-full items-center gap-3 px-4 py-2 text-start transition-colors',
                          isActive ? 'bg-accent text-accent-foreground' : 'hover:bg-accent/50',
                        )}
                      >
                        <span className="flex size-7 shrink-0 items-center justify-center rounded-md border bg-background shadow-xs">
                          <Icon className="size-3.5 text-muted-foreground" aria-hidden />
                        </span>
                        <span className="min-w-0 flex-1">
                          <span className="block truncate text-sm font-medium leading-tight">
                            {item.label}
                          </span>
                          {item.subtitle ? (
                            <span className="block truncate text-xs leading-tight text-muted-foreground">
                              {item.subtitle}
                            </span>
                          ) : null}
                        </span>
                        {item.href ? (
                          <span className="shrink-0 text-xs text-muted-foreground/40" aria-hidden>
                            ↵
                          </span>
                        ) : null}
                      </button>
                    );
                  })}
                </div>
              );
            })
          )}
        </div>

        {/* ── Footer ────────────────────────────────────────────────────── */}
        <div className="flex items-center justify-between border-t bg-muted/30 px-4 py-2">
          <div className="flex items-center gap-3 text-[10px] text-muted-foreground">
            <span>
              <kbd className="rounded border bg-background px-1 font-mono text-[9px]">↑↓</kbd>{' '}
              Navigate
            </span>
            <span>
              <kbd className="rounded border bg-background px-1 font-mono text-[9px]">↵</kbd>{' '}
              Select
            </span>
            <span>
              <kbd className="rounded border bg-background px-1 font-mono text-[9px]">ESC</kbd>{' '}
              Close
            </span>
          </div>
          <div className="flex items-center gap-1.5 text-[10px] text-muted-foreground">
            <Sparkles className="size-3 text-primary/60" aria-hidden />
            <span className="hidden sm:inline">AI Search</span>
            <span className="rounded-full border border-primary/30 bg-primary/5 px-1.5 py-0.5 text-[9px] font-medium text-primary/70">
              Soon
            </span>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
