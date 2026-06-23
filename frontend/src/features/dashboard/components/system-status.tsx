import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

export function SystemStatus() {
  const { t } = useTranslation('dashboard');

  const serviceKeys = [
    'systemStatus.api',
    'systemStatus.database',
    'systemStatus.queue',
    'systemStatus.mail',
  ] as const;

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('systemStatus.title')}</CardTitle>
        <CardDescription>{t('systemStatus.subtitle')}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {serviceKeys.map((key) => (
          <div key={key} className="flex items-center justify-between">
            <span className="text-sm">{t(key)}</span>
            <Badge variant="secondary" className="gap-1.5">
              <span className="size-1.5 rounded-full bg-emerald-500" />
              {t('systemStatus.operational')}
            </Badge>
          </div>
        ))}
      </CardContent>
    </Card>
  );
}
