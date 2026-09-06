import { Lock } from 'lucide-react';

/**
 * The FORBIDDEN state (TASK-ECOS-REPORTING-V1-USER-VISIBLE-NAVIGATION-CLOSURE-010
 * §5) — visually distinct from ERROR/EMPTY so a viewer never reads "no data" or
 * "something went wrong" when the true reason is a missing permission. Mirrors the
 * shared LoadingState/EmptyState/ErrorState (`@/components/crud`) layout so all
 * four states feel like one family; kept local since no shared "forbidden" variant
 * exists yet in that kit and this task does not extend shared primitives.
 */
export function ReportForbiddenState({ title, description }: { title: string; description?: string }) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 py-12 text-center">
      <span className="bg-muted text-muted-foreground flex size-12 items-center justify-center rounded-full">
        <Lock className="size-6" />
      </span>
      <p className="font-medium">{title}</p>
      {description ? <p className="text-muted-foreground max-w-sm text-sm">{description}</p> : null}
    </div>
  );
}
