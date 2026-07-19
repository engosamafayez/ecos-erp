import { Utensils } from 'lucide-react';

import { PageHeader } from '@/components/crud';
import { Card, CardContent } from '@/components/ui/card';
import { ROUTES } from '@/router/routes';

export function ConsumablesPage() {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="المستهلكات"
        subtitle="إدارة المواد المستهلكة في الإنتاج والعمليات"
        breadcrumbs={[
          { label: 'الرئيسية', to: ROUTES.dashboard },
          { label: 'المخزون', to: ROUTES.inventoryProducts },
          { label: 'المستهلكات' },
        ]}
      />
      <Card>
        <CardContent className="flex flex-col items-center justify-center gap-3 py-16 text-center">
          <Utensils className="size-10 text-muted-foreground" />
          <p className="font-medium">المستهلكات</p>
          <p className="text-muted-foreground text-sm max-w-sm">
            إدارة المستهلكات قادمة قريبًا. سيتتبع هذا القسم المستلزمات كعوامل التنظيف والزيوت والقفازات وغيرها من العناصر المستهلكة في الإنتاج.
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
