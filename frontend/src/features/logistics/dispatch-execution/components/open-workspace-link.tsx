import { ArrowUpRight } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * The "Open full workspace →" affordance every tab carries (§ page structure).
 * Deep actions (assign vehicle, confirm loading, etc.) never happen on this
 * coordination page — they stay on the full existing pages, and this is the way
 * there.
 */
export function OpenWorkspaceLink({
  to,
  label,
  prominent = false,
}: {
  to: string;
  label: string;
  prominent?: boolean;
}) {
  const navigate = useNavigate();

  return (
    <Button
      type="button"
      variant={prominent ? 'default' : 'outline'}
      size={prominent ? 'default' : 'sm'}
      onClick={() => navigate(to)}
      className={cn('shrink-0 gap-1.5', prominent && 'w-full justify-center sm:w-auto')}
    >
      {label}
      <ArrowUpRight className="size-4" />
    </Button>
  );
}
