import { ChevronLeft, ChevronRight } from 'lucide-react';

import { Button } from '@/components/ui/button';
import type { PaginationMeta } from '@/components/crud/types';

type PaginationProps = {
  meta: PaginationMeta;
  onPageChange: (page: number) => void;
};

/**
 * Reusable pagination control (previous / next + summary).
 */
export function Pagination({ meta, onPageChange }: PaginationProps) {
  const { page, total, lastPage } = meta;
  const canPrevious = page > 1;
  const canNext = page < lastPage;

  return (
    <div className="text-muted-foreground flex flex-col items-center justify-between gap-2 text-sm sm:flex-row">
      <span>
        {total === 0 ? 'No results' : `Page ${page} of ${Math.max(lastPage, 1)} · ${total} total`}
      </span>
      <div className="flex items-center gap-2">
        <Button
          variant="outline"
          size="sm"
          disabled={!canPrevious}
          onClick={() => onPageChange(page - 1)}
        >
          <ChevronLeft className="size-4" />
          Previous
        </Button>
        <Button
          variant="outline"
          size="sm"
          disabled={!canNext}
          onClick={() => onPageChange(page + 1)}
        >
          Next
          <ChevronRight className="size-4" />
        </Button>
      </div>
    </div>
  );
}
