import { useTranslation } from 'react-i18next';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

export function RecentActivity() {
  const { t } = useTranslation('dashboard');

  const activityKeys = [
    'recentActivity.onboarding',
    'recentActivity.inventory',
    'recentActivity.sales',
    'recentActivity.hr',
  ] as const;

  return (
    <Card className="h-full">
      <CardHeader>
        <CardTitle>{t('recentActivity.title')}</CardTitle>
        <CardDescription>{t('recentActivity.subtitle')}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {activityKeys.map((key) => (
          <div key={key} className="flex items-center gap-3">
            <Skeleton className="size-9 rounded-full" />
            <div className="flex flex-1 flex-col gap-1">
              <span className="text-sm">{t(key)}</span>
              <span className="text-muted-foreground text-xs">{t('recentActivity.justNow')}</span>
            </div>
          </div>
        ))}
      </CardContent>
    </Card>
  );
}
