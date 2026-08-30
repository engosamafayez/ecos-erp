/* eslint-disable ecos-i18n/no-hardcoded-ui-strings -- the brands feature is not i18n-wired; matches its sibling tabs (brand-shipping-tab, etc.) */
import { Warehouse as WarehouseIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Skeleton } from '@/components/ui/skeleton';
import { useToast } from '@/components/ds/use-toast';
import {
  useBrandWarehouseCoverage,
  useSaveBrandWarehouseCoverage,
} from '@/features/brands/hooks/use-brand-warehouse-coverage';

type Props = { brandId: string };

function sameSet(a: Set<string>, b: Set<string>): boolean {
  if (a.size !== b.size) return false;
  for (const v of a) if (!b.has(v)) return false;
  return true;
}

/**
 * Brand → Settings → Warehouses. Select which of THIS company's warehouses serve
 * the brand. An unselected warehouse cannot serve the brand (fail-closed). No
 * auto-assignment: a new brand serves no warehouse until saved here.
 */
export function BrandWarehousesTab({ brandId }: Props) {
  const { toast } = useToast();
  const query = useBrandWarehouseCoverage(brandId);
  const save = useSaveBrandWarehouseCoverage(brandId);

  const rows = useMemo(() => query.data ?? [], [query.data]);
  const serverSelected = useMemo(
    () => new Set(rows.filter((r) => r.serves_brand).map((r) => r.id)),
    [rows],
  );

  // `override` is null until the operator touches a checkbox — then it holds the
  // edited set. This derives the effective selection without a setState-in-effect.
  const [override, setOverride] = useState<Set<string> | null>(null);
  const selected = override ?? serverSelected;
  const dirty = override !== null && !sameSet(override, serverSelected);

  const toggle = (id: string) =>
    setOverride((prev) => {
      const base = new Set(prev ?? serverSelected);
      if (base.has(id)) base.delete(id);
      else base.add(id);
      return base;
    });

  const onSave = () => {
    save.mutate([...selected], {
      onSuccess: () => {
        setOverride(null); // re-sync to the freshly-saved server state
        toast({ title: 'Warehouse coverage saved' });
      },
      onError: () => toast({ title: 'Could not save warehouse coverage', variant: 'destructive' }),
    });
  };

  if (query.isLoading) {
    return (
      <div className="flex flex-col gap-2 px-6 py-5">
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-10 w-full" />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4 px-6 py-5">
      <div className="flex items-start justify-between gap-3">
        <p className="text-muted-foreground text-xs leading-relaxed">
          Select which warehouses serve this brand. A warehouse that is not selected
          cannot be assigned this brand&apos;s orders.
        </p>
        <Button size="sm" onClick={onSave} disabled={!dirty || save.isPending} className="shrink-0">
          {save.isPending ? 'Saving…' : 'Save'}
        </Button>
      </div>

      {rows.length === 0 ? (
        <div className="border-muted-foreground/20 text-muted-foreground flex flex-col items-center gap-2 rounded-md border border-dashed px-4 py-10 text-center">
          <WarehouseIcon className="size-5 opacity-60" />
          <p className="text-sm">No warehouses exist for this company yet.</p>
        </div>
      ) : (
        <div className="flex flex-col gap-2">
          {rows.map((w) => (
            <label
              key={w.id}
              htmlFor={`wh-${w.id}`}
              className="hover:bg-accent/40 flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2.5"
            >
              <Checkbox
                id={`wh-${w.id}`}
                checked={selected.has(w.id)}
                onCheckedChange={() => toggle(w.id)}
              />
              <div className="bg-primary/10 flex size-7 items-center justify-center rounded shrink-0">
                <WarehouseIcon className="text-primary size-3.5" />
              </div>
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{w.name}</p>
                {w.code ? (
                  <p className="text-muted-foreground font-mono text-[11px]">{w.code}</p>
                ) : null}
              </div>
              {!w.is_active ? (
                <Badge variant="secondary" className="shrink-0 text-[10px]">
                  Inactive
                </Badge>
              ) : null}
              {selected.has(w.id) ? (
                <Badge className="shrink-0 text-[10px]">Serves brand</Badge>
              ) : null}
            </label>
          ))}
          {selected.size === 0 ? (
            <p className="text-muted-foreground pt-1 text-center text-[11px]">
              No warehouses selected — this brand is currently served by no warehouse.
            </p>
          ) : null}
        </div>
      )}
    </div>
  );
}
