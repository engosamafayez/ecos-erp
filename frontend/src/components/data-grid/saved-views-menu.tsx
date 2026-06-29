import { Bookmark, ChevronDown } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type SavedViewsMenuProps = {
  label?: string;
};

/**
 * Saved Views — placeholder for future view persistence.
 * Will allow users to save and restore filter/sort/column combinations per module.
 */
export function SavedViewsMenu({ label = 'Views' }: SavedViewsMenuProps) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" size="sm" className="gap-1.5">
          <Bookmark className="size-3.5" />
          {label}
          <ChevronDown className="size-3" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-52">
        <DropdownMenuItem disabled className="text-xs text-muted-foreground">
          No saved views yet
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem disabled>Save current view…</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
