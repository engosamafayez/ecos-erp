import { AlertTriangle, MapPin, MinusCircle } from 'lucide-react';

import { PageDrawer } from '@/components/page/drawer/page-drawer';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

import { useCapacityPlans, useServiceArea } from '../hooks/use-network';
import type { CapacityUnit, ServiceAreaMember } from '../types/network';
import { AreaStatusBadge } from './area-status-badge';

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-0.5">
      <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <div className="text-sm">{children}</div>
    </div>
  );
}

function Overview({ areaId }: { areaId: string }) {
  const { data: area } = useServiceArea(areaId);

  if (!area) return <Skeleton className="h-64 w-full" />;

  const included = (area.members ?? []).filter((m) => !m.is_excluded);
  const excluded = (area.members ?? []).filter((m) => m.is_excluded);

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 gap-4">
        <Field label="Status">
          <AreaStatusBadge status={area.status} />
        </Field>
        <Field label="Code">{area.code}</Field>
        <Field label="Dispatch region">{area.dispatch_region?.name ?? '—'}</Field>
        <Field label="Lead time">{area.default_lead_time_hours} hours</Field>
        <Field label="Priority">{area.priority}</Field>
        <Field label="Accepts commitments">{area.accepts_commitments ? 'Yes' : 'No'}</Field>
      </div>

      {area.status_reason && (
        <Alert>
          <AlertDescription className="text-xs">{area.status_reason}</AlertDescription>
        </Alert>
      )}

      {included.length === 0 && (
        <Alert>
          <AlertTriangle className="size-4" />
          <AlertDescription className="text-xs">
            This area has no members, so it covers nothing and will never match an address.
            Attach a zone or city before activating it.
          </AlertDescription>
        </Alert>
      )}

      <Separator />

      <div className="space-y-2">
        <p className="text-sm font-medium">Coverage</p>
        <p className="text-[11px] text-muted-foreground">
          Composed from zones and cities that already exist. Names are read live from the
          geography master — nothing is copied here.
        </p>

        <div className="space-y-1.5 pt-1">
          {included.map((member: ServiceAreaMember) => (
            <div key={member.id} className="flex items-center gap-2 text-xs">
              <MapPin className="size-3 shrink-0 text-emerald-600" />
              <span>{member.name ?? member.member_id}</span>
              <Badge variant="outline" className="text-[10px]">
                {member.member_type_label}
              </Badge>
            </div>
          ))}

          {excluded.length > 0 && (
            <>
              <p className="pt-2 text-[11px] uppercase tracking-wide text-muted-foreground">
                Carved out
              </p>
              {excluded.map((member: ServiceAreaMember) => (
                <div key={member.id} className="flex items-center gap-2 text-xs">
                  <MinusCircle className="size-3 shrink-0 text-destructive" />
                  <span className="text-muted-foreground line-through">
                    {member.name ?? member.member_id}
                  </span>
                  <Badge variant="outline" className="text-[10px]">
                    {member.member_type_label}
                  </Badge>
                </div>
              ))}
            </>
          )}
        </div>
      </div>

      {(area.coverage_rules ?? []).length > 0 && (
        <>
          <Separator />
          <div className="space-y-2">
            <p className="text-sm font-medium">Service levels</p>
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b text-muted-foreground">
                  <th className="py-1 text-left font-normal">Level</th>
                  <th className="py-1 text-left font-normal">Cutoff</th>
                  <th className="py-1 text-right font-normal">Lead time</th>
                  <th className="py-1 text-right font-normal">Surcharge</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {(area.coverage_rules ?? []).map((rule) => (
                  <tr key={rule.id}>
                    <td className="py-1">{rule.service_level ?? '—'}</td>
                    <td className="py-1 text-muted-foreground">{rule.cutoff_time ?? '—'}</td>
                    <td className="py-1 text-right tabular-nums">{rule.lead_time_hours}h</td>
                    <td className="py-1 text-right tabular-nums">
                      {rule.surcharge !== null ? `${rule.surcharge} ${rule.currency ?? ''}` : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  );
}

const UNIT_LABELS: Record<CapacityUnit, string> = {
  orders: 'orders',
  stops: 'stops',
  weight_kg: 'kg',
  volume_m3: 'm³',
};

function Capacity({ areaId }: { areaId: string }) {
  const { data: plans, isLoading } = useCapacityPlans(areaId);

  if (isLoading) return <Skeleton className="h-48 w-full" />;
  if (!plans || plans.length === 0) {
    return (
      <p className="py-8 text-center text-sm text-muted-foreground">
        No capacity plans published for this area.
      </p>
    );
  }

  return (
    <div className="space-y-3">
      {plans.map((plan) => (
        <div key={plan.id} className="rounded-lg border p-3">
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium">{plan.plan_date}</span>
            <Badge variant={plan.is_published ? 'default' : 'outline'} className="text-[10px]">
              {plan.is_published ? 'Published' : 'Draft'}
            </Badge>
          </div>

          <div className="mt-2 space-y-2">
            {plan.slots.map((slot) => (
              <div key={slot.id} className="rounded-md bg-muted/30 p-2">
                <div className="flex items-center justify-between text-xs">
                  <span className="text-muted-foreground">
                    {slot.window_start && slot.window_end
                      ? `${slot.window_start}–${slot.window_end}`
                      : 'All day'}
                  </span>
                  {slot.is_exhausted ? (
                    <Badge variant="destructive" className="text-[10px]">
                      Exhausted
                    </Badge>
                  ) : slot.at_warn_threshold ? (
                    <Badge className="bg-amber-500 text-[10px] hover:bg-amber-500">
                      Near capacity
                    </Badge>
                  ) : null}
                </div>

                {slot.utilisation !== null && (
                  <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                      className={`h-full ${
                        slot.is_exhausted
                          ? 'bg-destructive'
                          : slot.at_warn_threshold
                            ? 'bg-amber-500'
                            : 'bg-emerald-600'
                      }`}
                      style={{ width: `${Math.min(100, Math.round(slot.utilisation * 100))}%` }}
                    />
                  </div>
                )}

                <div className="mt-1.5 flex flex-wrap gap-2 text-[11px] text-muted-foreground">
                  {(Object.entries(slot.remaining) as [CapacityUnit, number][])
                    .filter(([, value]) => value !== 0)
                    .map(([unit, value]) => (
                      <span key={unit} className="tabular-nums">
                        {value} {UNIT_LABELS[unit]} left
                        {/* The tightest dimension is what actually stops us. */}
                        {slot.binding_unit === unit && ' · binding'}
                      </span>
                    ))}
                </div>
              </div>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}

export function ServiceAreaDrawer({
  areaId,
  open,
  onOpenChange,
}: {
  areaId: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { data: area } = useServiceArea(open ? areaId : null);

  return (
    <PageDrawer
      open={open}
      onOpenChange={onOpenChange}
      size="2xl"
      title={area ? `Service Area · ${area.name}` : 'Service Area'}
      description={area?.code}
    >
      {!areaId ? null : (
        <Tabs defaultValue="overview" className="w-full">
          <TabsList>
            <TabsTrigger value="overview">Overview</TabsTrigger>
            <TabsTrigger value="capacity">Capacity</TabsTrigger>
          </TabsList>

          <TabsContent value="overview" className="pt-4">
            <Overview areaId={areaId} />
          </TabsContent>
          <TabsContent value="capacity" className="pt-4">
            <Capacity areaId={areaId} />
          </TabsContent>
        </Tabs>
      )}
    </PageDrawer>
  );
}
