import { Building2, FileBarChart, Package, UserPlus } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

export function QuickActions() {
  const { t } = useTranslation('dashboard');

  const actions = [
    { labelKey: 'quickActions.addCompany', icon: Building2 },
    { labelKey: 'quickActions.newProduct', icon: Package },
    { labelKey: 'quickActions.inviteUser', icon: UserPlus },
    { labelKey: 'quickActions.viewReports', icon: FileBarChart },
  ];

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('quickActions.title')}</CardTitle>
        <CardDescription>{t('quickActions.subtitle')}</CardDescription>
      </CardHeader>
      <CardContent className="grid grid-cols-2 gap-3">
        {actions.map((action) => {
          const Icon = action.icon;
          const label = t(action.labelKey);
          return (
            <Button key={action.labelKey} variant="outline" className="h-auto flex-col gap-2 py-4">
              <Icon className="size-5" />
              <span className="text-xs">{label}</span>
            </Button>
          );
        })}
      </CardContent>
    </Card>
  );
}
