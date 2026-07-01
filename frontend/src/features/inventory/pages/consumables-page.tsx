import { Utensils } from 'lucide-react';

import { PageHeader } from '@/components/crud';
import { Card, CardContent } from '@/components/ui/card';
import { ROUTES } from '@/router/routes';

export function ConsumablesPage() {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Consumables"
        subtitle="Manage consumable items used in production and operations"
        breadcrumbs={[
          { label: 'Home', to: ROUTES.dashboard },
          { label: 'Inventory', to: ROUTES.inventoryProducts },
          { label: 'Consumables' },
        ]}
      />
      <Card>
        <CardContent className="flex flex-col items-center justify-center gap-3 py-16 text-center">
          <Utensils className="size-10 text-muted-foreground" />
          <p className="font-medium">Consumables</p>
          <p className="text-muted-foreground text-sm max-w-sm">
            Consumables management is coming soon. This section will track supplies like cleaning
            agents, lubricants, gloves, and other items consumed during production.
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
