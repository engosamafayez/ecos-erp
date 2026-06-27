import {
  Copy,
  ExternalLink,
  FileText,
  Map,
  MessageCircle,
  Phone,
  Plus,
  ShoppingBag,
  X,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { PhoneCell } from '@/components/ecos/phone-cell';
import { StatusBadge } from '@/components/crud/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import type { Customer } from '@/features/customers/types/customer';
import { cn } from '@/lib/utils';

type CustomerQuickCardProps = {
  customer: Customer;
  onOpen: (customer: Customer) => void;
  onCreateOrder?: (customer: Customer) => void;
  onClose?: () => void;
  className?: string;
};

/**
 * DD-056/057 — Customer Quick Action Card.
 * Shown when a search returns exactly one customer.
 * Operational action hub — not an information popup.
 * Every common CS action is reachable from here without opening the full profile.
 */
export function CustomerQuickCard({
  customer,
  onOpen,
  onCreateOrder,
  onClose,
  className,
}: CustomerQuickCardProps) {
  const { t } = useTranslation('customers');

  const primaryPhone = customer.phone ?? customer.mobile;
  const address = [customer.city, customer.country].filter(Boolean).join(', ');

  const handleCopyPhone = () => {
    if (primaryPhone) {
      void navigator.clipboard.writeText(primaryPhone);
    }
  };

  const handleCopyAddress = () => {
    const fullAddress = [customer.address, address].filter(Boolean).join(' — ');
    if (fullAddress) void navigator.clipboard.writeText(fullAddress);
  };

  return (
    <div
      className={cn(
        'relative rounded-xl border bg-background shadow-md',
        'animate-in fade-in slide-in-from-top-1 duration-150',
        className,
      )}
    >
      {/* Close button */}
      {onClose ? (
        <button
          type="button"
          onClick={onClose}
          className="absolute right-3 top-3 rounded-md p-0.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
          aria-label="Close"
        >
          <X className="size-4" />
        </button>
      ) : null}

      <div className="p-4 pb-3">
        {/* Customer identity */}
        <div className="flex items-start gap-3 pr-6">
          {/* Avatar */}
          <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
            {customer.name.slice(0, 2).toUpperCase()}
          </div>

          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2">
              <span className="truncate text-sm font-semibold">{customer.name}</span>
              {!customer.is_active ? (
                <Badge variant="secondary" className="shrink-0 text-[10px]">
                  {t('tags.inactive')}
                </Badge>
              ) : null}
            </div>
            <p className="text-xs text-muted-foreground">{customer.code}</p>
          </div>
        </div>

        {/* Primary Phone */}
        {primaryPhone ? (
          <div className="mt-3 flex items-center gap-2">
            <Phone className="size-3.5 shrink-0 text-muted-foreground" />
            <PhoneCell
              phone={primaryPhone}
              labels={{
                call: t('phone.call'),
                whatsapp: t('phone.whatsapp'),
                copy: t('phone.copy'),
                copied: t('phone.copied'),
              }}
            />
            {customer.mobile && customer.mobile !== primaryPhone ? (
              <span className="text-xs text-muted-foreground">
                {t('phone.more', { count: 1 })}
              </span>
            ) : null}
          </div>
        ) : null}

        {/* Address */}
        {(customer.address ?? address) ? (
          <div className="mt-1.5 flex items-start gap-2">
            <Map className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
            <div className="min-w-0 flex-1">
              {customer.address ? (
                <p className="truncate text-xs">{customer.address}</p>
              ) : null}
              {address ? (
                <p className="text-xs text-muted-foreground">{address}</p>
              ) : null}
            </div>
          </div>
        ) : null}

        {/* Notes preview (customer memory) */}
        {customer.notes ? (
          <div className="mt-1.5 flex items-start gap-2">
            <FileText className="mt-0.5 size-3.5 shrink-0 text-amber-500" />
            <p className="line-clamp-2 text-xs text-muted-foreground">{customer.notes}</p>
          </div>
        ) : null}
      </div>

      <Separator />

      {/* Quick actions */}
      <div className="flex flex-wrap gap-1.5 p-3">
        {/* Primary action */}
        <Button
          size="sm"
          className="h-7 gap-1.5 px-3 text-xs"
          onClick={() => onOpen(customer)}
        >
          <ExternalLink className="size-3" />
          {t('quickCard.openCustomer')}
        </Button>

        {onCreateOrder ? (
          <Button
            size="sm"
            variant="outline"
            className="h-7 gap-1.5 px-3 text-xs"
            onClick={() => onCreateOrder(customer)}
          >
            <Plus className="size-3" />
            {t('quickCard.createOrder')}
          </Button>
        ) : null}

        {/* Communication */}
        {primaryPhone ? (
          <>
            <Button
              size="sm"
              variant="ghost"
              className="h-7 gap-1.5 px-2.5 text-xs"
              onClick={() => window.open(`tel:${primaryPhone.replace(/\D/g, '')}`, '_self')}
            >
              <Phone className="size-3" />
              {t('quickCard.call')}
            </Button>
            <Button
              size="sm"
              variant="ghost"
              className="h-7 gap-1.5 px-2.5 text-xs"
              asChild
            >
              <a
                href={`https://wa.me/${primaryPhone.replace(/\D/g, '')}`}
                target="_blank"
                rel="noopener noreferrer"
              >
                <MessageCircle className="size-3" />
                {t('quickCard.whatsapp')}
              </a>
            </Button>
            <Button
              size="sm"
              variant="ghost"
              className="h-7 gap-1.5 px-2.5 text-xs"
              onClick={handleCopyPhone}
            >
              <Copy className="size-3" />
              {t('quickCard.copyPhone')}
            </Button>
          </>
        ) : null}

        {/* Address actions */}
        {(customer.address ?? address) ? (
          <Button
            size="sm"
            variant="ghost"
            className="h-7 gap-1.5 px-2.5 text-xs"
            onClick={handleCopyAddress}
          >
            <Copy className="size-3" />
            {t('quickCard.copyAddress')}
          </Button>
        ) : null}
      </div>

      {/* Status footer */}
      <div className="border-t px-4 py-2">
        <StatusBadge status={customer.is_active ? 'active' : 'inactive'} />
      </div>
    </div>
  );
}
