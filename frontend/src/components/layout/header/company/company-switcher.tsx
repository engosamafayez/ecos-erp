import { useEffect, useRef, useState } from 'react';
import { Check, ChevronsUpDown, Search } from 'lucide-react';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

// ── Mock data ─────────────────────────────────────────────────────────────────

type Company = {
  id: string;
  name: string;
  type: string;
  branches: number;
  initials: string;
  colorClass: string;
  ringClass: string;
};

const COMPANIES: Company[] = [
  {
    id: '1',
    name: 'ECOS Holding',
    type: 'Holding Company',
    branches: 3,
    initials: 'EH',
    colorClass: 'bg-blue-500/15 text-blue-600 dark:text-blue-400',
    ringClass: 'ring-blue-500/30',
  },
  {
    id: '2',
    name: 'ECOS Retail',
    type: 'Retail Operations',
    branches: 5,
    initials: 'ER',
    colorClass: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    ringClass: 'ring-emerald-500/30',
  },
  {
    id: '3',
    name: 'ECOS Logistics',
    type: 'Logistics Division',
    branches: 2,
    initials: 'EL',
    colorClass: 'bg-orange-500/15 text-orange-600 dark:text-orange-400',
    ringClass: 'ring-orange-500/30',
  },
];

// ── Component ─────────────────────────────────────────────────────────────────

export function CompanySwitcher({ className }: { className?: string }) {
  const [open, setOpen] = useState(false);
  const [activeId, setActiveId] = useState(COMPANIES[0].id);
  const [search, setSearch] = useState('');
  const [focusIdx, setFocusIdx] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);

  const active = COMPANIES.find((c) => c.id === activeId) ?? COMPANIES[0];

  const filtered = search.trim()
    ? COMPANIES.filter(
        (c) =>
          c.name.toLowerCase().includes(search.toLowerCase()) ||
          c.type.toLowerCase().includes(search.toLowerCase()),
      )
    : COMPANIES;

  // Reset + focus input when dropdown opens; reset on close
  useEffect(() => {
    if (open) {
      setSearch('');
      setFocusIdx(0);
      const id = setTimeout(() => inputRef.current?.focus(), 40);
      return () => clearTimeout(id);
    }
  }, [open]);

  // Clamp focus index when filtered list shrinks
  useEffect(() => {
    setFocusIdx((i) => Math.min(i, Math.max(0, filtered.length - 1)));
  }, [filtered.length]);

  function selectCompany(id: string) {
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
        if (filtered[focusIdx]) selectCompany(filtered[focusIdx].id);
        break;
    }
  }

  return (
    <DropdownMenu open={open} onOpenChange={setOpen}>
      <DropdownMenuTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          aria-label={`Current company: ${active.name}. Click to switch.`}
          aria-expanded={open}
          className={cn('h-9 gap-2 px-2 sm:px-3', className)}
        >
          {/* Company initials badge */}
          <span
            className={cn(
              'flex size-6 shrink-0 items-center justify-center rounded-md text-[10px] font-bold leading-none ring-1',
              active.colorClass,
              active.ringClass,
            )}
            aria-hidden
          >
            {active.initials}
          </span>

          {/* Name + type — hidden on xs, visible sm+ */}
          <span className="hidden flex-col items-start sm:flex">
            <span className="max-w-[7rem] truncate text-xs font-semibold leading-tight lg:max-w-[9rem]">
              {active.name}
            </span>
            <span className="max-w-[7rem] truncate text-[10px] leading-tight text-muted-foreground lg:max-w-[9rem]">
              {active.type}
            </span>
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
            placeholder="Search companies..."
            className="flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
            aria-label="Search companies"
            autoComplete="off"
          />
        </div>

        {/* ── Section label ── */}
        <div className="px-3 pb-1 pt-2.5">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            Companies
          </p>
        </div>

        {/* ── List ── */}
        <div className="px-1 pb-1" role="listbox" aria-label="Available companies">
          {filtered.length === 0 ? (
            <div className="flex flex-col items-center gap-1 py-8 text-center">
              <p className="text-sm font-medium text-muted-foreground">No companies found</p>
              <p className="text-xs text-muted-foreground/60">Try a different name</p>
            </div>
          ) : (
            filtered.map((company, idx) => {
              const isActive = company.id === activeId;
              const isFocused = idx === focusIdx;
              return (
                <button
                  key={company.id}
                  type="button"
                  role="option"
                  aria-selected={isActive}
                  onClick={() => selectCompany(company.id)}
                  onMouseEnter={() => setFocusIdx(idx)}
                  className={cn(
                    'flex w-full items-center gap-3 rounded-md px-2 py-2.5 text-start transition-colors',
                    isFocused
                      ? 'bg-accent text-accent-foreground'
                      : 'text-foreground hover:bg-accent/60',
                  )}
                >
                  {/* Initials badge */}
                  <span
                    className={cn(
                      'flex size-9 shrink-0 items-center justify-center rounded-lg text-sm font-bold ring-1',
                      company.colorClass,
                      company.ringClass,
                    )}
                    aria-hidden
                  >
                    {company.initials}
                  </span>

                  {/* Info */}
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold">{company.name}</span>
                    <span className="block truncate text-xs text-muted-foreground">
                      {company.type} · {company.branches} branches
                    </span>
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
            <span className="text-xs text-muted-foreground">Add company</span>
            <span className="ml-auto rounded-full border border-primary/30 bg-primary/5 px-1.5 py-0.5 text-[9px] font-medium text-primary/70">
              Soon
            </span>
          </button>
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
