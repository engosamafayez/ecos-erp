import { useEffect, useState } from 'react';
import { CheckCheck, Copy, ExternalLink, MapPin, Navigation, Pencil, Plus, Trash2 } from 'lucide-react';

import { usePatchOrder } from '@/features/orders/hooks/use-orders';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

import type { Order } from '../types/order';

// ── Helpers ───────────────────────────────────────────────────────────────────

function isValidMapsUrl(raw: string): boolean {
  const s = raw.trim();
  if (!s) return false;
  try {
    const url = new URL(s);
    const host = url.hostname.replace(/^www\./, '');
    return (
      host === 'maps.app.goo.gl' ||
      host === 'maps.google.com' ||
      (host === 'google.com' && url.pathname.startsWith('/maps'))
    );
  } catch {
    return false;
  }
}

/**
 * Extract lat/lng from a full Google Maps URL.
 * Returns null for short links (maps.app.goo.gl) — those are resolved server-side.
 */
function extractCoords(raw: string): { lat: number; lng: number } | null {
  const s = raw.trim();

  // /@lat,lng,zoom (most common full-URL pattern)
  const atMatch = s.match(/@(-?\d+\.?\d*),(-?\d+\.?\d*)/);
  if (atMatch) return { lat: parseFloat(atMatch[1]), lng: parseFloat(atMatch[2]) };

  // ?q=lat,lng
  const qMatch = s.match(/[?&]q=(-?\d+\.?\d*)[,+](-?\d+\.?\d*)/);
  if (qMatch) return { lat: parseFloat(qMatch[1]), lng: parseFloat(qMatch[2]) };

  // ?ll=lat,lng
  const llMatch = s.match(/[?&]ll=(-?\d+\.?\d*),(-?\d+\.?\d*)/);
  if (llMatch) return { lat: parseFloat(llMatch[1]), lng: parseFloat(llMatch[2]) };

  return null;
}

// ── Assign / Edit dialog ──────────────────────────────────────────────────────

function AssignLocationDialog({
  open,
  orderId,
  initialLink,
  onClose,
}: {
  open: boolean;
  orderId: string;
  initialLink: string;
  onClose: () => void;
}) {
  const [value, setValue]   = useState(initialLink);
  const [error, setError]   = useState('');
  const { mutate: patchOrder, isPending } = usePatchOrder();

  // Sync input when dialog opens (or initial link changes)
  useEffect(() => {
    if (open) {
      setValue(initialLink);
      setError('');
    }
  }, [open, initialLink]);

  function handleSave() {
    const trimmed = value.trim();
    if (!isValidMapsUrl(trimmed)) {
      setError('Please enter a valid Google Maps link.');
      return;
    }
    setError('');

    const coords = extractCoords(trimmed);
    const payload: Record<string, unknown> = { google_maps_url: trimmed };
    if (coords) {
      payload.google_maps_lat = coords.lat;
      payload.google_maps_lng = coords.lng;
    }

    patchOrder({ id: orderId, data: payload }, { onSettled: onClose });
  }

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <DialogTitle>Assign Delivery Location</DialogTitle>
        </DialogHeader>

        <div className="space-y-1.5">
          <label className="text-xs font-medium text-foreground" htmlFor="maps-link-input">
            Google Maps Link
          </label>
          <input
            id="maps-link-input"
            autoFocus
            value={value}
            onChange={(e) => { setValue(e.target.value); setError(''); }}
            onKeyDown={(e) => {
              if (e.key === 'Enter') handleSave();
              if (e.key === 'Escape') onClose();
            }}
            placeholder="https://maps.app.goo.gl/…"
            className={cn(
              'h-9 w-full rounded-md border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring',
              error ? 'border-destructive' : 'border-input',
            )}
            disabled={isPending}
          />
          {error ? (
            <p className="text-xs text-destructive">{error}</p>
          ) : (
            <p className="text-xs text-muted-foreground">Paste a Google Maps link (short or full).</p>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" size="sm" onClick={onClose} disabled={isPending}>
            Cancel
          </Button>
          <Button size="sm" onClick={handleSave} disabled={isPending || !value.trim()}>
            {isPending ? 'Saving…' : 'Save'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── Main component ────────────────────────────────────────────────────────────

type Props = {
  order: Order;
  onEdit?: (order: Order) => void;
  onDelete?: (order: Order) => void;
};

export function OrderLocationCell({ order, onEdit: _onEdit, onDelete }: Props) {
  const loc    = order.location;
  const hasGps = Boolean(loc?.lat && loc?.lng);
  const [copied, setCopied]       = useState(false);
  const [dialogOpen, setDialogOpen] = useState(false);
  const { mutate: patchOrder } = usePatchOrder();

  // Pre-fill: prefer stored google_maps_url, fall back to generating one from coords
  const initialLink = order.google_maps_url
    ?? (hasGps ? `https://www.google.com/maps?q=${loc!.lat},${loc!.lng}` : '');

  function openDialog() { setDialogOpen(true); }
  function closeDialog() { setDialogOpen(false); }

  if (!hasGps) {
    return (
      <>
        <div className="relative flex items-center gap-1">
          <MapPin className="size-3 text-muted-foreground/40" />
          <span className="text-xs text-muted-foreground">No GPS</span>
          <Button
            variant="ghost"
            size="icon"
            className="size-5 text-muted-foreground hover:text-foreground opacity-0 group-hover:opacity-100 transition-opacity"
            onClick={openDialog}
            aria-label="Assign location"
          >
            <Plus className="size-2.5" />
          </Button>
        </div>

        <AssignLocationDialog
          open={dialogOpen}
          orderId={order.id}
          initialLink={initialLink}
          onClose={closeDialog}
        />
      </>
    );
  }

  const lat    = loc!.lat;
  const lng    = loc!.lng;
  const latStr = lat.toFixed(5);
  const lngStr = lng.toFixed(5);
  const mapsUrl = `https://www.google.com/maps?q=${lat},${lng}`;
  const wazeUrl = `https://www.waze.com/ul?ll=${lat}%2C${lng}&navigate=yes`;
  const setBy   = loc!.set_by ?? null;

  function copyCoords() {
    void navigator.clipboard.writeText(`${latStr}, ${lngStr}`);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  }

  function copyMapsUrl() {
    void navigator.clipboard.writeText(order.google_maps_url ?? mapsUrl);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  }

  function deleteLocation() {
    patchOrder(
      { id: order.id, data: { google_maps_lat: null, google_maps_lng: null, google_maps_url: null } },
      { onSuccess: () => onDelete?.(order) },
    );
  }

  return (
    <>
      <TooltipProvider delayDuration={400}>
        <div className="flex items-center gap-1">
          <Tooltip>
            <TooltipTrigger asChild>
              <a
                href={order.google_maps_url ?? mapsUrl}
                target="_blank"
                rel="noopener noreferrer"
                className={cn(
                  'inline-flex items-center gap-0.5 font-mono text-[10px] tabular-nums hover:underline',
                  setBy === 'employee'
                    ? 'text-blue-600 dark:text-blue-400'
                    : 'text-emerald-600 dark:text-emerald-400',
                )}
              >
                <MapPin className="size-2.5 flex-shrink-0" />
                {latStr}, {lngStr}
              </a>
            </TooltipTrigger>
            <TooltipContent side="bottom" className="text-xs space-y-0.5">
              <p className="font-medium">GPS Location</p>
              <p className="font-mono text-muted-foreground">{latStr}, {lngStr}</p>
              {loc!.label ? <p>{loc!.label}</p> : null}
              {setBy ? (
                <p className="text-muted-foreground capitalize">
                  Set by: <span className={setBy === 'employee' ? 'text-blue-400' : 'text-emerald-400'}>{setBy}</span>
                </p>
              ) : null}
            </TooltipContent>
          </Tooltip>

          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                className="size-5 text-muted-foreground hover:text-foreground opacity-0 group-hover:opacity-100 transition-opacity"
                aria-label="Location actions"
              >
                <Pencil className="size-2.5" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-52">
              <DropdownMenuItem asChild>
                <a href={order.google_maps_url ?? mapsUrl} target="_blank" rel="noopener noreferrer">
                  <ExternalLink className="size-3.5" />
                  Open in Google Maps
                </a>
              </DropdownMenuItem>
              <DropdownMenuItem asChild>
                <a href={wazeUrl} target="_blank" rel="noopener noreferrer">
                  <Navigation className="size-3.5" />
                  Open in Waze
                </a>
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={copyCoords}>
                {copied ? <CheckCheck className="size-3.5 text-emerald-500" /> : <Copy className="size-3.5" />}
                Copy Coordinates
              </DropdownMenuItem>
              <DropdownMenuItem onClick={copyMapsUrl}>
                <Copy className="size-3.5" />
                Copy Maps URL
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={openDialog}>
                <Pencil className="size-3.5" />
                Replace Location
              </DropdownMenuItem>
              {onDelete ? (
                <>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem variant="destructive" onClick={deleteLocation}>
                    <Trash2 className="size-3.5" />
                    Delete Location
                  </DropdownMenuItem>
                </>
              ) : null}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </TooltipProvider>

      <AssignLocationDialog
        open={dialogOpen}
        orderId={order.id}
        initialLink={initialLink}
        onClose={closeDialog}
      />
    </>
  );
}
