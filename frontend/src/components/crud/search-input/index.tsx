import { useEffect, useRef, useState } from 'react';
import { Search, X } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type SearchInputProps = {
  /** Called with the debounced value. */
  onChange: (value: string) => void;
  placeholder?: string;
  initialValue?: string;
  debounceMs?: number;
  className?: string;
};

/**
 * Debounced search input with a clear button.
 */
export function SearchInput({
  onChange,
  placeholder = 'Search…',
  initialValue = '',
  debounceMs = 300,
  className,
}: SearchInputProps) {
  const [value, setValue] = useState(initialValue);
  const onChangeRef = useRef(onChange);

  // Keep the latest callback without re-arming the debounce timer.
  useEffect(() => {
    onChangeRef.current = onChange;
  }, [onChange]);

  useEffect(() => {
    const timer = setTimeout(() => onChangeRef.current(value), debounceMs);
    return () => clearTimeout(timer);
  }, [value, debounceMs]);

  return (
    <div className={cn('relative w-full max-w-sm', className)}>
      <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
      <Input
        type="text"
        value={value}
        placeholder={placeholder}
        aria-label={placeholder}
        onChange={(event) => setValue(event.target.value)}
        className="pr-8 pl-8"
      />
      {value ? (
        <Button
          type="button"
          variant="ghost"
          size="icon"
          aria-label="Clear search"
          onClick={() => setValue('')}
          className="absolute top-1/2 right-1 size-7 -translate-y-1/2"
        >
          <X className="size-3.5" />
        </Button>
      ) : null}
    </div>
  );
}
