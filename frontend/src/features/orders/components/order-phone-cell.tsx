import { MessageCircle, Phone, Copy, Check } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type OrderPhoneCellProps = {
  phone: string | null;
};

/**
 * DD-013 — Clicking a phone number opens Call / WhatsApp / Copy actions.
 */
export function OrderPhoneCell({ phone }: OrderPhoneCellProps) {
  const { t } = useTranslation('orders');
  const [copied, setCopied] = useState(false);

  if (!phone) {
    return <span className="text-muted-foreground">—</span>;
  }

  const digits = phone.replace(/\D/g, '');

  const handleCopy = () => {
    void navigator.clipboard.writeText(phone).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    });
  };

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          className="font-mono text-xs hover:text-primary transition-colors underline-offset-2 hover:underline"
        >
          {phone}
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-44">
        <DropdownMenuItem asChild>
          <a href={`tel:${digits}`} className="flex items-center gap-2">
            <Phone className="size-3.5" />
            {t('phone.call')}
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
            {t('phone.whatsapp')}
          </a>
        </DropdownMenuItem>
        <DropdownMenuItem onClick={handleCopy}>
          {copied ? (
            <Check className="size-3.5 text-emerald-500" />
          ) : (
            <Copy className="size-3.5" />
          )}
          {copied ? t('phone.copied') : t('phone.copy')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
