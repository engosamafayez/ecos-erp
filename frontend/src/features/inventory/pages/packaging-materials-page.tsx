import { Package } from 'lucide-react';

import { PageHeader } from '@/components/crud';
import { Card, CardContent } from '@/components/ui/card';
import { ROUTES } from '@/router/routes';

export function PackagingMaterialsPage() {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Packaging Materials"
        subtitle="Manage packaging materials used in production"
        breadcrumbs={[
          { label: 'Home', to: ROUTES.dashboard },
          { label: 'Inventory', to: ROUTES.inventoryProducts },
          { label: 'Packaging Materials' },
        ]}
      />
      <Card>
        <CardContent className="flex flex-col items-center justify-center gap-3 py-16 text-center">
          <Package className="size-10 text-muted-foreground" />
          <p className="font-medium">Packaging Materials</p>
          <p className="text-muted-foreground text-sm max-w-sm">
            Packaging materials management is coming soon. This section will allow you to track boxes, bags, labels, and other packaging materials used in production.
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
