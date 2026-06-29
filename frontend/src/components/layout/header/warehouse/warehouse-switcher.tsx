import { useEffect, useRef, useState } from 'react';
import { Check, ChevronsUpDown, MapPin, Search, Warehouse } from 'lucide-react';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

// ── Mock data ─────────────────────────────────────────────────────────────────

type WarehouseItem = {
  id: string;
  name: string;
  location: string;
  zone: string;
  capacityPct: number;
};

const WAREHOUSES: WarehouseItem[] = [
  { id: '1', name: 'Main Warehouse', location: 'Dubai, UAE', zone: 'Zone A', capacityPct: 92 },
  { id: '2', name: 'Branch Warehouse', location: 'Abu Dhabi, UAE', zone: 'Zone B', capacityPct: 61 },
  { id: '3', name: 'Overflow Storage', location: 'Sharjah, UAE', zone: 'Zone C', capacityPct: 38 },
];

// ── Capacity helpers ──────────────────────────────────────────────────────────

function getCapacityColor(pct: number) {
  if (pct >= 90) return { bar: 'bg-destructive', badge: 'text-destructive bg-destructive/10' };
  if (pct >= 70) return { bar: 'bg-amber-500', badge: 'text-amber-600 bg-amber-500/10 dark:text-amber-400' };
  return { bar: 'bg-emerald-500', badge: 'text-emerald-600 bg-emerald-500/10 dark:text-emerald-400' };
}

// ── Component ─────────────────────────────────────────────────────────────────

export function WarehouseSwitcher({ className }: { className?: string }) {
  const [open, setOpen] = useState(false);
  const [activeId, setActiveId] = useState(WAREHOUSES[0].id);
  const [search, setSearch] = useState('');
  const [focusIdx, setFocusIdx] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);

  const active = WAREHOUSES.find((w) => w.id === activeId) ?? WAREHOUSES[0];

  const filtered = search.trim()
    ? WAREHOUSES.filter(
        (w) =>
          w.name.toLowerCase().includes(search.toLowerCase()) ||
          w.location.toLowerCase().includes(search.toLowerCase()) ||
          w.zone.toLowerCase().includes(search.toLowerCase()),
      )
    : WAREHOUSES;

  // Reset + focus input when dropdown opens
  useEffect(() => {
    if (open) {
      setSearch('');
      setFocusIdx(0);
      const id = setTimeout(() => inputRef.current?.focus(), 40);
      return () => clearTimeout(id);
    }
  }, [open]);

  useEffect(() => {
    setFocusIdx((i) => Math.min(i, Math.max(0, filtered.length - 1)));
  }, [filtered.length]);

  function selectWarehouse(id: string) {
    setActiveId(id);
    setOpen(false);
  }

  function handleInputKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        e.stopPropagation();
        setFocusIdx((i) => Math.min(i + 1, filtered.length - 1));
        break;
      case 'ArrowUp':
        e.preventDefault();
        e.stopPropagation();
        setFocusIdx((i) => Math.max(i - 1, 0));
        break;
      case 'Enter':
        e.preventDefault();
        e.stopPropagation();
        if (filtered[focusIdx]) selectWarehouse(filtered[focusIdx].id);
        break;
    }
  }

  const activeCapacity = getCapacityColor(active.capacityPct);

  return (
    <DropdownMenu open={open} onOpenChange={setOpen}>
      <DropdownMenuTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          aria-label={`Current warehouse: ${active.name}. Click to switch.`}
          aria-expanded={open}
          className={cn('h-9 gap-2 px-2 sm:px-3', className)}
        >
          {/* Warehouse icon */}
          <Warehouse className="size-4 shrink-0 text-muted-foreground" aria-hidden />

          {/* Name + location — hidden xs, visible sm+ */}
          <span className="hidden flex-col items-start sm:flex">
            <span className="max-w-[7rem] truncate text-xs font-semibold leading-tight lg:max-w-[9rem]">
              {active.name}
            </span>
            <span className="max-w-[7rem] truncate text-[10px] leading-tight text-muted-foreground lg:max-w-[9rem]">
              {active.location}
            </span>
          </span>

          {/* Capacity badge — sm+ */}
          <span
            className={cn(
              'hidden rounded-full px-1.5 py-0.5 text-[9px] font-semibold sm:inline-block',
              activeCapacity.badge,
            )}
            aria-hidden
          >
            {active.capacityPct}%
          </span>

          <ChevronsUpDown className="size-3.5 shrink-0 opacity-40" aria-hidden />
        </Button>
      </DropdownMenuTrigger>

      <DropdownMenuContent align="start" className="w-72 p-0" onCloseAutoFocus={(e) => e.preventDefault()}>
        {/* ── Search ── */}
        <div className="flex items-center gap-2 border-b px-3 py-2.5">
          <Search className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
          <input
            ref={inputRef}
            type="text"
            value={search}
            onChange={(e) => { setSearch(e.target.value); setFocusIdx(0); }}
            onKeyDown={handleInputKeyDown}
            placeholder="Search warehouses..."
            className="flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
            aria-label="Search warehouses"
            autoComplete="off"
          />
        </div>

        {/* ── Section label ── */}
        <div className="px-3 pb-1 pt-2.5">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            Warehouses
          </p>
        </div>

        {/* ── List ── */}
        <div className="px-1 pb-1" role="listbox" aria-label="Available warehouses">
          {filtered.length === 0 ? (
            <div className="flex flex-col items-center gap-1 py-8 text-center">
              <p className="text-sm font-medium text-muted-foreground">No warehouses found</p>
              <p className="text-xs text-muted-foreground/60">Try a different location or name</p>
            </div>
          ) : (
            filtered.map((wh, idx) => {
              const isActive = wh.id === activeId;
              const isFocused = idx === focusIdx;
              const cap = getCapacityColor(wh.capacityPct);
              return (
                <button
                  key={wh.id}
                  type="button"
                  role="option"
                  aria-selected={isActive}
                  onClick={() => selectWarehouse(wh.id)}
                  onMouseEnter={() => setFocusIdx(idx)}
                  className={cn(
                    'flex w-full items-center gap-3 rounded-md px-2 py-2.5 text-start transition-colors',
                    isFocused
                      ? 'bg-accent text-accent-foreground'
                      : 'text-foreground hover:bg-accent/60',
                  )}
                >
                  {/* Icon badge */}
                  <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <Warehouse className="size-4" aria-hidden />
                  </span>

                  {/* Info */}
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold">{wh.name}</span>
                    <span className="flex items-center gap-1 text-xs text-muted-foreground">
                      <MapPin className="size-3 shrink-0" aria-hidden />
                      <span className="truncate">{wh.location} · {wh.zone}</span>
                    </span>
                    {/* Capacity bar */}
                    <div className="mt-1.5 flex items-center gap-2">
                      <div className="h-1 flex-1 overflow-hidden rounded-full bg-muted">
                        <div
                          className={cn('h-full rounded-full transition-all', cap.bar)}
                          style={{ width: `${wh.capacityPct}%` }}
                        />
                      </div>
                      <span className={cn('shrink-0 text-[9px] font-semibold', cap.badge.split(' ')[0])}>
                        {wh.capacityPct}%
                      </span>
                    </div>
                  </span>

                  {/* Active check */}
                  {isActive ? (
                    <Check className="size-4 shrink-0 text-primary" aria-hidden />
                  ) : null}
                </button>
              );
            })
          )}
        </div>

        {/* ── Footer ── */}
        <div className="border-t px-1 py-1">
          <button
            type="button"
            disabled
            className="flex w-full cursor-not-allowed items-center gap-2.5 rounded-md px-2 py-2 text-start opacity-50"
          >
            <span className="flex size-6 shrink-0 items-center justify-center rounded-md border border-dashed text-sm text-muted-foreground">
              +
            </span>
            <span className="text-xs text-muted-foreground">Add warehouse</span>
            <span className="ml-auto rounded-full border border-primary/30 bg-primary/5 px-1.5 py-0.5 text-[9px] font-medium text-primary/70">
              Soon
            </span>
          </button>
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
