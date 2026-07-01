import { useState } from 'react';
import { Search, User, X } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { usePosStore } from '@/features/pos/store/pos-store';

type CustomerPanelProps = {
  className?: string;
};

export function CustomerPanel({ className }: CustomerPanelProps) {
  const { activeCustomerId, activeCustomerName, setCustomer } = usePosStore();
  const [search, setSearch] = useState('');
  const [expanded, setExpanded] = useState(false);

  function clearCustomer() {
    setCustomer(null, null);
    setSearch('');
  }

  if (activeCustomerId) {
    return (
      <div className={cn('flex items-center gap-2 rounded-md border bg-muted/30 px-2 py-1.5', className)}>
        <User className="size-3.5 shrink-0 text-muted-foreground" />
        <span className="flex-1 truncate text-xs font-medium">{activeCustomerName}</span>
        <Button
          variant="ghost"
          size="icon"
          className="size-5 text-muted-foreground hover:text-destructive"
          onClick={clearCustomer}
        >
          <X className="size-3" />
        </Button>
      </div>
    );
  }

  return (
    <div className={cn(className)}>
      {expanded ? (
        <div className="flex items-center gap-1.5">
          <Input
            autoFocus
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Customer name or ID..."
            className="h-7 text-xs"
            onKeyDown={(e) => {
              if (e.key === 'Escape') { setExpanded(false); setSearch(''); }
              // A real implementation would trigger a customer lookup here
            }}
          />
          <Button
            variant="ghost"
            size="icon"
            className="size-7"
            onClick={() => { setExpanded(false); setSearch(''); }}
          >
            <X className="size-3" />
          </Button>
        </div>
      ) : (
        <Button
          variant="ghost"
          size="sm"
          className="h-7 gap-1.5 text-xs text-muted-foreground"
          onClick={() => setExpanded(true)}
        >
          <User className="size-3.5" />
          Add Customer
        </Button>
      )}
    </div>
  );
}
