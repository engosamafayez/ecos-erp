import { Factory } from 'lucide-react';

import { PageHeader } from '@/components/crud';
import { Card, CardContent } from '@/components/ui/card';
import { ROUTES } from '@/router/routes';

export function SemiFinishedMaterialsPage() {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="المواد نصف المصنّعة"
        subtitle="إدارة المنتجات تحت التصنيع والبضائع نصف المصنّعة"
        breadcrumbs={[
          { label: 'الرئيسية', to: ROUTES.dashboard },
          { label: 'المخزون', to: ROUTES.inventoryProducts },
          { label: 'نصف مصنّع' },
        ]}
      />
      <Card>
        <CardContent className="flex flex-col items-center justify-center gap-3 py-16 text-center">
          <Factory className="size-10 text-muted-foreground" />
          <p className="font-medium">المواد نصف المصنّعة</p>
          <p className="text-muted-foreground text-sm max-w-sm">
            إدارة المواد نصف المصنّعة قادمة قريبًا. سيتتبع هذا القسم عناصر الإنتاج الجاري والبضائع الوسيطة المستخدمة في الإنتاج متعدد المراحل.
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
