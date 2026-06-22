import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

const SERVICES = ['API', 'Database', 'Queue', 'Mail'];

/**
 * System status panel (placeholder — not connected to the backend).
 */
export function SystemStatus() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>System Status</CardTitle>
        <CardDescription>Placeholder — not connected to the backend.</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {SERVICES.map((service) => (
          <div key={service} className="flex items-center justify-between">
            <span className="text-sm">{service}</span>
            <Badge variant="secondary" className="gap-1.5">
              <span className="size-1.5 rounded-full bg-emerald-500" />
              Operational
            </Badge>
          </div>
        ))}
      </CardContent>
    </Card>
  );
}
