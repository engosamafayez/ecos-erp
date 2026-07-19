import { Package } from 'lucide-react';

import { PageHeader } from '@/components/crud';
import { Card, CardContent } from '@/components/ui/card';
import { ROUTES } from '@/router/routes';

export function PackagingMaterialsPage() {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="مواد التغليف"
        subtitle="إدارة مواد التغليف المستخدمة في الإنتاج"
        breadcrumbs={[
          { label: 'الرئيسية', to: ROUTES.dashboard },
          { label: 'المخزون', to: ROUTES.inventoryProducts },
          { label: 'مواد التغليف' },
        ]}
      />
      <Card>
        <CardContent className="flex flex-col items-center justify-center gap-3 py-16 text-center">
          <Package className="size-10 text-muted-foreground" />
          <p className="font-medium">مواد التغليف</p>
          <p className="text-muted-foreground text-sm max-w-sm">
            إدارة مواد التغليف قادمة قريبًا. سيتيح لك هذا القسم تتبع الصناديق والأكياس والملصقات وغيرها من مواد التغليف المستخدمة في الإنتاج.
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
