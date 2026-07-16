import { useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';

import { Badge }  from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input }  from '@/components/ui/input';
import { Label }  from '@/components/ui/label';
import { PageDrawer } from '@/components/page/drawer/page-drawer';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useToast } from '@/components/ds/use-toast';
import type { City } from '@/features/logistics/geography/types/geography';
import {
  useCityAliases,
  useCreateAlias,
  useDeleteAlias,
  useUpdateCity,
} from '@/features/logistics/geography/hooks/use-geography';

const PROVIDERS = ['bosta', 'mylerz', 'smsa', 'aramex'];

type Props = {
  city: City | null;
  governorateId: number;
  defaultShippingPrice: number;
  onClose: () => void;
};

export function CityDrawer({ city, governorateId, defaultShippingPrice, onClose }: Props) {
  const { toast } = useToast();

  const [nameEn,   setNameEn]   = useState(city?.name_en ?? '');
  const [nameAr,   setNameAr]   = useState(city?.name_ar ?? '');
  const [price,    setPrice]    = useState(city?.shipping_price != null ? String(city.shipping_price) : '');
  const [dirty,    setDirty]    = useState(false);

  // Reset state when city changes
  if (city && city.name_en !== nameEn && !dirty) {
    setNameEn(city.name_en);
    setNameAr(city.name_ar);
    setPrice(city.shipping_price != null ? String(city.shipping_price) : '');
  }

  const [newAlias,    setNewAlias]    = useState('');
  const [newProvider, setNewProvider] = useState('');
  const [newCode,     setNewCode]     = useState('');
  const [aliasError,  setAliasError]  = useState<string | null>(null);

  const update      = useUpdateCity();
  const { data: aliases = [], isFetching } = useCityAliases(city?.id ?? null);
  const createAlias = useCreateAlias();
  const deleteAlias = useDeleteAlias();

  const handleSave = async () => {
    if (!city) return;
    try {
      await update.mutateAsync({
        governorateId,
        cityId: city.id,
        payload: {
          name_en: nameEn.trim(),
          name_ar: nameAr.trim(),
          shipping_price: price !== '' ? parseFloat(price) : null,
        },
      });
      toast({ title: 'City saved' });
      setDirty(false);
    } catch {
      toast({ title: 'Save failed', variant: 'destructive' });
    }
  };

  const handleAddAlias = async () => {
    if (!city || !newAlias.trim()) return;
    setAliasError(null);
    try {
      await createAlias.mutateAsync({
        cityId: city.id,
        payload: {
          alias:    newAlias.trim(),
          provider: newProvider || null,
          code:     newCode.trim() || null,
        },
      });
      toast({ title: 'Alias added' });
      setNewAlias('');
      setNewProvider('');
      setNewCode('');
    } catch (err: unknown) {
      type LaravelError = { response?: { data?: { errors?: Record<string, string[]>; message?: string } } };
      const apiErr = err as LaravelError;
      const firstField = apiErr?.response?.data?.errors;
      const msg = firstField?.alias?.[0]
        ?? apiErr?.response?.data?.message
        ?? 'Failed to add alias';
      setAliasError(msg);
    }
  };

  const handleDeleteAlias = async (aliasId: number) => {
    if (!city) return;
    try {
      await deleteAlias.mutateAsync({ cityId: city.id, aliasId });
      toast({ title: 'Alias removed' });
    } catch {
      toast({ title: 'Failed', variant: 'destructive' });
    }
  };

  const effectivePrice = price !== '' ? parseFloat(price) : defaultShippingPrice;

  return (
    <PageDrawer
      open={Boolean(city)}
      onOpenChange={(o) => !o && onClose()}
      title={city ? `${city.name_en} — ${city.name_ar}` : ''}
      description="City details & provider aliases"
      size="lg"
    >
      {city && (
        <div className="space-y-6">
          {/* Status */}
          <div className="flex items-center gap-2">
            <Badge variant={city.is_active ? 'default' : 'secondary'}>
              {city.is_active ? 'Active' : 'Inactive'}
            </Badge>
            {city.is_system && (
              <Badge variant="outline" className="text-xs">System Record</Badge>
            )}
            {city.uses_governorate_price && (
              <Badge variant="outline" className="text-xs text-muted-foreground">
                Using governorate price
              </Badge>
            )}
          </div>

          {/* Core fields */}
          <div className="space-y-3">
            <div className="space-y-1.5">
              <Label>Name (English)</Label>
              <Input
                value={nameEn}
                onChange={(e) => { setNameEn(e.target.value); setDirty(true); }}
                disabled={city.is_system}
              />
            </div>
            <div className="space-y-1.5">
              <Label>Name (Arabic)</Label>
              <Input
                value={nameAr}
                onChange={(e) => { setNameAr(e.target.value); setDirty(true); }}
                dir="rtl"
                disabled={city.is_system}
              />
            </div>
            <div className="space-y-1.5">
              <Label>Custom Shipping Price (EGP)</Label>
              <Input
                type="number"
                min={0}
                step={0.5}
                placeholder={`Leave blank to use governorate default (${defaultShippingPrice} EGP)`}
                value={price}
                onChange={(e) => { setPrice(e.target.value); setDirty(true); }}
              />
              <p className="text-xs text-muted-foreground">
                Effective: <strong>{effectivePrice} EGP</strong>
                {price === '' && ' (inherited from governorate)'}
              </p>
            </div>

            {dirty && (
              <Button onClick={handleSave} disabled={update.isPending} className="w-full">
                {update.isPending ? 'Saving…' : 'Save Changes'}
              </Button>
            )}
          </div>

          {/* Aliases */}
          <div className="space-y-3">
            <p className="text-sm font-medium">Provider Aliases</p>
            <p className="text-xs text-muted-foreground">
              Map this city to the name used by each courier provider.
            </p>

            {/* Add alias form */}
            <div className="border rounded-lg p-3 space-y-2 bg-muted/20">
              <div className="flex gap-2">
                <Select
                  value={newProvider}
                  onValueChange={(v) => { setNewProvider(v); setAliasError(null); }}
                >
                  <SelectTrigger className="h-8 w-28 text-xs">
                    <SelectValue placeholder="Provider" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="">Any</SelectItem>
                    {PROVIDERS.map((p) => (
                      <SelectItem key={p} value={p} className="capitalize">{p}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <Input
                  placeholder="Alias name *"
                  value={newAlias}
                  onChange={(e) => { setNewAlias(e.target.value); setAliasError(null); }}
                  className={`h-8 text-sm flex-1 ${aliasError ? 'border-destructive focus-visible:ring-destructive' : ''}`}
                />
                <Input
                  placeholder="Code"
                  value={newCode}
                  onChange={(e) => setNewCode(e.target.value)}
                  className="h-8 text-sm w-20"
                />
                <Button
                  size="sm"
                  onClick={handleAddAlias}
                  disabled={createAlias.isPending || !newAlias.trim()}
                >
                  <Plus className="h-4 w-4" />
                </Button>
              </div>
              {aliasError && (
                <p className="text-xs text-destructive">{aliasError}</p>
              )}
            </div>

            {/* Alias list */}
            {isFetching && aliases.length === 0 ? (
              <p className="text-xs text-muted-foreground">Loading aliases…</p>
            ) : aliases.length === 0 ? (
              <p className="text-xs text-muted-foreground">No aliases configured yet.</p>
            ) : (
              <div className="border rounded-lg divide-y">
                {aliases.map((alias) => (
                  <div key={alias.id} className="flex items-center gap-2 px-3 py-2">
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2">
                        {alias.provider && (
                          <span className="text-xs bg-blue-50 text-blue-700 border border-blue-200 px-1.5 py-0.5 rounded capitalize shrink-0">
                            {alias.provider}
                          </span>
                        )}
                        <span className="text-sm truncate">{alias.alias}</span>
                        {alias.code && (
                          <span className="text-xs font-mono text-muted-foreground shrink-0">
                            ({alias.code})
                          </span>
                        )}
                      </div>
                    </div>
                    <Button
                      size="sm"
                      variant="ghost"
                      className="h-7 w-7 p-0 text-red-500 hover:text-red-600 shrink-0"
                      onClick={() => handleDeleteAlias(alias.id)}
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      )}
    </PageDrawer>
  );
}
