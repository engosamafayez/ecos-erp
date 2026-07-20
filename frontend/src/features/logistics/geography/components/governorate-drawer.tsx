import { useState } from 'react';
import {
  Activity,
  Building2,
  CheckCircle,
  Clock,
  DollarSign,
  Link2,
  MapPin,
  Tag,
  Truck,
  XCircle,
} from 'lucide-react';

import { Badge }  from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input }  from '@/components/ui/input';
import { Label }  from '@/components/ui/label';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { PageDrawer } from '@/components/page/drawer/page-drawer';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useToast } from '@/components/ds/use-toast';
import type { Governorate } from '@/features/logistics/geography/types/geography';
import {
  useCities,
  useCreateCity,
  useDeleteGovernorate,
  useToggleCityStatus,
  useToggleGovernorateStatus,
  useUpdateGovernorate,
} from '@/features/logistics/geography/hooks/use-geography';
import { CityDrawer } from './city-drawer';

// ── Overview Tab ──────────────────────────────────────────────────────────────

function OverviewTab({
  gov,
  onToggleStatus,
  onDelete,
}: {
  gov: Governorate;
  onToggleStatus: () => void;
  onDelete: () => void;
}) {
  const { toast } = useToast();
  const update    = useUpdateGovernorate();

  const [nameAr, setNameAr] = useState(gov.name_ar);
  const [nameEn, setNameEn] = useState(gov.name_en);
  const [price,  setPrice]  = useState(String(gov.default_shipping_price));
  const [dirty,  setDirty]  = useState(false);

  const markDirty = () => setDirty(true);

  const handleSave = async () => {
    try {
      await update.mutateAsync({
        id: gov.id,
        payload: {
          name_ar: nameAr.trim(),
          name_en: nameEn.trim(),
          default_shipping_price: parseFloat(price) || 0,
        },
      });
      toast({ title: 'Governorate saved' });
      setDirty(false);
    } catch {
      toast({ title: 'Save failed', variant: 'destructive' });
    }
  };

  return (
    <div className="space-y-6">
      {/* Status badges */}
      <div className="flex items-center gap-2 flex-wrap">
        <Badge variant={gov.is_active ? 'default' : 'secondary'}>
          {gov.is_active ? 'Active' : 'Inactive'}
        </Badge>
        {gov.is_system && (
          <Badge variant="outline" className="text-xs">System Record</Badge>
        )}
      </div>

      {/* Edit fields */}
      <div className="space-y-4">
        <div className="space-y-1.5">
          <Label>Name (English)</Label>
          <Input
            value={nameEn}
            onChange={(e) => { setNameEn(e.target.value); markDirty(); }}
          />
        </div>
        <div className="space-y-1.5">
          <Label>Name (Arabic)</Label>
          <Input
            value={nameAr}
            onChange={(e) => { setNameAr(e.target.value); markDirty(); }}
            dir="rtl"
          />
        </div>
        <div className="space-y-1.5">
          <Label>Default Shipping Price (EGP)</Label>
          <Input
            type="number"
            min={0}
            step={0.5}
            value={price}
            onChange={(e) => { setPrice(e.target.value); markDirty(); }}
          />
          <p className="text-xs text-muted-foreground">
            Cities without a custom price inherit this value.
          </p>
        </div>

        {dirty && (
          <Button onClick={handleSave} disabled={update.isPending} className="w-full">
            {update.isPending ? 'Saving…' : 'Save Changes'}
          </Button>
        )}
      </div>

      {/* KPI summary */}
      <div className="grid grid-cols-2 gap-3">
        <div className="border rounded-lg p-3">
          <p className="text-xs text-muted-foreground">Cities</p>
          <p className="text-2xl font-semibold mt-0.5 tabular-nums">{gov.cities_count ?? 0}</p>
        </div>
        <div className="border rounded-lg p-3">
          <p className="text-xs text-muted-foreground">Default Shipping</p>
          <p className="text-2xl font-semibold mt-0.5 tabular-nums">
            {gov.default_shipping_price} <span className="text-sm font-normal">EGP</span>
          </p>
        </div>
      </div>

      {/* Danger zone — only for non-system records */}
      {!gov.is_system && (
        <div className="border border-destructive/30 rounded-lg p-4 space-y-2">
          <p className="text-sm font-medium text-destructive">Danger Zone</p>
          <div className="flex flex-wrap gap-2">
            <Button
              size="sm"
              variant="outline"
              onClick={onToggleStatus}
              className="gap-1.5"
            >
              {gov.is_active
                ? <><XCircle className="h-3.5 w-3.5" />Deactivate</>
                : <><CheckCircle className="h-3.5 w-3.5" />Activate</>}
            </Button>
            <Button size="sm" variant="destructive" onClick={onDelete}>
              Delete Governorate
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

// ── Cities Tab ────────────────────────────────────────────────────────────────

function CitiesTab({ gov }: { gov: Governorate }) {
  const { toast } = useToast();
  const [search,         setSearch]         = useState('');
  const [selectedCityId, setSelectedCityId] = useState<number | null>(null);
  const [showNewCity,    setShowNewCity]     = useState(false);
  const [newNameEn,      setNewNameEn]       = useState('');
  const [newNameAr,      setNewNameAr]       = useState('');
  const [newPrice,       setNewPrice]        = useState('');

  const { data, isFetching } = useCities(gov.id, { search, per_page: 200 });
  const cities = data?.data ?? [];

  const createCity = useCreateCity();
  const toggleCity = useToggleCityStatus();

  const handleCreate = async () => {
    if (!newNameEn.trim()) return;
    try {
      await createCity.mutateAsync({
        governorateId: gov.id,
        payload: {
          name_en: newNameEn.trim(),
          name_ar: newNameAr.trim(),
          shipping_price: newPrice ? parseFloat(newPrice) : null,
        },
      });
      toast({ title: 'City added' });
      setShowNewCity(false);
      setNewNameEn('');
      setNewNameAr('');
      setNewPrice('');
    } catch {
      toast({ title: 'Failed to add city', variant: 'destructive' });
    }
  };

  const selectedCity = cities.find((c) => c.id === selectedCityId) ?? null;

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-2">
        <Input
          placeholder="Search cities…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="h-8 text-sm"
        />
        <Button size="sm" onClick={() => setShowNewCity(true)} className="shrink-0">
          Add City
        </Button>
      </div>

      {showNewCity && (
        <div className="border rounded-lg p-3 space-y-2 bg-muted/30">
          <p className="text-xs font-medium">New City</p>
          <div className="grid grid-cols-2 gap-2">
            <Input
              placeholder="Name (English) *"
              value={newNameEn}
              onChange={(e) => setNewNameEn(e.target.value)}
              className="h-8 text-sm"
            />
            <Input
              placeholder="Arabic name"
              value={newNameAr}
              onChange={(e) => setNewNameAr(e.target.value)}
              className="h-8 text-sm"
              dir="rtl"
            />
          </div>
          <Input
            type="number"
            placeholder={`Custom shipping price (blank = inherit ${gov.default_shipping_price} EGP)`}
            value={newPrice}
            onChange={(e) => setNewPrice(e.target.value)}
            className="h-8 text-sm"
          />
          <div className="flex gap-2">
            <Button
              size="sm"
              onClick={handleCreate}
              disabled={createCity.isPending || !newNameEn.trim()}
            >
              {createCity.isPending ? 'Adding…' : 'Add City'}
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setShowNewCity(false)}>
              Cancel
            </Button>
          </div>
        </div>
      )}

      {isFetching && cities.length === 0 ? (
        <p className="text-sm text-muted-foreground py-8 text-center">Loading cities…</p>
      ) : cities.length === 0 ? (
        <p className="text-sm text-muted-foreground py-8 text-center">No cities found</p>
      ) : (
        <div className="border rounded-lg divide-y">
          {cities.map((city) => (
            <div
              key={city.id}
              className="flex items-center gap-2 px-3 py-2.5 hover:bg-muted/30 transition-colors"
            >
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="text-sm font-medium">{city.name_en}</span>
                  {!city.is_active && (
                    <Badge variant="secondary" className="text-xs shrink-0">Inactive</Badge>
                  )}
                  {city.is_remote_area && (
                    <Badge variant="outline" className="text-xs shrink-0">Remote</Badge>
                  )}
                  {city.uses_governorate_price && (
                    <span className="text-xs text-muted-foreground shrink-0">↑ gov price</span>
                  )}
                </div>
                <div className="flex items-center gap-3 text-xs text-muted-foreground mt-0.5">
                  <span dir="rtl">{city.name_ar}</span>
                  <span className="tabular-nums">{city.effective_shipping_price} EGP</span>
                  {city.aliases_count > 0 && <span>{city.aliases_count} alias(es)</span>}
                </div>
              </div>
              <div className="flex items-center gap-1 shrink-0">
                <Button
                  size="sm"
                  variant="ghost"
                  className="h-7 px-2 text-xs"
                  onClick={() => setSelectedCityId(city.id)}
                >
                  Edit
                </Button>
                {city.is_system ? (
                  <TooltipProvider>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <span className="inline-flex">
                          <Button
                            size="sm"
                            variant="ghost"
                            className="h-7 px-2 text-xs text-muted-foreground cursor-not-allowed"
                            disabled
                          >
                            {city.is_active ? 'Deactivate' : 'Activate'}
                          </Button>
                        </span>
                      </TooltipTrigger>
                      <TooltipContent>
                        System cities are managed by seed only.
                      </TooltipContent>
                    </Tooltip>
                  </TooltipProvider>
                ) : (
                  <Button
                    size="sm"
                    variant="ghost"
                    className="h-7 px-2 text-xs text-muted-foreground"
                    onClick={async () => {
                      try {
                        await toggleCity.mutateAsync({ governorateId: gov.id, cityId: city.id });
                      } catch {
                        toast({ title: 'Failed', variant: 'destructive' });
                      }
                    }}
                  >
                    {city.is_active ? 'Deactivate' : 'Activate'}
                  </Button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      <CityDrawer
        city={selectedCity}
        governorateId={gov.id}
        defaultShippingPrice={gov.default_shipping_price}
        onClose={() => setSelectedCityId(null)}
      />
    </div>
  );
}

// ── Providers Tab ─────────────────────────────────────────────────────────────

const KNOWN_PROVIDERS = [
  { id: 'bosta',  label: 'Bosta',  icon: Truck },
  { id: 'mylerz', label: 'Mylerz', icon: Truck },
  { id: 'smsa',   label: 'SMSA',   icon: Truck },
  { id: 'aramex', label: 'Aramex', icon: Truck },
];

function ProvidersTab({ gov }: { gov: Governorate }) {
  const { data } = useCities(gov.id, { per_page: 200 });
  const cities   = data?.data ?? [];

  const providerCounts = KNOWN_PROVIDERS.map((p) => ({
    ...p,
    count: cities.filter((c) => c.aliases?.some((a) => a.provider === p.id)).length,
  }));

  const totalMapped = providerCounts.reduce((n, p) => n + p.count, 0);

  if (totalMapped === 0) {
    return (
      <div className="py-12 flex flex-col items-center gap-4 text-center">
        <span className="bg-muted flex h-12 w-12 items-center justify-center rounded-full">
          <Link2 className="h-6 w-6 text-muted-foreground" />
        </span>
        <div>
          <p className="font-medium">No Shipping Providers Linked Yet</p>
          <p className="text-sm text-muted-foreground mt-1 max-w-xs">
            Add provider aliases to cities in the Cities tab. Each alias maps a city name
            to how a courier labels it in their system.
          </p>
        </div>
        <TooltipProvider>
          <Tooltip>
            <TooltipTrigger asChild>
              <span className="inline-flex">
                <Button size="sm" disabled className="gap-1.5">
                  <Link2 className="h-3.5 w-3.5" />
                  Link Provider
                </Button>
              </span>
            </TooltipTrigger>
            <TooltipContent>
              Direct provider integrations — coming in Logistics Phase 2.
            </TooltipContent>
          </Tooltip>
        </TooltipProvider>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">
        City aliases define how each courier labels this governorate's cities.
      </p>
      <div className="grid grid-cols-2 gap-3">
        {providerCounts.map((p) => (
          <div key={p.id} className="border rounded-lg p-3">
            <div className="flex items-center gap-2 mb-1">
              <Truck className="h-4 w-4 text-muted-foreground" />
              <span className="font-medium text-sm">{p.label}</span>
            </div>
            <p className="text-xs text-muted-foreground">
              {p.count > 0 ? `${p.count} city alias(es)` : 'No aliases yet'}
            </p>
          </div>
        ))}
      </div>
    </div>
  );
}

// ── Activity Tab ──────────────────────────────────────────────────────────────

type AuditEvent = {
  id: string;
  icon: typeof Activity;
  label: string;
  meta: string;
  time: string;
  color: string;
};

function ActivityTab({ gov }: { gov: Governorate }) {
  const events: AuditEvent[] = [
    {
      id:    'created',
      icon:  Tag,
      label: 'Governorate seeded',
      meta:  `${gov.name_en} added as a system record`,
      time:  gov.created_at,
      color: 'text-blue-500',
    },
  ];

  const fmt = (iso: string) =>
    new Date(iso).toLocaleDateString('en-EG', {
      year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    });

  return (
    <div className="space-y-1">
      <p className="text-xs text-muted-foreground mb-4">
        Chronological record of changes. Price changes, status changes, and alias activity appear here.
      </p>

      {events.length === 0 ? (
        <div className="py-10 text-center">
          <Activity className="h-8 w-8 text-muted-foreground/30 mx-auto mb-2" />
          <p className="text-sm text-muted-foreground">No activity recorded yet.</p>
        </div>
      ) : (
        <div className="relative pl-5 space-y-4">
          <div className="absolute left-2 top-1 bottom-1 w-px bg-border" />
          {events.map((ev) => {
            const Icon = ev.icon;
            return (
              <div key={ev.id} className="relative flex gap-3">
                <span className={`absolute -left-3 mt-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-background ring-2 ring-border ${ev.color}`}>
                  <Icon className="h-3 w-3" />
                </span>
                <div className="flex-1 min-w-0 pl-2">
                  <p className="text-sm font-medium">{ev.label}</p>
                  <p className="text-xs text-muted-foreground mt-0.5">{ev.meta}</p>
                  <div className="flex items-center gap-1 mt-1 text-xs text-muted-foreground">
                    <Clock className="h-3 w-3" />
                    {fmt(ev.time)}
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Upcoming event types legend */}
      <div className="mt-6 border rounded-lg p-3 space-y-1.5">
        <p className="text-xs font-medium text-muted-foreground">Tracked Events</p>
        {[
          { icon: DollarSign, label: 'Price changed' },
          { icon: CheckCircle, label: 'Status changed' },
          { icon: Tag, label: 'Alias added / removed' },
          { icon: MapPin, label: 'City order changed' },
          { icon: Activity, label: 'Child city status changed' },
        ].map((item) => {
          const Icon = item.icon;
          return (
            <div key={item.label} className="flex items-center gap-2 text-xs text-muted-foreground">
              <Icon className="h-3 w-3" />
              <span>{item.label}</span>
            </div>
          );
        })}
      </div>
    </div>
  );
}

// ── Main Drawer ───────────────────────────────────────────────────────────────

type Props = {
  governorate: Governorate | null;
  onClose: () => void;
};

export function GovernorateDrawer({ governorate, onClose }: Props) {
  const { toast }    = useToast();
  const toggleStatus = useToggleGovernorateStatus();
  const deleteGov    = useDeleteGovernorate();

  const handleToggle = async () => {
    if (!governorate) return;
    try {
      await toggleStatus.mutateAsync(governorate.id);
      const wasActive = governorate.is_active;
      toast({
        title: wasActive ? 'Governorate deactivated' : 'Governorate activated',
        description: wasActive ? 'All child cities have been deactivated.' : undefined,
      });
    } catch {
      toast({ title: 'Failed', variant: 'destructive' });
    }
  };

  const handleDelete = async () => {
    if (!governorate) return;
    try {
      await deleteGov.mutateAsync(governorate.id);
      toast({ title: 'Governorate deleted' });
      onClose();
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Failed to delete';
      toast({ title: 'Error', description: msg, variant: 'destructive' });
    }
  };

  return (
    <PageDrawer
      open={Boolean(governorate)}
      onOpenChange={(o) => !o && onClose()}
      title={governorate ? `${governorate.name_en} — ${governorate.name_ar}` : ''}
      description={governorate ? `Governorate · ${governorate.cities_count ?? 0} cities · ${governorate.default_shipping_price} EGP default` : ''}
      size="xl"
    >
      {governorate && (
        <Tabs defaultValue="overview">
          <TabsList className="w-full mb-4">
            <TabsTrigger value="overview"  className="flex-1">
              <Building2 className="h-3.5 w-3.5 mr-1.5" />Overview
            </TabsTrigger>
            <TabsTrigger value="cities"    className="flex-1">
              <MapPin className="h-3.5 w-3.5 mr-1.5" />Cities
            </TabsTrigger>
            <TabsTrigger value="providers" className="flex-1">
              <Truck className="h-3.5 w-3.5 mr-1.5" />Providers
            </TabsTrigger>
            <TabsTrigger value="activity"  className="flex-1">
              <Activity className="h-3.5 w-3.5 mr-1.5" />Activity
            </TabsTrigger>
          </TabsList>

          <TabsContent value="overview">
            <OverviewTab
              gov={governorate}
              onToggleStatus={handleToggle}
              onDelete={handleDelete}
            />
          </TabsContent>

          <TabsContent value="cities">
            <CitiesTab gov={governorate} />
          </TabsContent>

          <TabsContent value="providers">
            <ProvidersTab gov={governorate} />
          </TabsContent>

          <TabsContent value="activity">
            <ActivityTab gov={governorate} />
          </TabsContent>
        </Tabs>
      )}
    </PageDrawer>
  );
}
