import { MoreHorizontal } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { ActionMenuItem } from '@/components/crud/types';

type ActionMenuProps = {
  items: ActionMenuItem[];
  label?: string;
};

/**
 * Reusable row/entity action menu (View, Edit, Delete and custom actions).
 */
export function ActionMenu({ items, label = 'Open actions' }: ActionMenuProps) {
  if (items.length === 0) {
    return null;
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" aria-label={label}>
          <MoreHorizontal className="size-4" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        {items.map((item) => {
          const Icon = item.icon;
          return (
            <DropdownMenuItem
              key={item.key}
              variant={item.variant}
              disabled={item.disabled}
              onClick={item.onSelect}
            >
              {Icon ? <Icon className="size-4" /> : null}
              {item.label}
            </DropdownMenuItem>
          );
        })}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
