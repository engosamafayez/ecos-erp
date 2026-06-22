import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

const ACTIVITY = [
  'Company onboarding flow drafted',
  'Inventory module scaffolding planned',
  'Sales pipeline schema proposed',
  'HR onboarding checklist outlined',
];

/**
 * Recent activity feed (placeholder — not connected to the backend).
 */
export function RecentActivity() {
  return (
    <Card className="h-full">
      <CardHeader>
        <CardTitle>Recent Activity</CardTitle>
        <CardDescription>Placeholder feed — not connected to the backend.</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {ACTIVITY.map((item) => (
          <div key={item} className="flex items-center gap-3">
            <Skeleton className="size-9 rounded-full" />
            <div className="flex flex-1 flex-col gap-1">
              <span className="text-sm">{item}</span>
              <span className="text-muted-foreground text-xs">Just now</span>
            </div>
          </div>
        ))}
      </CardContent>
    </Card>
  );
}
