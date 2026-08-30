import type { ReactNode } from 'react';
import { ArrowDown, ArrowDownUp, ArrowUp } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

export type MobileSortOption = {
  field: string;
  label: ReactNode;
};

export type MobileSortMenuProps = {
  options: MobileSortOption[];
  value?: { field: string; direction: 'asc' | 'desc' };
  /** Mirrors the grid's `onSortChange`: selecting the active field toggles
   *  direction; the page owns that logic. */
  onChange: (field: string) => void;
  className?: string;
};

/**
 * MobileSortMenu — "Sort by ▾" for card mode (§11).
 *
 * In card mode the column-header sort affordance is unavailable, so sorting is
 * exposed as a dropdown that reuses the list's existing sort state and
 * `onSortChange` callback. Presentation-only.
 */
export function MobileSortMenu({ options, value, onChange, className }: MobileSortMenuProps) {
  const { t } = useTranslation('common');

  const active = value ? options.find((option) => option.field === value.field) : undefined;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button type="button" variant="outline" size="sm" className={className}>
          <ArrowDownUp className="size-4" />
          <span className="truncate">{active ? active.label : t(($) => $.mobile.sortBy)}</span>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        {options.map((option) => {
          const isActive = value?.field === option.field;
          const DirectionIcon = value?.direction === 'desc' ? ArrowDown : ArrowUp;
          return (
            <DropdownMenuItem
              key={option.field}
              onClick={() => onChange(option.field)}
              className={cn(isActive && 'font-medium')}
            >
              {option.label}
              {isActive ? <DirectionIcon className="size-3.5 ms-auto opacity-70" /> : null}
            </DropdownMenuItem>
          );
        })}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
