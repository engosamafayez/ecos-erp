import { ChevronLeft, ChevronRight } from 'lucide-react';

import { Button } from '@/components/ui/button';
import type { PaginationMeta } from '@/components/crud/types';
import { useLanguage } from '@/providers/language-context';

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
  const { dir } = useLanguage();
  const PrevIcon = dir === 'rtl' ? ChevronRight : ChevronLeft;
  const NextIcon = dir === 'rtl' ? ChevronLeft  : ChevronRight;

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
          <PrevIcon className="size-4" aria-hidden />
          Previous
        </Button>
        <Button
          variant="outline"
          size="sm"
          disabled={!canNext}
          onClick={() => onPageChange(page + 1)}
        >
          Next
          <NextIcon className="size-4" aria-hidden />
        </Button>
      </div>
    </div>
  );
}
