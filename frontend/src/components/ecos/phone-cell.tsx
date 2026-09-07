import { Check, Copy, MessageCircle, Phone } from 'lucide-react';
import { useState } from 'react';

import { toast } from '@/components/ds/use-toast';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { copyToClipboard } from '@/lib/clipboard';

export type PhoneCellLabels = {
  call?: string;
  whatsapp?: string;
  copy?: string;
  copied?: string;
  /** Toast title shown after a successful Copy. */
  copySuccessTitle?: string;
  /** Toast title shown when Copy genuinely fails (clipboard unavailable/denied). */
  copyErrorTitle?: string;
};

const DEFAULT_LABELS: Required<PhoneCellLabels> = {
  call: 'Call',
  whatsapp: 'WhatsApp',
  copy: 'Copy',
  copied: 'Copied!',
  copySuccessTitle: 'Phone number copied',
  copyErrorTitle: "Couldn't copy phone number",
};

type PhoneCellProps = {
  phone: string | null;
  labels?: PhoneCellLabels;
  /**
   * 'text' (default): the phone number itself is the trigger — for table cells.
   * 'icon': a bare Phone glyph is the trigger, no number text — for tight spaces
   * (e.g. a mobile card's icon-only action row) that already show the number
   * elsewhere. Same Call / WhatsApp / Copy menu either way.
   */
  variant?: 'text' | 'icon';
  /** Accessible name for the icon variant's trigger button. Ignored for 'text'. */
  ariaLabel?: string;
  /** Extra classes for the trigger button. */
  className?: string;
};

/**
 * Reusable phone cell: click to reveal Call / WhatsApp / Copy actions.
 * No i18n dependency — pass `labels` for translated text.
 * Used in: Orders, Customers, Suppliers, any table with a phone column.
 */
export function PhoneCell({ phone, labels, variant = 'text', ariaLabel, className }: PhoneCellProps) {
  const [copied, setCopied] = useState(false);
  const l = { ...DEFAULT_LABELS, ...labels };

  if (!phone) {
    return variant === 'icon' ? null : <span className="text-muted-foreground">—</span>;
  }

  const digits = phone.replace(/\D/g, '');

  const handleCopy = () => {
    // The row/menu's OWN `phone` prop is closed over here — never a shared/
    // module-level value — so concurrently open menus for different rows can
    // never copy each other's number.
    void copyToClipboard(phone).then((ok) => {
      if (ok) {
        setCopied(true);
        toast.success(l.copySuccessTitle);
        setTimeout(() => setCopied(false), 1500);
      } else {
        toast.error(l.copyErrorTitle);
      }
    });
  };

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        {variant === 'icon' ? (
          <button
            type="button"
            onMouseDown={(e) => e.stopPropagation()}
            aria-label={ariaLabel ?? l.call}
            className={
              className ??
              'inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground'
            }
          >
            <Phone className="size-3.5" />
          </button>
        ) : (
          <button
            type="button"
            onMouseDown={(e) => e.stopPropagation()}
            className={className ?? 'font-mono text-xs transition-colors underline-offset-2 hover:text-primary hover:underline'}
          >
            {phone}
          </button>
        )}
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-44">
        <DropdownMenuItem asChild>
          <a href={`tel:${digits}`} className="flex items-center gap-2">
            <Phone className="size-3.5" />
            {l.call}
          </a>
        </DropdownMenuItem>
        <DropdownMenuItem asChild>
          <a
            href={`https://wa.me/${digits}`}
            target="_blank"
            rel="noopener noreferrer"
            className="flex items-center gap-2"
          >
            <MessageCircle className="size-3.5" />
            {l.whatsapp}
          </a>
        </DropdownMenuItem>
        <DropdownMenuItem onClick={handleCopy}>
          {copied ? (
            <Check className="size-3.5 text-emerald-500" />
          ) : (
            <Copy className="size-3.5" />
          )}
          {copied ? l.copied : l.copy}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
