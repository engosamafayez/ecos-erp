import { Link } from 'react-router-dom';
import {
  AlertCircle,
  CheckCircle2,
  Clock,
  Factory,
  Package,
  ShoppingBag,
  ShoppingCart,
  Truck,
  Users,
  XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { ROUTES } from '@/router/routes';

type WorkflowStatus = {
  waiting: number;
  blocked: number;
  completed: number;
  alert?: string;
};

type Workflow = {
  key: string;
  label: string;
  icon: LucideIcon;
  iconCls: string;
  bgCls: string;
  href: string;
  status: WorkflowStatus;
};

const WORKFLOWS: Workflow[] = [
  {
    key: 'orders',
    label: 'Orders',
    icon: ShoppingCart,
    iconCls: 'text-indigo-500',
    bgCls: 'bg-indigo-500/10',
    href: ROUTES.orders,
    status: { waiting: 0, blocked: 0, completed: 0 },
  },
  {
    key: 'inventory',
    label: 'Inventory',
    icon: Package,
    iconCls: 'text-emerald-500',
    bgCls: 'bg-emerald-500/10',
    href: ROUTES.inventory,
    status: { waiting: 0, blocked: 0, completed: 0 },
  },
  {
    key: 'manufacturing',
    label: 'Manufacturing',
    icon: Factory,
    iconCls: 'text-violet-500',
    bgCls: 'bg-violet-500/10',
    href: ROUTES.waveWorkspace,
    status: { waiting: 0, blocked: 0, completed: 0 },
  },
  {
    key: 'procurement',
    label: 'Procurement',
    icon: ShoppingBag,
    iconCls: 'text-amber-500',
    bgCls: 'bg-amber-500/10',
    href: ROUTES.procurementHub,
    status: { waiting: 0, blocked: 0, completed: 0 },
  },
  {
    key: 'logistics',
    label: 'Logistics',
    icon: Truck,
    iconCls: 'text-cyan-500',
    bgCls: 'bg-cyan-500/10',
    href: ROUTES.distributionBoard,
    status: { waiting: 0, blocked: 0, completed: 0 },
  },
  {
    key: 'crm',
    label: 'CRM',
    icon: Users,
    iconCls: 'text-pink-500',
    bgCls: 'bg-pink-500/10',
    href: ROUTES.customers,
    status: { waiting: 0, blocked: 0, completed: 0 },
  },
];

function WorkflowCard({ wf }: { wf: Workflow }) {
  const { label, icon: Icon, iconCls, bgCls, href, status } = wf;
  const blocked = status.blocked > 0;

  return (
    <Link to={href} className="block group">
      <div
        className={cn(
          'rounded-lg border p-3 transition-all group-hover:shadow-sm group-hover:border-border/80',
          blocked ? 'border-red-500/30 bg-red-500/5' : 'bg-card',
        )}
      >
        {/* Header row */}
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-2">
            <div className={cn('h-7 w-7 rounded-md flex items-center justify-center shrink-0', bgCls)}>
              <Icon className={cn('h-3.5 w-3.5', iconCls)} />
            </div>
            <span className="text-sm font-semibold">{label}</span>
          </div>
          {blocked ? (
            <Badge variant="destructive" className="text-[10px] h-4 px-1.5">
              Blocked
            </Badge>
          ) : (
            <span className="flex items-center gap-1 text-[10px] text-muted-foreground">
              <span className="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" />
              Live
            </span>
          )}
        </div>

        {/* Stats row */}
        <div className="grid grid-cols-3 gap-1 text-center">
          <div>
            <p className="text-lg font-bold leading-none mb-1">{status.waiting}</p>
            <p className="text-[10px] text-muted-foreground flex items-center justify-center gap-0.5">
              <Clock className="h-2.5 w-2.5" /> Waiting
            </p>
          </div>
          <div>
            <p className={cn('text-lg font-bold leading-none mb-1', blocked && 'text-red-500')}>
              {status.blocked}
            </p>
            <p className="text-[10px] text-muted-foreground flex items-center justify-center gap-0.5">
              <XCircle className="h-2.5 w-2.5" /> Blocked
            </p>
          </div>
          <div>
            <p className="text-lg font-bold leading-none mb-1 text-emerald-600 dark:text-emerald-400">
              {status.completed}
            </p>
            <p className="text-[10px] text-muted-foreground flex items-center justify-center gap-0.5">
              <CheckCircle2 className="h-2.5 w-2.5" /> Done
            </p>
          </div>
        </div>

        {/* Alert strip */}
        {status.alert && (
          <div className="mt-2 flex items-center gap-1.5 rounded-md bg-amber-500/10 px-2 py-1.5">
            <AlertCircle className="h-3 w-3 text-amber-500 shrink-0" />
            <span className="text-[11px] text-amber-700 dark:text-amber-400 truncate">
              {status.alert}
            </span>
          </div>
        )}
      </div>
    </Link>
  );
}

export function OperationsCenter() {
  return (
    <Card className="h-full">
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between">
          <div>
            <CardTitle className="text-base">Operations Center</CardTitle>
            <p className="text-muted-foreground text-xs mt-0.5">
              Live workflow status across all departments
            </p>
          </div>
          <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <span className="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" />
            Real-time
          </span>
        </div>
      </CardHeader>
      <CardContent>
        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          {WORKFLOWS.map((wf) => (
            <WorkflowCard key={wf.key} wf={wf} />
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
