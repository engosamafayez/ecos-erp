import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import type { StatusVariant } from '@/components/crud/types';
import type enCommon from '@/i18n/locales/en/common.json';
import { cn } from '@/lib/utils';

type StatusConfig = {
  /**
   * The label as an i18next selector into the `common` namespace.
   *
   * Held as a selector rather than a key string: selector mode has no type for
   * a key chosen at runtime, so `t('status.active')` stored in a constant
   * cannot type-check. The selector is the same expression the compiler
   * validates at an inline call site — it just lives in the table.
   */
  label: ($: typeof enCommon) => string;
  dot: string;
  variant: 'secondary' | 'outline';
  muted?: boolean;
};

const STATUS_CONFIG: Record<StatusVariant, StatusConfig> = {
  active: { label: ($) => $.status.active, dot: 'bg-emerald-500', variant: 'secondary' },
  pending: { label: ($) => $.status.pending, dot: 'bg-amber-500', variant: 'secondary' },
  inactive: {
    label: ($) => $.status.inactive,
    dot: 'bg-muted-foreground',
    variant: 'outline',
    muted: true,
  },
  archived: {
    label: ($) => $.status.archived,
    dot: 'bg-muted-foreground',
    variant: 'outline',
    muted: true,
  },
};

/**
 * The 5 semantic status roles docs/ux/ENTERPRISE-DESIGN-LANGUAGE.md specifies
 * for arbitrary domain statuses (order.delivered, wave.blocked,
 * cost.pending_review, …) — see index.css's `--success`/`--warning`/`--error`/
 * `--info`/`--neutral` tokens (TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045).
 */
export type StatusTone = 'success' | 'warning' | 'error' | 'info' | 'neutral';

const TONE_CLASSES: Record<StatusTone, string> = {
  success: 'bg-success text-success-foreground border-success-border',
  warning: 'bg-warning text-warning-foreground border-warning-border',
  error: 'bg-error text-error-foreground border-error-border',
  info: 'bg-info text-info-foreground border-info-border',
  neutral: 'bg-neutral text-neutral-foreground border-neutral-border',
};

const TONE_DOT_CLASSES: Record<StatusTone, string> = {
  success: 'bg-success-foreground',
  warning: 'bg-warning-foreground',
  error: 'bg-error-foreground',
  info: 'bg-info-foreground',
  neutral: 'bg-neutral-foreground',
};

type StatusBadgeProps =
  | {
      /** One of the 4 built-in lifecycle states with a translated default label. */
      status: StatusVariant;
      label?: string;
      className?: string;
      tone?: undefined;
    }
  | {
      /**
       * Escape hatch for domain-specific statuses that don't fit the 4
       * built-in `status` values above — there is no translation table for
       * arbitrary domain strings, so the caller supplies its own already-
       * translated `label`.
       */
      tone: StatusTone;
      label: string;
      className?: string;
      status?: undefined;
    };

/**
 * Reusable status badge. `status` covers the 4 built-in lifecycle states
 * (active / inactive / pending / archived); `tone` is the general-purpose
 * escape hatch for any other domain status, mapped to one of the 5 semantic
 * roles (success / warning / error / info / neutral) instead of a hand-rolled
 * color per call site.
 */
export function StatusBadge(props: StatusBadgeProps) {
  const { t } = useTranslation('common');

  if (props.tone) {
    const { tone, label, className } = props;
    return (
      <Badge variant="outline" className={cn('gap-1.5', TONE_CLASSES[tone], className)}>
        <span className={cn('size-1.5 rounded-full', TONE_DOT_CLASSES[tone])} />
        {label}
      </Badge>
    );
  }

  const { status, label, className } = props;
  const config = STATUS_CONFIG[status];

  return (
    <Badge
      variant={config.variant}
      className={cn('gap-1.5', config.muted && 'text-muted-foreground', className)}
    >
      <span className={cn('size-1.5 rounded-full', config.dot)} />
      {label ?? t(config.label)}
    </Badge>
  );
}
