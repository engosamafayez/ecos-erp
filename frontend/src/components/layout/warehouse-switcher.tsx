import { useState } from 'react';
import { Check, ChevronsUpDown, Warehouse } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const WAREHOUSES = ['Main Warehouse', 'Branch Warehouse', 'Overflow Storage'] as const;

export function WarehouseSwitcher() {
  const active = WAREHOUSES[0];
  const [selected, setSelected] = useState<string>(active);

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" size="sm" className="gap-2">
          <Warehouse className="size-4" />
          <span className="hidden max-w-28 truncate sm:inline">{selected}</span>
          <ChevronsUpDown className="size-3.5 opacity-60" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-56">
        <DropdownMenuLabel>Switch warehouse</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {WAREHOUSES.map((wh) => (
          <DropdownMenuItem key={wh} onClick={() => setSelected(wh)}>
            {wh}
            {wh === selected ? <Check className="ml-auto size-4" /> : null}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
