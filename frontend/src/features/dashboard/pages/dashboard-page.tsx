import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

const PLACEHOLDER_ROWS = [
  { id: 1, module: 'Inventory', status: 'Planned' },
  { id: 2, module: 'Sales', status: 'Planned' },
  { id: 3, module: 'Accounting', status: 'Planned' },
];

/**
 * Placeholder dashboard (route `/dashboard`). Showcases reusable UI components;
 * contains no business logic.
 */
export function DashboardPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Dashboard</h1>
          <p className="text-muted-foreground text-sm">Placeholder page — no business logic.</p>
        </div>
        <Dialog>
          <DialogTrigger asChild>
            <Button>Open dialog</Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Reusable dialog</DialogTitle>
              <DialogDescription>This dialog is a reusable shadcn/ui component.</DialogDescription>
            </DialogHeader>
          </DialogContent>
        </Dialog>
      </div>

      <Separator />

      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle>Modules</CardTitle>
            <CardDescription>Future ERP modules</CardDescription>
          </CardHeader>
          <CardContent>
            <Badge variant="secondary">0 active</Badge>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Loading state</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-2">
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-2/3" />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Status</CardTitle>
          </CardHeader>
          <CardContent>
            <Badge>Operational</Badge>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Planned modules</CardTitle>
        </CardHeader>
        <CardContent>
          <Table>
            <TableCaption>A list of planned ERP modules.</TableCaption>
            <TableHeader>
              <TableRow>
                <TableHead>#</TableHead>
                <TableHead>Module</TableHead>
                <TableHead>Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {PLACEHOLDER_ROWS.map((row) => (
                <TableRow key={row.id}>
                  <TableCell>{row.id}</TableCell>
                  <TableCell>{row.module}</TableCell>
                  <TableCell>
                    <Badge variant="outline">{row.status}</Badge>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  );
}
