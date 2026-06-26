import { Check, ClipboardCopy, ExternalLink, MapPin, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Button } from '@/components/ui/button';
import type { Order, OrderLocation } from '@/features/orders/types/order';

// ── Helpers ───────────────────────────────────────────────────────────────────

function buildAddress(order: Order): string {
  const parts: string[] = [
    [order.shipping_first_name, order.shipping_last_name].filter(Boolean).join(' '),
    order.shipping_address_1,
    order.shipping_address_2,
    order.shipping_city,
    order.shipping_state,
    order.shipping_postcode,
    order.shipping_country,
  ].filter(Boolean) as string[];

  if (parts.length === 0) {
    const billingParts = [
      order.billing_address_1,
      order.billing_address_2,
      order.billing_city,
      order.billing_state,
      order.billing_postcode,
      order.billing_country,
    ].filter(Boolean) as string[];
    return billingParts.join(', ');
  }

  return parts.join(', ');
}

function mapsUrl(location: OrderLocation | null, address: string): string {
  if (location?.lat && location?.lng) {
    return `https://www.google.com/maps?q=${location.lat},${location.lng}`;
  }
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`;
}

function locationLink(location: OrderLocation | null): string | null {
  if (!location?.lat || !location?.lng) return null;
  return `https://www.google.com/maps?q=${location.lat},${location.lng}`;
}

// ── Component ─────────────────────────────────────────────────────────────────

type OrderAddressCellProps = {
  order: Order;
  /** Called when user clicks "Add/Edit Location" */
  onEditLocation?: (order: Order) => void;
  /** Called when user clicks "Delete Location" */
  onDeleteLocation?: (order: Order) => void;
};

/**
 * DD-014 — Full multi-line address, no truncation.
 * DD-015 — 📍 action menu (Google Maps / Add-Edit / Copy Link / Delete) + 📋 copy address.
 */
export function OrderAddressCell({ order, onEditLocation, onDeleteLocation }: OrderAddressCellProps) {
  const { t } = useTranslation('orders');
  const [copiedAddr, setCopiedAddr] = useState(false);
  const [copiedLink, setCopiedLink] = useState(false);

  const address = buildAddress(order);
  const hasAddress = Boolean(address);
  const hasLocation = Boolean(order.location?.lat && order.location?.lng);
  const gmapsUrl = mapsUrl(order.location ?? null, address);
  const locLink = locationLink(order.location ?? null);

  const copyAddress = () => {
    if (!address) return;
    void navigator.clipboard.writeText(address).then(() => {
      setCopiedAddr(true);
      setTimeout(() => setCopiedAddr(false), 1500);
    });
  };

  const copyLink = () => {
    if (!locLink) return;
    void navigator.clipboard.writeText(locLink).then(() => {
      setCopiedLink(true);
      setTimeout(() => setCopiedLink(false), 1500);
    });
  };

  if (!hasAddress) {
    return <span className="text-muted-foreground">—</span>;
  }

  return (
    <div className="flex items-start gap-1.5 min-w-48 max-w-xs">
      {/* Full address — multi-line, no truncation (DD-014) */}
      <span className="flex-1 text-sm leading-snug whitespace-normal break-words">
        {address}
      </span>

      {/* Action buttons — shown always for accessibility */}
      <div className="flex shrink-0 items-center gap-0.5 mt-0.5">
        {/* 📍 — Location actions */}
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="size-6 text-muted-foreground hover:text-foreground"
              aria-label={t('address.locationActions')}
            >
              <MapPin className="size-3" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start" className="w-48">
            <DropdownMenuItem asChild>
              <a
                href={gmapsUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="flex items-center gap-2"
              >
                <ExternalLink className="size-3.5" />
                {t('address.openMaps')}
              </a>
            </DropdownMenuItem>
            <DropdownMenuItem onClick={() => onEditLocation?.(order)}>
              <Pencil className="size-3.5" />
              {hasLocation ? t('address.editLocation') : t('address.addLocation')}
            </DropdownMenuItem>
            {hasLocation ? (
              <DropdownMenuItem onClick={copyLink}>
                {copiedLink ? (
                  <Check className="size-3.5 text-emerald-500" />
                ) : (
                  <ClipboardCopy className="size-3.5" />
                )}
                {copiedLink ? t('address.copied') : t('address.copyLink')}
              </DropdownMenuItem>
            ) : null}
            {hasLocation ? (
              <>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  variant="destructive"
                  onClick={() => onDeleteLocation?.(order)}
                >
                  <Trash2 className="size-3.5" />
                  {t('address.deleteLocation')}
                </DropdownMenuItem>
              </>
            ) : null}
          </DropdownMenuContent>
        </DropdownMenu>

        {/* 📋 — Copy full address */}
        <Button
          variant="ghost"
          size="icon"
          className="size-6 text-muted-foreground hover:text-foreground"
          aria-label={t('address.copyAddress')}
          onClick={copyAddress}
        >
          {copiedAddr ? (
            <Check className="size-3 text-emerald-500" />
          ) : (
            <ClipboardCopy className="size-3" />
          )}
        </Button>
      </div>
    </div>
  );
}
