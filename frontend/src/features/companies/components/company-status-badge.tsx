import { Badge } from '@/components/ui/badge';

/**
 * Active / Inactive status badge.
 */
export function CompanyStatusBadge({ active }: { active: boolean }) {
  if (active) {
    return (
      <Badge variant="secondary" className="gap-1.5">
        <span className="size-1.5 rounded-full bg-emerald-500" />
        Active
      </Badge>
    );
  }

  return (
    <Badge variant="outline" className="text-muted-foreground gap-1.5">
      <span className="bg-muted-foreground size-1.5 rounded-full" />
      Inactive
    </Badge>
  );
}
