import { Factory } from 'lucide-react';

import { PageHeader } from '@/components/crud';
import { Card, CardContent } from '@/components/ui/card';
import { ROUTES } from '@/router/routes';

export function SemiFinishedMaterialsPage() {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Semi-Finished Materials"
        subtitle="Manage work-in-progress and semi-finished goods"
        breadcrumbs={[
          { label: 'Home', to: ROUTES.dashboard },
          { label: 'Inventory', to: ROUTES.inventoryProducts },
          { label: 'Semi-Finished' },
        ]}
      />
      <Card>
        <CardContent className="flex flex-col items-center justify-center gap-3 py-16 text-center">
          <Factory className="size-10 text-muted-foreground" />
          <p className="font-medium">Semi-Finished Materials</p>
          <p className="text-muted-foreground text-sm max-w-sm">
            Semi-finished materials management is coming soon. This section will track
            work-in-progress items and intermediate goods used in multi-stage production.
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
