import { Building2, FileBarChart, Package, UserPlus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

const ACTIONS = [
  { label: 'Add Company', icon: Building2 },
  { label: 'New Product', icon: Package },
  { label: 'Invite User', icon: UserPlus },
  { label: 'View Reports', icon: FileBarChart },
];

/**
 * Quick action shortcuts (placeholder — buttons are not yet wired).
 */
export function QuickActions() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Quick Actions</CardTitle>
        <CardDescription>Common shortcuts (placeholder).</CardDescription>
      </CardHeader>
      <CardContent className="grid grid-cols-2 gap-3">
        {ACTIONS.map((action) => {
          const Icon = action.icon;
          return (
            <Button key={action.label} variant="outline" className="h-auto flex-col gap-2 py-4">
              <Icon className="size-5" />
              <span className="text-xs">{action.label}</span>
            </Button>
          );
        })}
      </CardContent>
    </Card>
  );
}
